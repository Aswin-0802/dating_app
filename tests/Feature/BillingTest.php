<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BanType;
use App\Livewire\Users\Show;
use App\Models\AppUser;
use App\Models\Ban;
use App\Models\EmailLog;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Subscriptions;
use Database\Seeders\MasterSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    /**
     * "Did they get the renewal warning?" has to be answerable from the
     * console, so the delivery log has to describe delivery and not intent.
     *
     * Now that mail is queued, writing `sent` at the moment of dispatch would
     * be a lie: all that has happened is that a job was accepted. The row is
     * written as `queued` and settled by RecordEmailOutcome when the send
     * actually happens.
     */
    public function test_the_email_log_records_delivery_rather_than_intent(): void
    {
        $this->seed(NotificationTemplateSeeder::class);

        // A queue that never runs, which is what a real one looks like in the
        // seconds after dispatch — and what a production install with no
        // worker looks like permanently.
        Queue::fake();

        $member = AppUser::factory()->create();
        $this->grantPlus($member);

        $log = $this->planStartedLogFor($member);

        $this->assertNotNull($log, 'EXPECTED the plan-started email to be logged.');
        $this->assertSame(
            'queued',
            $log->status,
            'EXPECTED a mail that has only been handed to the queue to read as queued, not sent.',
        );
        $this->assertNull($log->sent_at);
    }

    public function test_the_email_log_is_settled_once_the_mail_is_actually_sent(): void
    {
        $this->seed(NotificationTemplateSeeder::class);

        $member = AppUser::factory()->create();
        $this->grantPlus($member);

        // No fake queue here, so the send really happens and
        // RecordEmailOutcome settles the row.
        $log = $this->planStartedLogFor($member);

        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertNotNull($log->sent_at);
    }

    private function grantPlus(AppUser $member): void
    {
        app(Subscriptions::class)->grant(
            $member,
            Plan::query()->where('slug', 'plus')->firstOrFail(),
            now()->addMonth(),
        );
    }

    private function planStartedLogFor(AppUser $member): ?EmailLog
    {
        return EmailLog::query()
            ->where('recipient_id', $member->id)
            ->where('template_key', 'billing.plan_started')
            ->latest('id')
            ->first();
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
