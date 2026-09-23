<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Moderation\ApplyEnforcement;
use App\Enums\AccountStatus;
use App\Enums\BanType;
use App\Enums\CaseStatus;
use App\Enums\LadderStep;
use App\Enums\ReasonCode;
use App\Models\ActivityLog;
use App\Models\AppUser;
use App\Models\Ban;
use App\Models\ReportCase;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class EnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $moderator;

    private AppUser $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->moderator = User::factory()->create(['status' => 'active']);
        $this->moderator->syncRoles([Role::TS_LEAD]);

        $this->member = AppUser::factory()->create([
            'account_status' => AccountStatus::Active,
        ]);
    }

    private function action(): ApplyEnforcement
    {
        return app(ApplyEnforcement::class);
    }

    /**
     * Lifting a restriction returns a member to where they were. It is not a
     * decision to publish them: an account that was still `pending` (profile
     * unfinished) or that the member had deactivated themselves used to be
     * promoted straight into the deck the moment a suspension ended.
     *
     * @dataProvider statusesThatMustSurviveARestriction
     */
    public function test_lifting_a_ban_restores_the_status_the_account_had_before(string $before): void
    {
        $status = AccountStatus::from($before);
        $this->member->forceFill(['account_status' => $status])->save();

        $this->action()->apply(
            subject: $this->member->fresh(),
            step: LadderStep::Suspend,
            reason: ReasonCode::HarassmentConfirmed,
            actor: $this->moderator,
            durationHours: 24,
        );

        $this->assertSame(AccountStatus::Suspended, $this->member->fresh()->account_status);

        $this->action()->lift(
            ban: Ban::query()->where('app_user_id', $this->member->id)->firstOrFail(),
            actor: $this->moderator,
            reason: ReasonCode::AppealOverturned,
        );

        $this->assertSame(
            $status,
            $this->member->fresh()->account_status,
            "EXPECTED a lift to restore {$before}, not to promote the account.",
        );
    }

    /** @return array<string, array<int, string>> */
    public static function statusesThatMustSurviveARestriction(): array
    {
        return [
            'pending' => ['pending'],
            'deactivated' => ['deactivated'],
        ];
    }

    public function test_lifting_a_ban_still_restores_an_ordinary_member_to_active(): void
    {
        $this->action()->apply(
            subject: $this->member,
            step: LadderStep::Suspend,
            reason: ReasonCode::HarassmentConfirmed,
            actor: $this->moderator,
            durationHours: 24,
        );

        $this->action()->lift(
            ban: Ban::query()->where('app_user_id', $this->member->id)->firstOrFail(),
            actor: $this->moderator,
            reason: ReasonCode::AppealOverturned,
        );

        $this->assertSame(AccountStatus::Active, $this->member->fresh()->account_status);
    }

    public function test_a_suspension_writes_the_action_the_ban_and_the_mirror_together(): void
    {
        $this->actingAs($this->moderator);

        $action = $this->action()->apply(
            subject: $this->member,
            step: LadderStep::Suspend,
            reason: ReasonCode::HarassmentConfirmed,
            actor: $this->moderator,
            note: 'Repeated abusive messages after a warning.',
            durationHours: 168,
        );

        $this->assertDatabaseHas('moderation_actions', [
            'id' => $action->id,
            'ladder_step' => LadderStep::Suspend->value,
            'reason_code' => ReasonCode::HarassmentConfirmed->value,
            'actor_type' => 'human',
            // DSA Art. 17 requires naming the specific clause, not a category.
            'policy_clause' => ReasonCode::HarassmentConfirmed->policyClause(),
        ]);

        $ban = Ban::query()->where('app_user_id', $this->member->id)->firstOrFail();
        $this->assertSame(BanType::Suspension, $ban->type);
        $this->assertTrue($ban->expires_at->isFuture());

        // The mirror is what the API reads, so it has to move in the same write.
        $this->member->refresh();
        $this->assertSame(AccountStatus::Suspended, $this->member->account_status);
        $this->assertSame($ban->id, $this->member->active_ban_id);
        $this->assertNotNull($this->member->suspended_until);
    }

    public function test_a_shadow_ban_without_a_review_date_is_refused(): void
    {
        $this->actingAs($this->moderator);

        // A shadow ban is invisible to the member, so nothing but a calendar
        // entry will ever prompt a revisit. Open-ended is not an option.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have a review date');

        $this->action()->apply(
            subject: $this->member,
            step: LadderStep::ShadowBan,
            reason: ReasonCode::SpamOrAdvertising,
            actor: $this->moderator,
            durationHours: 168,
            reviewDueAt: null,
        );
    }

    public function test_a_shadow_ban_with_a_review_date_is_accepted_and_surfaces_when_overdue(): void
    {
        $this->actingAs($this->moderator);

        $this->action()->apply(
            subject: $this->member,
            step: LadderStep::ShadowBan,
            reason: ReasonCode::SpamOrAdvertising,
            actor: $this->moderator,
            durationHours: 168,
            reviewDueAt: now()->subDay(),
        );

        $this->member->refresh();
        $this->assertSame(AccountStatus::ShadowBanned, $this->member->account_status);

        $this->assertSame(1, Ban::query()->reviewDue()->count());
    }

    public function test_a_shadow_ban_cannot_exceed_the_configured_maximum(): void
    {
        $this->actingAs($this->moderator);

        $this->expectException(InvalidArgumentException::class);

        $this->action()->apply(
            subject: $this->member,
            step: LadderStep::ShadowBan,
            reason: ReasonCode::SpamOrAdvertising,
            actor: $this->moderator,
            durationHours: 10_000,
            reviewDueAt: now()->addWeek(),
        );
    }

    public function test_a_reason_requiring_a_note_is_refused_without_one(): void
    {
        $this->actingAs($this->moderator);

        $this->expectException(InvalidArgumentException::class);

        $this->action()->apply(
            subject: $this->member,
            step: LadderStep::Warn,
            reason: ReasonCode::Other,
            actor: $this->moderator,
            note: null,
        );
    }

    public function test_moderation_actions_cannot_be_edited_or_deleted(): void
    {
        $this->actingAs($this->moderator);

        $action = $this->action()->apply(
            subject: $this->member,
            step: LadderStep::Warn,
            reason: ReasonCode::SpamOrAdvertising,
            actor: $this->moderator,
        );

        // An audit trail that can be rewritten after the fact is not evidence.
        try {
            $action->update(['internal_note' => 'rewritten']);
            $this->fail('Expected a moderation action to be immutable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $action->delete();
    }

    public function test_lifting_records_a_reversal_rather_than_erasing_the_original(): void
    {
        $this->actingAs($this->moderator);

        $original = $this->action()->apply(
            subject: $this->member,
            step: LadderStep::Suspend,
            reason: ReasonCode::HarassmentConfirmed,
            actor: $this->moderator,
            note: 'Initial decision.',
            durationHours: 24,
        );

        $ban = Ban::query()->where('app_user_id', $this->member->id)->firstOrFail();

        $reversal = $this->action()->lift(
            ban: $ban,
            actor: $this->moderator,
            reason: ReasonCode::AppealOverturned,
            note: 'Appeal upheld on review.',
        );

        // The original survives, now pointing at what reversed it.
        $original->refresh();
        $this->assertSame($reversal->id, $original->reversed_by_action_id);
        $this->assertSame(LadderStep::Lift, $reversal->ladder_step);

        $ban->refresh();
        $this->assertNotNull($ban->lifted_at);

        // With nothing left in force the member returns to active.
        $this->member->refresh();
        $this->assertSame(AccountStatus::Active, $this->member->account_status);
        $this->assertNull($this->member->active_ban_id);
    }

    public function test_actioning_a_case_resolves_it(): void
    {
        $this->actingAs($this->moderator);

        $case = ReportCase::query()->create([
            'case_number' => 'VEY-TEST-000001',
            'subject_app_user_id' => $this->member->id,
            'status' => CaseStatus::InReview,
            'severity' => 'high',
            'sla_due_at' => now()->addHours(8),
        ]);

        $this->action()->apply(
            subject: $this->member,
            step: LadderStep::PermanentBan,
            reason: ReasonCode::RomanceScam,
            actor: $this->moderator,
            case: $case,
            note: 'Confirmed advance-fee pattern across four matches.',
        );

        $case->refresh();
        $this->assertSame(CaseStatus::Actioned, $case->status);
        $this->assertSame($this->moderator->id, $case->resolved_by);
        $this->assertNotNull($case->resolved_at);
    }

    public function test_an_automated_decision_is_recorded_as_such(): void
    {
        // The audit log and the statement of reasons both have to distinguish a
        // rule from a person, so actor_type is not cosmetic.
        $action = $this->action()->apply(
            subject: $this->member,
            step: LadderStep::FeatureLimit,
            reason: ReasonCode::SpamOrAdvertising,
            actor: null,
            durationHours: 24,
            limitedFeatures: ['new_likes'],
            actorType: 'automation',
        );

        $this->assertSame('automation', $action->actor_type);
        $this->assertNull($action->actor_id);
        $this->assertSame('Automated rule', $action->actorLabel());
    }

    public function test_every_enforcement_writes_an_audit_entry(): void
    {
        $this->actingAs($this->moderator);

        $this->action()->apply(
            subject: $this->member,
            step: LadderStep::Warn,
            reason: ReasonCode::SpamOrAdvertising,
            actor: $this->moderator,
        );

        $log = ActivityLog::query()->where('module', 'enforcement')->latest('id')->firstOrFail();

        $this->assertSame('warn', $log->action);
        $this->assertSame($this->moderator->id, $log->user_id);
        // Actor identity is snapshotted so the log still reads after a staff
        // member is deleted.
        $this->assertSame($this->moderator->name, $log->actor_name);
    }

    public function test_the_enforcement_mirror_agrees_with_the_bans_table(): void
    {
        $this->actingAs($this->moderator);

        $this->action()->apply(
            subject: $this->member,
            step: LadderStep::PermanentBan,
            reason: ReasonCode::FakeProfileConfirmed,
            actor: $this->moderator,
            note: 'Stolen photos confirmed.',
        );

        $this->member->refresh();

        $activeBan = Ban::query()
            ->where('app_user_id', $this->member->id)
            ->active()
            ->firstOrFail();

        // This pair disagreeing is how the console says "banned" while the API
        // still lets somebody in, so it is asserted directly.
        $this->assertSame($activeBan->id, $this->member->active_ban_id);
        $this->assertSame(
            $activeBan->type->accountStatus(),
            $this->member->account_status,
        );
    }
}
