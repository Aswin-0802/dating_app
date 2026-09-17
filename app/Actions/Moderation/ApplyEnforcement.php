<?php

declare(strict_types=1);

namespace App\Actions\Moderation;

use App\Enums\AccountStatus;
use App\Enums\BanType;
use App\Enums\CaseStatus;
use App\Enums\LadderStep;
use App\Enums\ReasonCode;
use App\Models\AppUser;
use App\Models\Ban;
use App\Models\ModerationAction;
use App\Models\ReportCase;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The single write path for the graduated enforcement ladder.
 *
 * Used by the admin UI and by the automation rule runner alike, which is why
 * `actorType` is a parameter rather than "whoever is logged in" — an automated
 * decision has to appear in the audit log exactly as a human one does, and DSA
 * Article 17 requires telling the member which it was.
 *
 * Everything happens in one transaction: the moderation action, the ban row and
 * the denormalised mirror on app_users. Those three disagreeing is the most
 * likely way for the console to say "banned" while the API still lets somebody in.
 */
final class ApplyEnforcement
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function apply(
        AppUser $subject,
        LadderStep $step,
        ReasonCode $reason,
        ?User $actor = null,
        ?ReportCase $case = null,
        ?string $note = null,
        ?int $durationHours = null,
        array $limitedFeatures = [],
        ?\DateTimeInterface $reviewDueAt = null,
        bool $notifyUser = true,
        string $actorType = 'human',
    ): ModerationAction {
        $this->guard($step, $reason, $note, $durationHours, $reviewDueAt);

        return DB::transaction(function () use (
            $subject, $step, $reason, $actor, $case, $note,
            $durationHours, $limitedFeatures, $reviewDueAt, $notifyUser, $actorType
        ): ModerationAction {
            $action = ModerationAction::query()->create([
                'uuid' => (string) Str::uuid(),
                'report_case_id' => $case?->id,
                'subject_app_user_id' => $subject->id,
                'actor_id' => $actorType === 'automation' ? null : $actor?->id,
                'actor_type' => $actorType,
                'ladder_step' => $step,
                'reason_code' => $reason,
                'policy_clause' => $reason->policyClause(),
                'internal_note' => $note,
                'user_facing_message' => $reason->statement(),
                'duration_hours' => $durationHours,
                'notified_user' => $notifyUser,
            ]);

            if ($step->createsBan()) {
                $ban = $this->createBan(
                    $subject, $step, $reason, $action, $actor,
                    $note, $durationHours, $limitedFeatures, $reviewDueAt,
                );

                $this->mirrorOntoMember($subject, $ban);
            }

            if ($case !== null) {
                $case->forceFill([
                    'status' => CaseStatus::Actioned,
                    'resolved_by' => $actor?->id,
                    'resolved_at' => now(),
                    'outcome' => $step->label(),
                ])->save();
            }

            $this->logger->log(
                module: 'enforcement',
                action: $step->value,
                subject: $subject,
                description: sprintf(
                    '%s applied to %s (%s)',
                    $step->label(),
                    $subject->display_name,
                    $reason->label(),
                ),
                new: [
                    'reason_code' => $reason->value,
                    'policy_clause' => $reason->policyClause(),
                    'duration_hours' => $durationHours,
                    'actor_type' => $actorType,
                ],
            );

            return $action;
        });
    }

    /**
     * Reverse an enforcement.
     *
     * Records a new action linked to the original rather than editing it —
     * moderation actions are append-only, so a reversal is a fact added to the
     * record, not a fact removed from it.
     */
    public function lift(
        Ban $ban,
        User $actor,
        ReasonCode $reason,
        ?string $note = null,
    ): ModerationAction {
        return DB::transaction(function () use ($ban, $actor, $reason, $note): ModerationAction {
            $ban->forceFill([
                'lifted_by' => $actor->id,
                'lifted_at' => now(),
                'lift_reason' => $note,
            ])->save();

            $action = ModerationAction::query()->create([
                'uuid' => (string) Str::uuid(),
                'subject_app_user_id' => $ban->app_user_id,
                'actor_id' => $actor->id,
                'actor_type' => 'human',
                'ladder_step' => LadderStep::Lift,
                'reason_code' => $reason,
                'policy_clause' => $reason->policyClause(),
                'internal_note' => $note,
                'user_facing_message' => $reason->statement(),
                'notified_user' => true,
            ]);

            if ($ban->moderation_action_id !== null) {
                // Append-only, so the link is written with a raw update rather
                // than through the model's guarded save path.
                DB::table('moderation_actions')
                    ->where('id', $ban->moderation_action_id)
                    ->update(['reversed_by_action_id' => $action->id]);
            }

            $this->recomputeMirror($ban->appUser);

            $this->logger->log(
                module: 'enforcement',
                action: 'lifted',
                subject: $ban->appUser,
                description: "{$ban->type->label()} lifted for {$ban->appUser?->display_name}",
                new: ['reason_code' => $reason->value, 'note' => $note],
            );

            return $action;
        });
    }

    private function guard(
        LadderStep $step,
        ReasonCode $reason,
        ?string $note,
        ?int $durationHours,
        ?\DateTimeInterface $reviewDueAt,
    ): void {
        if ($reason->requiresNote() && blank($note)) {
            throw new InvalidArgumentException("The reason '{$reason->label()}' requires a written note.");
        }

        if ($step->requiresDuration() && $durationHours === null) {
            throw new InvalidArgumentException("{$step->label()} requires a duration.");
        }

        /*
         * A shadow ban without a review date is a permanent secret punishment.
         * The member cannot see it, so nothing but a calendar entry will ever
         * prompt anyone to revisit it.
         */
        if ($step->requiresReviewDate() && $reviewDueAt === null) {
            throw new InvalidArgumentException(
                'A shadow ban must have a review date. It is invisible to the member, so it cannot be open-ended.',
            );
        }

        $maxHours = (int) veyra_setting(
            'enforcement.shadow_ban_max_hours',
            config('veyra.enforcement.shadow_ban_max_hours', 720),
        );

        if ($step === LadderStep::ShadowBan && $durationHours !== null && $durationHours > $maxHours) {
            throw new InvalidArgumentException(
                'A shadow ban cannot exceed '.veyra_hours_label($maxHours).'.',
            );
        }
    }

    private function createBan(
        AppUser $subject,
        LadderStep $step,
        ReasonCode $reason,
        ModerationAction $action,
        ?User $actor,
        ?string $note,
        ?int $durationHours,
        array $limitedFeatures,
        ?\DateTimeInterface $reviewDueAt,
    ): Ban {
        $type = BanType::from($step === LadderStep::Suspend ? 'suspension' : $step->value);

        return Ban::query()->create([
            'uuid' => (string) Str::uuid(),
            'app_user_id' => $subject->id,
            'moderation_action_id' => $action->id,
            'type' => $type,
            'limited_features' => $type === BanType::FeatureLimit ? $limitedFeatures : null,
            'reason_code' => $reason,
            'internal_note' => $note,
            'user_facing_message' => $reason->statement(),
            'issued_by' => $actor?->id,
            'starts_at' => now(),
            'expires_at' => $durationHours !== null ? now()->addHours($durationHours) : null,
            'review_due_at' => $reviewDueAt,
        ]);
    }

    /**
     * Keep the app_users mirror in step with the new ban.
     *
     * The mirror exists so the API can answer "may this account act?" without a
     * join. It is written inside the same transaction as the ban for exactly
     * that reason.
     */
    private function mirrorOntoMember(AppUser $subject, Ban $ban): void
    {
        $attributes = [
            'active_ban_id' => $ban->id,
            'account_status' => $ban->type->accountStatus(),
        ];

        match ($ban->type) {
            BanType::ShadowBan => $attributes['shadow_banned_until'] = $ban->expires_at,
            BanType::Suspension => $attributes['suspended_until'] = $ban->expires_at,
            BanType::PermanentBan, BanType::DeviceBan => $attributes['banned_at'] = now(),
            default => null,
        };

        $subject->forceFill($attributes)->save();
    }

    /** Recompute the mirror from whatever bans remain in force. */
    private function recomputeMirror(?AppUser $subject): void
    {
        if ($subject === null) {
            return;
        }

        $active = $subject->bans()->active()->get();

        if ($active->isEmpty()) {
            $subject->forceFill([
                'active_ban_id' => null,
                'account_status' => AccountStatus::Active,
                'shadow_banned_until' => null,
                'suspended_until' => null,
                'banned_at' => null,
            ])->save();

            return;
        }

        // The most severe remaining ban decides the account's status.
        $strongest = $active->sortByDesc(fn (Ban $ban): int => match ($ban->type) {
            BanType::FeatureLimit => 1,
            BanType::ShadowBan => 2,
            BanType::Suspension => 3,
            BanType::PermanentBan, BanType::DeviceBan => 4,
        })->first();

        $subject->forceFill([
            'active_ban_id' => $strongest->id,
            'account_status' => $strongest->type->accountStatus(),
            'shadow_banned_until' => $active->firstWhere('type', BanType::ShadowBan)?->expires_at,
            'suspended_until' => $active->firstWhere('type', BanType::Suspension)?->expires_at,
            'banned_at' => $active->first(fn (Ban $b) => in_array($b->type, [BanType::PermanentBan, BanType::DeviceBan], true))
                ? now() : null,
        ])->save();
    }
}
