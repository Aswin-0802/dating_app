<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\GatewayEvent;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\PaymentLog;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Services\Billing\Subscriptions;
use App\Services\Payments\Checkout;
use App\Services\Payments\WebhookEvent;
use App\Support\Currency;
use Database\Seeders\MasterSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionSeeder::class, RoleSeeder::class, SettingSeeder::class,
            SystemSeeder::class, MasterSeeder::class, NotificationTemplateSeeder::class,
        ]);

        Notification::fake();
    }

    private function enableStripe(): PaymentGateway
    {
        $gateway = PaymentGateway::query()->where('slug', 'stripe')->firstOrFail();

        $gateway->forceFill([
            'credentials' => [
                'publishable_key' => 'pk_test_123',
                'secret_key' => 'sk_test_123',
                'webhook_secret' => 'whsec_test',
            ],
            'is_active' => true,
            'is_test_mode' => true,
        ])->save();

        return $gateway;
    }

    private function enableRazorpay(): PaymentGateway
    {
        Setting::put('billing.currency', 'INR');
        Setting::flush();

        $gateway = PaymentGateway::query()->where('slug', 'razorpay')->firstOrFail();

        $gateway->forceFill([
            'credentials' => [
                'key_id' => 'rzp_test_123',
                'key_secret' => 'secret_123',
                'webhook_secret' => 'hook_123',
            ],
            'is_active' => true,
            'is_test_mode' => true,
        ])->save();

        return $gateway;
    }

    private function member(): AppUser
    {
        return AppUser::factory()->create(['is_premium' => false]);
    }

    // ---- availability -------------------------------------------------------------

    public function test_no_gateway_means_no_buy_buttons(): void
    {
        $this->actingAs($this->member(), 'member')
            ->get(route('member.premium'))
            ->assertOk()
            ->assertDontSee('Pay with')
            ->assertSee('How to upgrade');
    }

    public function test_a_gateway_that_cannot_take_the_currency_is_not_offered(): void
    {
        $this->enableRazorpay();
        Setting::put('billing.currency', 'USD');
        Setting::flush();

        // Razorpay settles in INR only, so with USD prices it is not offered.
        $this->assertTrue(app(Checkout::class)->availableGateways()->isEmpty());
    }

    public function test_a_gateway_without_keys_is_not_offered(): void
    {
        PaymentGateway::query()->where('slug', 'stripe')->update(['is_active' => true]);

        $this->assertTrue(app(Checkout::class)->availableGateways()->isEmpty());
    }

    // ---- starting a payment --------------------------------------------------------

    public function test_a_member_is_sent_to_stripe_with_the_right_amount(): void
    {
        $this->enableStripe();

        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/pay/cs_test_123',
        ])]);

        $member = $this->member();

        $this->actingAs($member, 'member')
            ->post(route('member.checkout.start'), ['plan' => 'plus', 'period' => 'monthly'])
            ->assertRedirect('https://checkout.stripe.com/pay/cs_test_123');

        $order = Order::query()->firstOrFail();
        $this->assertSame(1299, $order->amount_minor, '12.99 must be sent as 1299 cents.');
        $this->assertSame('pending', $order->status);
        $this->assertSame('cs_test_123', $order->gateway_ref);

        Http::assertSent(fn ($request): bool => $request['line_items'][0]['price_data']['unit_amount'] === 1299
            && $request['client_reference_id'] === $order->uuid);
    }

    public function test_a_member_is_sent_to_razorpay_in_paise(): void
    {
        $this->enableRazorpay();

        Http::fake(['api.razorpay.com/v1/payment_links' => Http::response([
            'id' => 'plink_123',
            'short_url' => 'https://rzp.io/i/abc',
            'status' => 'created',
        ])]);

        $this->actingAs($this->member(), 'member')
            ->post(route('member.checkout.start'), ['plan' => 'gold', 'period' => 'yearly'])
            ->assertRedirect('https://rzp.io/i/abc');

        $order = Order::query()->firstOrFail();
        $this->assertSame(19999, $order->amount_minor);
        $this->assertSame('INR', $order->currency);
        $this->assertSame('yearly', $order->billing_period);
    }

    public function test_a_gateway_outage_does_not_take_money_or_crash(): void
    {
        $this->enableStripe();
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);

        $this->actingAs($this->member(), 'member')
            ->post(route('member.checkout.start'), ['plan' => 'plus', 'period' => 'monthly'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('failed', Order::query()->firstOrFail()->status);
    }

    // ---- fulfilment ------------------------------------------------------------------

    public function test_the_plan_is_only_unlocked_once_the_gateway_confirms(): void
    {
        $this->enableStripe();
        $member = $this->member();

        $order = Order::query()->create([
            'app_user_id' => $member->id,
            'purpose' => 'plan',
            'reference' => 'plus',
            'description' => 'Plus · 1 month',
            'amount_minor' => 1299,
            'currency' => 'USD',
            'billing_period' => 'monthly',
            'gateway' => 'stripe',
            'gateway_ref' => 'cs_test_123',
            'status' => 'pending',
        ]);

        // One stub whose answer changes, because Stripe's answer changes: the
        // member can land on the return page before the payment settles.
        $paid = false;

        Http::fake(function () use (&$paid) {
            return Http::response($paid
                ? ['id' => 'cs_test_123', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_123']
                : ['id' => 'cs_test_123', 'status' => 'open', 'payment_status' => 'unpaid']);
        });

        $this->actingAs($member, 'member')
            ->get(route('member.checkout.return', ['order' => $order->uuid, 'session_id' => 'cs_test_123']))
            ->assertRedirect(route('member.checkout.show', $order));

        $this->assertFalse($member->fresh()->is_premium);

        $paid = true;

        $this->actingAs($member, 'member')
            ->get(route('member.checkout.return', ['order' => $order->uuid, 'session_id' => 'cs_test_123']))
            ->assertRedirect(route('member.checkout.show', $order));

        $member->refresh();
        $this->assertTrue($member->is_premium);
        $this->assertSame('plus', $member->premium_tier);
        $this->assertTrue($member->premium_until->isBetween(now()->addMonth()->subDay(), now()->addMonth()->addDay()));

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame('pi_123', $order->payment_ref);
        $this->assertDatabaseHas('payment_logs', ['gateway' => 'stripe', 'status' => 'succeeded']);
        $this->assertDatabaseHas('subscriptions', ['app_user_id' => $member->id, 'source' => 'payment', 'status' => 'active']);

        // Logged with no staff actor: a member paid, nobody on the team acted.
        $this->assertDatabaseHas('activity_logs', ['module' => 'billing', 'action' => 'payment_received', 'user_id' => null]);
    }

    public function test_a_repeated_confirmation_does_not_give_two_subscriptions(): void
    {
        $this->enableStripe();
        $member = $this->member();
        $checkout = app(Checkout::class);

        $order = Order::query()->create([
            'app_user_id' => $member->id, 'purpose' => 'plan', 'reference' => 'plus',
            'description' => 'Plus · 1 month', 'amount_minor' => 1299, 'currency' => 'USD',
            'billing_period' => 'monthly', 'gateway' => 'stripe', 'gateway_ref' => 'cs_dup', 'status' => 'pending',
        ]);

        // The return page, then the webhook, then a webhook retry.
        $checkout->fulfil($order, 'pi_1');
        $checkout->fulfil($order->fresh(), 'pi_1');
        $checkout->fulfil($order->fresh(), 'pi_1');

        $this->assertSame(1, Subscription::query()->where('app_user_id', $member->id)->count());
        $this->assertSame(1, PaymentLog::query()->count());
    }

    public function test_renewing_early_adds_to_the_time_already_paid_for(): void
    {
        $this->enableStripe();
        $member = $this->member();

        app(Subscriptions::class)->grant(
            $member,
            Plan::query()->where('slug', 'plus')->firstOrFail(),
            now()->addDays(10),
        );

        $order = Order::query()->create([
            'app_user_id' => $member->id, 'purpose' => 'plan', 'reference' => 'plus',
            'description' => 'Plus · 1 month', 'amount_minor' => 1299, 'currency' => 'USD',
            'billing_period' => 'monthly', 'gateway' => 'stripe', 'gateway_ref' => 'cs_renew', 'status' => 'pending',
        ]);

        app(Checkout::class)->fulfil($order, 'pi_2');

        // Ten days left plus a month, not a month from today.
        $this->assertTrue($member->fresh()->premium_until->isAfter(now()->addMonth()->addDays(9)));
    }

    // ---- webhooks ---------------------------------------------------------------------

    /**
     * A retry after a failed fulfilment must do the work, not skip it.
     *
     * The dedup row is written before fulfilment and outside the transaction
     * that fulfilment rolls back. Treating the row itself as proof of handling
     * meant one transient failure stranded a paid order for good.
     *
     * The failure is induced rather than mocked — both classes are final — by
     * throwing from a query listener once the subscription insert has run, so
     * the transaction really does roll back completed work.
     */
    public function test_a_paid_order_is_still_fulfilled_when_the_first_attempt_fails(): void
    {
        $this->enableStripe();
        $member = $this->member();

        $order = Order::query()->create([
            'app_user_id' => $member->id, 'purpose' => 'plan', 'reference' => 'gold',
            'description' => 'Gold · 1 month', 'amount_minor' => 2499, 'currency' => 'USD',
            'billing_period' => 'monthly', 'gateway' => 'stripe', 'gateway_ref' => 'cs_retry', 'status' => 'pending',
        ]);

        $event = new WebhookEvent(
            id: 'evt_retry_1',
            type: 'checkout.session.completed',
            paid: true,
            failed: false,
            gatewayRef: 'cs_retry',
            paymentRef: 'pi_retry',
            payload: ['id' => 'evt_retry_1'],
        );

        $failed = false;

        DB::listen(function ($query) use (&$failed): void {
            if (! $failed && str_contains($query->sql, 'insert into `subscriptions`')) {
                $failed = true;

                throw new RuntimeException('Simulated failure part-way through fulfilment.');
            }
        });

        try {
            app(Checkout::class)->handleWebhook('stripe', $event);
            $this->fail('EXPECTED the first attempt to fail.');
        } catch (RuntimeException) {
            // The gateway sees a 500 and will retry, which is the whole point.
        }

        $this->assertTrue($failed, 'EXPECTED the induced failure to have fired.');

        // Nothing was granted, and the order is still owed to the member.
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, Subscription::query()->where('app_user_id', $member->id)->count());

        /*
         * And nobody was told about it. Subscriptions::grant() sends after its
         * own transaction, but checkout calls it from inside fulfil()'s — so
         * "after the transaction" was still inside one, and the member got a
         * "your plan has started" email for a plan the rollback removed.
         */
        $this->assertDatabaseMissing('email_logs', [
            'recipient_id' => $member->id,
            'template_key' => 'billing.plan_started',
        ]);

        // The event was recorded, but not as handled.
        $this->assertDatabaseCount('gateway_events', 1);
        $this->assertNull(GatewayEvent::query()->firstOrFail()->processed_at);

        // The retry: same event id, and this time it must go through.
        $this->assertTrue(app(Checkout::class)->handleWebhook('stripe', $event));

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertTrue($member->fresh()->is_premium);
        $this->assertSame(1, Subscription::query()->where('app_user_id', $member->id)->count());
        $this->assertNotNull(GatewayEvent::query()->firstOrFail()->processed_at);

        // Now that the plan is real, the member is told. (This class fakes
        // notifications, so only the attempt is visible here; the delivery
        // outcome is covered in BillingTest.)
        $this->assertDatabaseHas('email_logs', [
            'recipient_id' => $member->id,
            'template_key' => 'billing.plan_started',
        ]);

        // And a third delivery of a now-handled event still changes nothing.
        $this->assertFalse(app(Checkout::class)->handleWebhook('stripe', $event));
        $this->assertSame(1, Subscription::query()->where('app_user_id', $member->id)->count());
    }

    public function test_a_stripe_webhook_with_a_bad_signature_is_refused(): void
    {
        $this->enableStripe();

        $this->postJson(route('webhooks.payments', 'stripe'), ['id' => 'evt_1', 'type' => 'checkout.session.completed'], [
            'Stripe-Signature' => 't='.time().',v1=deadbeef',
        ])->assertStatus(400);
    }

    public function test_a_signed_stripe_webhook_unlocks_the_plan_and_repeats_are_ignored(): void
    {
        $this->enableStripe();
        $member = $this->member();

        Order::query()->create([
            'app_user_id' => $member->id, 'purpose' => 'plan', 'reference' => 'gold',
            'description' => 'Gold · 1 month', 'amount_minor' => 2499, 'currency' => 'USD',
            'billing_period' => 'monthly', 'gateway' => 'stripe', 'gateway_ref' => 'cs_hook', 'status' => 'pending',
        ]);

        $payload = json_encode([
            'id' => 'evt_hook_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_hook', 'payment_status' => 'paid', 'payment_intent' => 'pi_hook']],
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        for ($i = 0; $i < 2; $i++) {
            $this->call(
                'POST',
                route('webhooks.payments', 'stripe'),
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
                $payload,
            )->assertOk();
        }

        $this->assertTrue($member->fresh()->is_premium);
        $this->assertSame(1, Subscription::query()->where('app_user_id', $member->id)->count());
        $this->assertDatabaseCount('gateway_events', 1);
    }

    public function test_an_old_stripe_webhook_is_refused(): void
    {
        $this->enableStripe();

        $payload = json_encode(['id' => 'evt_old', 'type' => 'checkout.session.completed', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        $timestamp = time() - 3600;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $this->call('POST', route('webhooks.payments', 'stripe'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload)->assertStatus(400);
    }

    public function test_a_signed_razorpay_webhook_unlocks_the_plan(): void
    {
        $this->enableRazorpay();
        $member = $this->member();

        Order::query()->create([
            'app_user_id' => $member->id, 'purpose' => 'plan', 'reference' => 'plus',
            'description' => 'Plus · 1 month', 'amount_minor' => 129900, 'currency' => 'INR',
            'billing_period' => 'monthly', 'gateway' => 'razorpay', 'gateway_ref' => 'plink_x', 'status' => 'pending',
        ]);

        $payload = json_encode([
            'event' => 'payment_link.paid',
            'created_at' => time(),
            'payload' => [
                'payment_link' => ['entity' => ['id' => 'plink_x', 'status' => 'paid']],
                'payment' => ['entity' => ['id' => 'pay_1', 'status' => 'captured']],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', route('webhooks.payments', 'razorpay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $payload, 'hook_123'),
        ], $payload)->assertOk();

        $this->assertTrue($member->fresh()->is_premium);
        $this->assertDatabaseHas('orders', ['gateway_ref' => 'plink_x', 'status' => 'paid', 'payment_ref' => 'pay_1']);
    }

    public function test_one_member_cannot_see_anothers_order(): void
    {
        $this->enableStripe();

        $order = Order::query()->create([
            'app_user_id' => $this->member()->id, 'purpose' => 'plan', 'reference' => 'plus',
            'description' => 'Plus · 1 month', 'amount_minor' => 1299, 'currency' => 'USD',
            'billing_period' => 'monthly', 'gateway' => 'stripe', 'gateway_ref' => 'cs_private', 'status' => 'pending',
        ]);

        $this->actingAs($this->member(), 'member')
            ->get(route('member.checkout.show', $order))
            ->assertForbidden();
    }

    public function test_amounts_convert_to_the_smallest_unit_correctly(): void
    {
        $this->assertSame(1299, Currency::toMinor(12.99, 'USD'));
        $this->assertSame(129900, Currency::toMinor(1299, 'INR'));
        $this->assertSame(500, Currency::toMinor(500, 'JPY'), 'Yen has no minor unit.');
        $this->assertSame(12.99, Currency::fromMinor(1299, 'USD'));
    }
}
