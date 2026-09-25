<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Livewire\Billing\Subscriptions as SubscriptionsScreen;
use App\Models\AppUser;
use App\Models\GatewayEvent;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\Preference;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Subscriptions;
use App\Services\Members\AccountDeletion;
use App\Services\Payments\Checkout;
use App\Services\Store\StoreDrivers;
use Database\Seeders\MasterSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\FakeStoreDriver;
use Tests\TestCase;

/**
 * In-app purchases: the receipt endpoint, the store notifications, and the
 * rules in dating_app_mobile/docs/premium-receipt-contract.md.
 */
class StoreBillingTest extends TestCase
{
    use RefreshDatabase;

    private FakeStoreDriver $apple;

    private FakeStoreDriver $google;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionSeeder::class, RoleSeeder::class, SettingSeeder::class,
            SystemSeeder::class, MasterSeeder::class, NotificationTemplateSeeder::class,
        ]);

        Notification::fake();

        foreach (['apple', 'google'] as $store) {
            PaymentGateway::query()->where('slug', $store)->update(['is_active' => true, 'is_test_mode' => false]);
        }

        Plan::query()->where('slug', 'plus')->update([
            'apple_product_id_monthly' => 'plus_monthly', 'apple_product_id_yearly' => 'plus_yearly',
            'google_product_id_monthly' => 'plus_monthly', 'google_product_id_yearly' => 'plus_yearly',
        ]);
        Plan::query()->where('slug', 'gold')->update(['apple_product_id_monthly' => 'gold_monthly', 'google_product_id_monthly' => 'gold_monthly']);

        StoreDrivers::swap('apple', $this->apple = new FakeStoreDriver('apple'));
        StoreDrivers::swap('google', $this->google = new FakeStoreDriver('google'));
    }

    protected function tearDown(): void
    {
        StoreDrivers::reset();

        parent::tearDown();
    }

    private function member(array $attributes = []): AppUser
    {
        $member = AppUser::factory()->create($attributes + ['account_status' => AccountStatus::Active, 'is_premium' => false, 'premium_tier' => null, 'premium_until' => null]);

        Profile::query()->create(['app_user_id' => $member->id]);
        Preference::query()->create(['app_user_id' => $member->id, 'interested_in' => ['woman', 'man'], 'age_min' => 18, 'age_max' => 99]);

        return $member->fresh();
    }

    private function using(AppUser $member): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($member, ['profile:read', 'profile:write']);
    }

    private function redeem(AppUser $member, string $token, string $platform = 'ios', string $productId = 'plus_monthly')
    {
        $this->using($member);

        return $this->postJson('/api/v1/me/premium/receipt', ['platform' => $platform, 'product_id' => $productId, 'transaction' => $token]);
    }

    private function notify(string $store, array $body, string $bearer = 'valid')
    {
        return $this->withHeader('Authorization', "Bearer {$bearer}")->postJson("/webhooks/store/{$store}", $body);
    }

    // ---- the receipt endpoint -------------------------------------------------------

    public function test_a_verified_store_purchase_puts_the_member_on_the_plan(): void
    {
        $member = $this->member();
        $transaction = $this->apple->transaction('jws-1');

        $response = $this->redeem($member, 'jws-1')->assertOk();

        $response->assertJsonPath('data.is_premium', true)
            ->assertJsonPath('data.premium_tier', 'plus')
            ->assertJsonPath('data.premium_source', 'apple')
            ->assertJsonPath('data.auto_renewing', true);

        $order = Order::query()->where('gateway', 'apple')->where('payment_ref', 'txn-1')->firstOrFail();
        $this->assertSame('paid', $order->status);
        $this->assertSame('production', $order->environment);
        $this->assertSame($member->id, $order->app_user_id);
        $this->assertSame('orig-1', $order->gateway_ref);
        $this->assertSame(1299, $order->amount_minor);

        $subscription = Subscription::query()->where('app_user_id', $member->id)->active()->firstOrFail();
        $this->assertSame('apple', $subscription->source);
        $this->assertSame('orig-1', $subscription->external_ref);
        $this->assertSame('monthly', $subscription->billing_period);
        $this->assertTrue($subscription->ends_at->equalTo($transaction->expiresAt->startOfSecond()) || $subscription->ends_at->diffInSeconds($transaction->expiresAt) < 2);

        // The store's calendar, not ours.
        $this->assertTrue($member->fresh()->premium_until->diffInSeconds($transaction->expiresAt) < 2);

        $this->assertContains('txn-1', $this->apple->acknowledged);
        $this->assertDatabaseHas('activity_logs', ['module' => 'billing', 'action' => 'plan_purchased']);
    }

    public function test_posting_the_same_receipt_again_changes_nothing(): void
    {
        $member = $this->member();
        $this->apple->transaction('jws-1');

        $this->redeem($member, 'jws-1')->assertOk();
        $this->redeem($member, 'jws-1')->assertOk()->assertJsonPath('data.is_premium', true);

        $this->assertSame(1, Order::query()->where('gateway', 'apple')->count());
        $this->assertSame(1, Subscription::query()->where('app_user_id', $member->id)->count());
    }

    public function test_a_receipt_already_attached_to_another_member_is_refused(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $this->apple->transaction('jws-1');

        $this->redeem($owner, 'jws-1')->assertOk();

        $this->redeem($other, 'jws-1')
            ->assertStatus(409)
            ->assertJsonPath('code', 'receipt_owned_elsewhere');

        $this->assertFalse($other->fresh()->is_premium);
        $this->assertTrue($owner->fresh()->is_premium);
        $this->assertSame(1, Order::query()->where('gateway', 'apple')->count());
    }

    public function test_a_family_shared_transaction_is_honoured_for_each_family_member(): void
    {
        // Gap 2: same originalTransactionId, ownership FAMILY_SHARED, and
        // every family member may redeem it on their own account.
        $first = $this->member();
        $second = $this->member();
        $this->apple->transaction('jws-family', ['ownership' => 'family_shared']);

        $this->redeem($first, 'jws-family')->assertOk();
        $this->redeem($second, 'jws-family')->assertOk();

        $this->assertTrue($first->fresh()->is_premium);
        $this->assertTrue($second->fresh()->is_premium);

        $refs = Order::query()->where('gateway', 'apple')->orderBy('id')->pluck('payment_ref')->all();
        $this->assertSame(["txn-1@{$first->uuid}", "txn-1@{$second->uuid}"], $refs);
        $this->assertSame('Family Sharing', Subscription::query()->where('app_user_id', $second->id)->value('note'));
    }

    public function test_a_testflight_sandbox_purchase_succeeds_against_a_production_mode_gateway(): void
    {
        // §2 fix: the operator's test-mode switch is OFF, the verified
        // transaction says Sandbox, and it must still be honoured.
        $this->assertFalse((bool) PaymentGateway::query()->where('slug', 'apple')->value('is_test_mode'));

        $member = $this->member();
        $this->apple->transaction('jws-sandbox', ['environment' => 'sandbox']);

        $this->redeem($member, 'jws-sandbox')->assertOk()->assertJsonPath('data.is_premium', true);

        $this->assertSame('sandbox', Order::query()->where('gateway', 'apple')->value('environment'));
        $this->assertDatabaseHas('activity_logs', ['module' => 'billing', 'action' => 'plan_purchased']);
    }

    public function test_an_unmapped_product_is_refused_and_creates_no_order(): void
    {
        $member = $this->member();
        $this->apple->transaction('jws-mystery', ['productId' => 'mystery_product']);

        $this->redeem($member, 'jws-mystery', productId: 'mystery_product')
            ->assertStatus(422)
            ->assertJsonPath('code', 'product_unknown');

        $this->assertSame(0, Order::query()->count());
        $this->assertFalse($member->fresh()->is_premium);
    }

    public function test_an_expired_unpaid_or_unknown_transaction_is_refused(): void
    {
        $member = $this->member();
        $this->apple->transaction('jws-expired', ['state' => 'expired', 'expiresAt' => now()->subDay()]);
        $this->apple->transaction('jws-pending', ['state' => 'pending']);

        $this->redeem($member, 'jws-expired')->assertStatus(422)->assertJsonPath('code', 'receipt_invalid');
        $this->redeem($member, 'jws-pending')->assertStatus(422)->assertJsonPath('code', 'receipt_invalid');
        $this->redeem($member, 'jws-nobody-knows')->assertStatus(422)->assertJsonPath('code', 'receipt_invalid');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_the_receipt_endpoint_says_so_when_the_store_is_switched_off(): void
    {
        StoreDrivers::reset();
        PaymentGateway::query()->where('slug', 'apple')->update(['is_active' => false]);

        $this->redeem($this->member(), 'jws-1')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('code', 'store_unavailable');
    }

    public function test_the_receipt_endpoint_validates_its_input(): void
    {
        $this->using($this->member());

        $this->postJson('/api/v1/me/premium/receipt', ['platform' => 'windows', 'product_id' => 'x', 'transaction' => 'y'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    // ---- notifications -------------------------------------------------------------

    public function test_a_store_notification_delivered_twice_produces_one_subscription(): void
    {
        $member = $this->member();
        $this->apple->transaction('jws-1');
        $this->redeem($member, 'jws-1')->assertOk();

        $renewal = $this->apple->transaction('jws-2', ['transactionId' => 'txn-2', 'expiresAt' => now()->addMonths(2)]);
        $body = ['id' => 'notif-1', 'type' => 'DID_RENEW', 'token' => 'jws-2'];

        $this->notify('apple', $body)->assertOk();
        $this->notify('apple', $body)->assertOk();

        $active = Subscription::query()->where('app_user_id', $member->id)->active()->get();
        $this->assertCount(1, $active);
        $this->assertTrue($active->first()->ends_at->diffInSeconds($renewal->expiresAt) < 2);
        $this->assertSame(2, Order::query()->where('gateway', 'apple')->count(), 'EXPECTED one order per transaction: txn-1 and txn-2.');
        $this->assertSame(1, GatewayEvent::query()->where('gateway', 'apple')->count());
        $this->assertNotNull(GatewayEvent::query()->firstOrFail()->processed_at);

        // The superseded row is history, not a second entitlement.
        $this->assertSame('expired', Subscription::query()->where('app_user_id', $member->id)->where('external_ref', 'orig-1')->oldest('id')->value('status'));
        $this->assertTrue($member->fresh()->premium_until->diffInSeconds($renewal->expiresAt) < 2);
    }

    public function test_a_notification_whose_fulfilment_fails_once_is_fulfilled_on_redelivery(): void
    {
        $member = $this->member();
        $this->apple->transaction('jws-1');
        $this->redeem($member, 'jws-1')->assertOk();

        $this->apple->transaction('jws-2', ['transactionId' => 'txn-2', 'expiresAt' => now()->addMonths(2)]);
        $body = ['id' => 'notif-retry', 'type' => 'DID_RENEW', 'token' => 'jws-2'];

        $failed = false;

        DB::listen(function ($query) use (&$failed): void {
            if (! $failed && str_contains($query->sql, 'insert into `subscriptions`')) {
                $failed = true;

                throw new RuntimeException('Simulated failure part-way through fulfilment.');
            }
        });

        // First delivery: a 500, so the store will retry.
        $this->notify('apple', $body)->assertStatus(500);
        $this->assertTrue($failed, 'EXPECTED the induced failure to have fired.');

        // Nothing granted, the order still owed, the event recorded but NOT processed.
        $this->assertSame('pending', Order::query()->where('payment_ref', 'txn-2')->value('status'));
        $this->assertSame(1, Subscription::query()->where('app_user_id', $member->id)->active()->count());
        $this->assertSame(1, GatewayEvent::query()->count());
        $this->assertNull(GatewayEvent::query()->firstOrFail()->processed_at);

        // The retry, same notification id: this time it goes through.
        $this->notify('apple', $body)->assertOk();
        $this->assertSame('paid', Order::query()->where('payment_ref', 'txn-2')->value('status'));
        $this->assertNotNull(GatewayEvent::query()->firstOrFail()->processed_at);
        $this->assertSame(1, Subscription::query()->where('app_user_id', $member->id)->active()->count());
        $this->assertSame(2, Subscription::query()->where('app_user_id', $member->id)->count());

        // A third delivery of a handled event changes nothing.
        $this->notify('apple', $body)->assertOk();
        $this->assertSame(2, Subscription::query()->where('app_user_id', $member->id)->count());
        $this->assertSame(2, Order::query()->count());
    }

    public function test_a_refund_notification_ends_the_plan(): void
    {
        $member = $this->member();
        $this->apple->transaction('jws-1');
        $this->redeem($member, 'jws-1')->assertOk();
        $this->assertTrue($member->fresh()->is_premium);

        $this->apple->transaction('jws-refund', ['state' => 'revoked']);
        $this->notify('apple', ['id' => 'notif-refund', 'type' => 'REFUND', 'token' => 'jws-refund'])->assertOk();

        $this->assertFalse($member->fresh()->is_premium);
        $this->assertSame('cancelled', Subscription::query()->where('app_user_id', $member->id)->latest('id')->value('status'));
        $this->assertDatabaseHas('activity_logs', ['module' => 'billing', 'action' => 'plan_ended_by_store']);
    }

    public function test_a_hold_ends_the_entitlement_and_a_recovery_restores_it(): void
    {
        $member = $this->member();
        $this->apple->transaction('jws-1');
        $this->redeem($member, 'jws-1')->assertOk();

        $this->apple->transaction('jws-hold', ['state' => 'hold']);
        $this->notify('apple', ['id' => 'n-hold', 'type' => 'DID_FAIL_TO_RENEW', 'token' => 'jws-hold'])->assertOk();
        $this->assertFalse($member->fresh()->is_premium);

        $this->apple->transaction('jws-recovered', ['transactionId' => 'txn-3', 'expiresAt' => now()->addMonths(2)]);
        $this->notify('apple', ['id' => 'n-recovered', 'type' => 'DID_RENEW', 'token' => 'jws-recovered'])->assertOk();
        $this->assertTrue($member->fresh()->is_premium);
    }

    public function test_a_google_notification_is_only_a_hint_and_the_play_api_is_asked(): void
    {
        // The fake carries no transaction for a Google notification: the
        // handler must call verify() with the token, as it does for RTDN.
        $member = $this->member();
        $this->google->transaction('token-g1', ['originalTransactionId' => 'token-g1', 'transactionId' => 'GPA.1', 'store' => 'google']);
        $this->redeem($member, 'token-g1', platform: 'android')->assertOk()->assertJsonPath('data.premium_source', 'google');

        $this->google->transaction('token-g1', ['originalTransactionId' => 'token-g1', 'transactionId' => 'GPA.2', 'store' => 'google', 'expiresAt' => now()->addMonths(2)]);
        $this->notify('google', ['id' => 'msg-1', 'type' => 'SUBSCRIPTION_RENEWED', 'external_ref' => 'token-g1'])->assertOk();

        $this->assertSame(2, Order::query()->where('gateway', 'google')->count());
        $this->assertTrue($member->fresh()->premium_until->isAfter(now()->addDays(50)));
        $this->assertSame(['GPA.1', 'GPA.2'], $this->google->acknowledged);
    }

    public function test_a_renewal_for_a_deleted_member_is_recorded_and_ignored(): void
    {
        // Gap 4: the store keeps billing; we cannot cancel it for them, and
        // nothing is granted to a pseudonymised row.
        $member = $this->member(['password' => 'secret-pw-1']);
        $this->apple->transaction('jws-1');
        $this->redeem($member, 'jws-1')->assertOk();

        app(AccountDeletion::class)->delete($member->fresh(), 'secret-pw-1');
        $this->assertNotNull(AppUser::withTrashed()->find($member->id)->deleted_at);

        $this->apple->transaction('jws-2', ['transactionId' => 'txn-2', 'expiresAt' => now()->addMonths(2)]);
        $this->notify('apple', ['id' => 'n-after-delete', 'type' => 'DID_RENEW', 'token' => 'jws-2'])->assertOk();

        $this->assertSame(1, Order::query()->where('gateway', 'apple')->count(), 'EXPECTED no new order for a deleted member.');
        $this->assertSame(0, Subscription::query()->where('app_user_id', $member->id)->active()->count());
        $this->assertNotNull(GatewayEvent::query()->where('event_id', 'n-after-delete')->firstOrFail()->processed_at);
    }

    public function test_deleting_the_account_closes_store_rows_and_records_that_the_store_keeps_billing(): void
    {
        $member = $this->member(['password' => 'secret-pw-1']);
        $this->apple->transaction('jws-1');
        $this->redeem($member, 'jws-1')->assertOk();

        app(AccountDeletion::class)->delete($member->fresh(), 'secret-pw-1');

        $row = Subscription::query()->where('app_user_id', $member->id)->firstOrFail();
        $this->assertSame('cancelled', $row->status);
        $this->assertStringContainsString('Account deleted', (string) $row->note);
        $this->assertDatabaseHas('activity_logs', ['module' => 'billing', 'action' => 'store_plan_orphaned']);
    }

    public function test_a_notification_for_an_unknown_transaction_uses_the_app_account_token(): void
    {
        $member = $this->member();

        // The app attached the member's uuid at purchase time and then
        // crashed before posting the receipt.
        $this->apple->transaction('jws-9', ['originalTransactionId' => 'orig-9', 'transactionId' => 'txn-9', 'appAccountToken' => $member->uuid]);
        $this->notify('apple', ['id' => 'n-9', 'type' => 'SUBSCRIBED', 'token' => 'jws-9'])->assertOk();

        $this->assertTrue($member->fresh()->is_premium);
        $this->assertSame($member->id, Order::query()->where('gateway_ref', 'orig-9')->value('app_user_id'));

        // Without the token there is nobody to give it to: recorded, processed, ignored.
        $this->apple->transaction('jws-10', ['originalTransactionId' => 'orig-10', 'transactionId' => 'txn-10']);
        $this->notify('apple', ['id' => 'n-10', 'type' => 'SUBSCRIBED', 'token' => 'jws-10'])->assertOk();

        $this->assertSame(0, Order::query()->where('gateway_ref', 'orig-10')->count());
        $this->assertNotNull(GatewayEvent::query()->where('event_id', 'n-10')->firstOrFail()->processed_at);
    }

    public function test_a_notification_with_a_bad_signature_is_refused_and_not_recorded(): void
    {
        $this->notify('apple', ['id' => 'n-forged', 'type' => 'DID_RENEW', 'token' => 'jws-1'], bearer: 'forged')->assertStatus(400);

        $this->assertSame(0, GatewayEvent::query()->count());
    }

    public function test_a_test_ping_is_recorded_and_ignored(): void
    {
        $this->notify('apple', ['id' => 'n-test', 'type' => 'TEST'])->assertOk();

        $event = GatewayEvent::query()->where('event_id', 'n-test')->firstOrFail();
        $this->assertNotNull($event->processed_at);
        $this->assertSame(0, Order::query()->count());
    }

    // ---- never shorten, never downgrade -------------------------------------------

    public function test_a_store_purchase_never_shortens_or_downgrades_an_active_plan(): void
    {
        // Gap 3: web GOLD until +3 months, then a store PLUS monthly.
        $member = $this->member();
        $gold = Plan::query()->where('slug', 'gold')->firstOrFail();
        app(Subscriptions::class)->grant($member, $gold, now()->addMonths(3), source: 'payment', billingPeriod: 'custom');
        $goldUntil = $member->fresh()->premium_until;

        $this->apple->transaction('jws-plus');
        $this->redeem($member, 'jws-plus')->assertOk()
            ->assertJsonPath('data.premium_tier', 'gold');

        $fresh = $member->fresh();
        $this->assertSame('gold', $fresh->premium_tier);
        $this->assertTrue($fresh->premium_until->equalTo($goldUntil), 'EXPECTED the later end date to be kept.');
        $this->assertSame(2, Subscription::query()->where('app_user_id', $member->id)->active()->count(), 'EXPECTED both rows to stay active.');

        // A renewal of the store plan still does not touch the gold plan.
        $this->apple->transaction('jws-plus-2', ['transactionId' => 'txn-2', 'expiresAt' => now()->addMonths(2)]);
        $this->notify('apple', ['id' => 'n-plus-2', 'type' => 'DID_RENEW', 'token' => 'jws-plus-2'])->assertOk();
        $this->assertSame('gold', $member->fresh()->premium_tier);
        $this->assertTrue($member->fresh()->premium_until->equalTo($goldUntil));

        // And the reverse: a store GOLD plan, then staff give PLUS for a month.
        $other = $this->member();
        $this->apple->transaction('jws-gold', ['originalTransactionId' => 'orig-gold', 'transactionId' => 'txn-gold', 'productId' => 'gold_monthly']);
        $this->redeem($other, 'jws-gold', productId: 'gold_monthly')->assertOk();
        app(Subscriptions::class)->grant($other->fresh(), Plan::query()->where('slug', 'plus')->firstOrFail(), now()->addMonth());
        $this->assertSame('gold', $other->fresh()->premium_tier);
    }

    public function test_when_the_better_plan_lapses_the_other_one_carries_on(): void
    {
        $member = $this->member();
        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'gold')->firstOrFail(), now()->addDay());

        $this->apple->transaction('jws-plus', ['expiresAt' => now()->addMonth()]);
        $this->redeem($member, 'jws-plus')->assertOk();
        $this->assertSame('gold', $member->fresh()->premium_tier);

        $this->travel(2)->days();
        app(Subscriptions::class)->expireDue();

        $fresh = $member->fresh();
        $this->assertTrue($fresh->is_premium, 'EXPECTED the store plan to carry the member after the web plan lapsed.');
        $this->assertSame('plus', $fresh->premium_tier);
    }

    // ---- the rest of the contract --------------------------------------------------

    public function test_store_gateways_are_never_offered_as_a_way_to_pay_on_the_website(): void
    {
        PaymentGateway::query()->where('slug', 'apple')->update(['credentials' => ['issuer_id' => 'x', 'key_id' => 'x', 'private_key' => 'x', 'bundle_id' => 'x', 'app_apple_id' => 'x']]);

        $slugs = app(Checkout::class)->availableGateways('USD')->pluck('slug')->all();

        $this->assertNotContains('apple', $slugs);
        $this->assertNotContains('google', $slugs);
    }

    public function test_the_plans_endpoint_lists_store_products(): void
    {
        $this->getJson('/api/v1/plans')->assertOk()
            ->assertJsonPath('data.0.slug', 'plus')
            ->assertJsonPath('data.0.products.ios.monthly', 'plus_monthly')
            ->assertJsonPath('data.0.products.android.yearly', 'plus_yearly')
            ->assertJsonMissingPath('data.1.products.ios.yearly');
    }

    public function test_support_can_move_a_store_subscription_to_another_account(): void
    {
        $from = $this->member();
        $to = $this->member(['email' => 'right-account@example.test']);
        $this->apple->transaction('jws-1');
        $this->redeem($from, 'jws-1')->assertOk();

        $admin = User::factory()->create(['status' => 'active'])->syncRoles([Role::ADMIN]);
        $subscription = Subscription::query()->where('app_user_id', $from->id)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(SubscriptionsScreen::class)
            ->call('openReassign', $subscription->id)
            ->set('reassignEmail', 'right-account@example.test')
            ->set('reassignNote', 'Signed up twice, ticket #42')
            ->call('reassign')
            ->assertHasNoErrors();

        $this->assertFalse($from->fresh()->is_premium);
        $this->assertTrue($to->fresh()->is_premium);
        $this->assertSame($to->id, Order::query()->where('gateway_ref', 'orig-1')->value('app_user_id'));
        $this->assertDatabaseHas('activity_logs', ['module' => 'billing', 'action' => 'plan_reassigned']);

        // The next renewal follows the subscription to its new owner.
        $this->apple->transaction('jws-2', ['transactionId' => 'txn-2', 'expiresAt' => now()->addMonths(2)]);
        $this->notify('apple', ['id' => 'n-moved', 'type' => 'DID_RENEW', 'token' => 'jws-2'])->assertOk();
        $this->assertSame($to->id, Order::query()->where('payment_ref', 'txn-2')->value('app_user_id'));
    }
}
