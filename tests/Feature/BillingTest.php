<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BanType;
use App\Livewire\Users\Show;
use App\Models\AppUser;
use App\Models\Ban;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Subscriptions;
use Database\Seeders\MasterSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingSeeder::class, MasterSeeder::class]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['status' => 'active'])->syncRoles([$role]);
    }

    public function test_staff_can_put_a_member_on_a_plan(): void
    {
        $member = AppUser::factory()->create(['is_premium' => false]);

        Livewire::actingAs($this->staff(Role::ADMIN))
            ->test(Show::class, ['appUser' => $member])
            ->call('openPlanForm')
            ->set('planSlug', 'gold')
            ->set('planLength', '12m')
            ->set('planNote', 'Paid by bank transfer, ref 4471')
            ->call('savePlan')
            ->assertHasNoErrors();

        $member->refresh();
        $this->assertTrue($member->is_premium);
        $this->assertSame('gold', $member->premium_tier);
        $this->assertTrue($member->premium_until->isBetween(now()->addYear()->subDay(), now()->addYear()->addDay()));
        $this->assertTrue($member->hasPremiumFeature('priority_support'));

        $subscription = Subscription::query()->where('app_user_id', $member->id)->firstOrFail();
        $this->assertSame('active', $subscription->status);
        $this->assertSame('manual', $subscription->source);
        $this->assertSame('Paid by bank transfer, ref 4471', $subscription->note);

        $this->assertDatabaseHas('activity_logs', ['module' => 'billing', 'action' => 'plan_granted']);
    }

    public function test_a_second_plan_replaces_the_first_rather_than_stacking(): void
    {
        $member = AppUser::factory()->create();
        $subscriptions = app(Subscriptions::class);

        $subscriptions->grant($member, Plan::query()->where('slug', 'plus')->firstOrFail(), now()->addMonth());
        $subscriptions->grant($member->fresh(), Plan::query()->where('slug', 'gold')->firstOrFail(), now()->addMonth());

        $this->assertSame(1, Subscription::query()->where('app_user_id', $member->id)->active()->count());
        $this->assertSame('gold', $member->fresh()->premium_tier);
    }

    public function test_plan_dates_are_validated_and_removal_works(): void
    {
        $member = AppUser::factory()->create();
        $admin = $this->staff(Role::ADMIN);

        Livewire::actingAs($admin)
            ->test(Show::class, ['appUser' => $member])
            ->call('openPlanForm')
            ->set('planSlug', 'plus')
            ->set('planLength', 'custom')
            ->set('planEndsAt', now()->subDay()->format('Y-m-d'))
            ->call('savePlan')
            ->assertHasErrors(['planEndsAt' => 'after']);

        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'plus')->firstOrFail(), now()->addMonth());

        Livewire::actingAs($admin)->test(Show::class, ['appUser' => $member->fresh()])->call('removePlan');

        $member->refresh();
        $this->assertFalse($member->is_premium);
        $this->assertNull($member->premium_tier);
        $this->assertSame('cancelled', Subscription::query()->where('app_user_id', $member->id)->first()->status);
    }

    public function test_only_staff_who_edit_users_can_change_a_plan(): void
    {
        $member = AppUser::factory()->create();

        Livewire::actingAs($this->staff(Role::ANALYST))
            ->test(Show::class, ['appUser' => $member])
            ->call('openPlanForm')
            ->assertForbidden();
    }

    // ---- the scheduled task ------------------------------------------------------

    public function test_lapsed_plans_end_by_themselves(): void
    {
        $member = AppUser::factory()->create();
        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'plus')->firstOrFail(), now()->addDay());

        $this->travel(2)->days();
        $this->artisan('platform:run-due-tasks')->assertSuccessful();

        $member->refresh();
        $this->assertFalse($member->is_premium);
        $this->assertNull($member->premium_tier);
        $this->assertSame('expired', Subscription::query()->where('app_user_id', $member->id)->first()->status);
    }

    public function test_an_open_ended_plan_is_left_alone(): void
    {
        $member = AppUser::factory()->create();
        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'gold')->firstOrFail(), null);

        $this->travel(2)->years();
        $this->artisan('platform:run-due-tasks')->assertSuccessful();

        $this->assertTrue($member->fresh()->is_premium);
    }

    public function test_expired_restrictions_are_lifted_by_the_scheduled_task(): void
    {
        $member = AppUser::factory()->create(['account_status' => AccountStatus::ShadowBanned]);

        $ban = Ban::query()->create([
            'app_user_id' => $member->id,
            'type' => BanType::ShadowBan,
            'reason_code' => 'spam_or_advertising',
            'starts_at' => now()->subWeek(),
            'expires_at' => now()->subHour(),
            'review_due_at' => now()->subHour(),
        ]);

        $member->forceFill(['active_ban_id' => $ban->id, 'shadow_banned_until' => $ban->expires_at])->save();

        $this->artisan('platform:run-due-tasks')->assertSuccessful();

        $this->assertNotNull($ban->fresh()->lifted_at);
        $this->assertSame(AccountStatus::Active, $member->fresh()->account_status);
        $this->assertDatabaseHas('moderation_actions', [
            'subject_app_user_id' => $member->id,
            'actor_type' => 'automation',
            'reason_code' => 'expired_automatically',
        ]);
    }

    public function test_the_billing_tab_lists_the_history(): void
    {
        $member = AppUser::factory()->create();
        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'plus')->firstOrFail(), now()->addMonth(), note: 'Goodwill');

        Livewire::actingAs($this->staff(Role::ADMIN))
            ->test(Show::class, ['appUser' => $member->fresh()])
            ->call('setTab', 'billing')
            ->assertSee('Plus')
            ->assertSee('Goodwill');
    }
}
