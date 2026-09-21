<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Billing\Payments;
use App\Livewire\Billing\Subscriptions as SubscriptionsScreen;
use App\Models\AppUser;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\Subscriptions;
use Database\Seeders\MasterSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class BillingScreensTest extends TestCase
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

    private function staff(string $role): User
    {
        return User::factory()->create(['status' => 'active'])->syncRoles([$role]);
    }

    private function order(array $attributes = []): Order
    {
        return Order::query()->create(array_merge([
            'app_user_id' => AppUser::factory()->create()->id,
            'purpose' => 'plan',
            'reference' => 'plus',
            'description' => 'Plus · 1 month',
            'amount_minor' => 1299,
            'currency' => 'USD',
            'billing_period' => 'monthly',
            'gateway' => 'stripe',
            'gateway_ref' => 'cs_'.fake()->uuid(),
            'status' => 'paid',
            'paid_at' => now(),
        ], $attributes));
    }

    // ---- payments -----------------------------------------------------------------

    public function test_the_payments_screen_totals_only_what_was_actually_paid(): void
    {
        $this->order();
        $this->order(['amount_minor' => 2499]);
        $this->order(['status' => 'failed', 'amount_minor' => 9900]);
        $this->order(['status' => 'pending', 'amount_minor' => 5000]);

        Livewire::actingAs($this->staff(Role::ADMIN))
            ->test(Payments::class)
            ->assertSee('$37.98')      // 12.99 + 24.99, not the failed 99.00
            ->assertSee('Plus · 1 month');
    }

    public function test_amounts_in_different_currencies_are_never_added_together(): void
    {
        $this->order(['amount_minor' => 1299, 'currency' => 'USD']);
        $this->order(['amount_minor' => 129900, 'currency' => 'INR']);

        Livewire::actingAs($this->staff(Role::ADMIN))
            ->test(Payments::class)
            ->assertSee('$12.99')
            ->assertSee('₹1,299.00');
    }

    public function test_payments_can_be_searched_and_filtered(): void
    {
        $member = AppUser::factory()->create(['display_name' => 'Asha Reddy', 'email' => 'asha@example.test']);
        $this->order(['app_user_id' => $member->id]);
        $this->order(['status' => 'failed', 'description' => 'Gold · 1 month']);

        $component = Livewire::actingAs($this->staff(Role::ADMIN))->test(Payments::class);

        $component->set('search', 'asha@example.test')
            ->assertSee('Asha Reddy')
            ->assertDontSee('Gold · 1 month');

        $component->set('search', '')->set('status', 'failed')
            ->assertSee('Gold · 1 month')
            ->assertDontSee('Asha Reddy');
    }

    public function test_a_waiting_payment_can_be_rechecked_with_the_gateway(): void
    {
        // Saved through the model, so the credentials go through their
        // encryption cast the way the settings screen writes them.
        PaymentGateway::query()->where('slug', 'stripe')->firstOrFail()->forceFill([
            'credentials' => ['publishable_key' => 'pk', 'secret_key' => 'sk_test', 'webhook_secret' => 'whsec'],
            'is_active' => true,
        ])->save();

        $order = $this->order(['status' => 'pending', 'paid_at' => null, 'gateway_ref' => 'cs_recheck']);

        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'cs_recheck', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_9',
        ])]);

        Livewire::actingAs($this->staff(Role::ADMIN))
            ->test(Payments::class)
            ->call('recheck', $order->id);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertTrue($order->fresh()->appUser->is_premium);
    }

    public function test_the_export_needs_its_own_permission_and_is_logged(): void
    {
        $this->order();

        // Support can read payments but not take the list away.
        Livewire::actingAs($this->staff(Role::SUPPORT))
            ->test(Payments::class)
            ->call('export')
            ->assertForbidden();

        Livewire::actingAs($this->staff(Role::ADMIN))->test(Payments::class)->call('export');

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'billing',
            'action' => 'payments_exported',
            'is_sensitive' => true,
        ]);
    }

    // ---- subscriptions ---------------------------------------------------------------

    public function test_the_subscriptions_screen_separates_active_ending_and_lapsed(): void
    {
        $plus = Plan::query()->where('slug', 'plus')->firstOrFail();
        $subscriptions = app(Subscriptions::class);

        $steady = AppUser::factory()->create(['display_name' => 'Steady Sam']);
        $ending = AppUser::factory()->create(['display_name' => 'Ending Eve']);

        $subscriptions->grant($steady, $plus, now()->addMonths(6));
        $subscriptions->grant($ending, $plus, now()->addDays(3));

        $component = Livewire::actingAs($this->staff(Role::ADMIN))->test(SubscriptionsScreen::class);

        $component->assertSee('Steady Sam')->assertSee('Ending Eve');

        $component->call('setView', 'ending')
            ->assertSee('Ending Eve')
            ->assertDontSee('Steady Sam');
    }

    public function test_a_lapsed_plan_moves_out_of_the_active_view(): void
    {
        $member = AppUser::factory()->create(['display_name' => 'Lapsed Lee']);
        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'gold')->firstOrFail(), now()->addDay());

        $this->travel(2)->days();
        $this->artisan('platform:run-due-tasks')->assertSuccessful();

        $component = Livewire::actingAs($this->staff(Role::ADMIN))->test(SubscriptionsScreen::class);

        $component->assertDontSee('Lapsed Lee');
        $component->call('setView', 'expired')->assertSee('Lapsed Lee');
    }

    // ---- who can see what ---------------------------------------------------------------

    public function test_billing_is_open_to_support_and_closed_to_moderators(): void
    {
        $this->actingAs($this->staff(Role::SUPPORT))
            ->get(route('admin.billing.payments'))->assertOk();

        $this->actingAs($this->staff(Role::SUPPORT))
            ->get(route('admin.billing.subscriptions'))->assertOk();

        // A moderator has no business seeing revenue.
        $this->actingAs($this->staff(Role::MODERATOR))
            ->get(route('admin.billing.payments'))->assertForbidden();

        // Nor does support get to change what is sold.
        $this->actingAs($this->staff(Role::SUPPORT))
            ->get(route('admin.billing.plans'))->assertForbidden();
    }

    public function test_the_moved_screens_are_where_the_new_menu_says_they_are(): void
    {
        $admin = $this->staff(Role::SUPER_ADMIN);

        foreach ([
            'admin.billing.payments', 'admin.billing.subscriptions', 'admin.billing.plans', 'admin.billing.gateways',
            'admin.notifications.push', 'admin.notifications.sms',
            'admin.masters.locations', 'admin.settings.mail', 'admin.settings.logs',
        ] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }
}
