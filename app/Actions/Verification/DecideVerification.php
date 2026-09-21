<?php

declare(strict_types=1);

namespace App\Actions\Verification;

use App\Enums\LadderStep;
use App\Enums\ReasonCode;
use App\Enums\VerificationStatus;
use App\Models\ModerationAction;
use App\Models\User;
use App\Models\Verification;
use App\Services\Audit\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The single write path for verification decisions.
 *
 * Every outcome produces an immutable moderation action alongside the status
 * change, so a verification decision is as reviewable as an enforcement one —
 * rejecting somebody's identity is a consequential act and deserves the same
 * paper trail as banning them.
 */
final class DecideVerification
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function approve(Verification $verification, User $actor): Verification
    {
        if (! $verification->canBeApproved()) {
            throw new RuntimeException(
                'A submission in the minor safety queue cannot be approved.',
            );
        }

        return DB::transaction(function () use ($verification, $actor): Verification {
            $verification->forceFill([
                'status' => VerificationStatus::Approved,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason_code' => null,
            ])->save();

            $verification->appUser?->forceFill([
                'verification_status' => VerificationStatus::Approved,
                'verified_at' => now(),
            ])->save();

            $this->record($verification, $actor, LadderStep::Note, ReasonCode::Other, 'Verification approved');

            $this->logger->log(
                module: 'verification',
                action: 'approved',
                subject: $verification,
                description: "Approved verification for {$verification->appUser?->display_name}",
            );

            return $verification;
        });
    }

    public function reject(
        Verification $verification,
        User $actor,
        ReasonCode $reason,
        ?string $note = null,
    ): Verification {
        return DB::transaction(function () use ($verification, $actor, $reason, $note): Verification {
            $verification->forceFill([
                'status' => VerificationStatus::Rejected,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason_code' => $reason,
                'internal_note' => $note,
            ])->save();

            $verification->appUser?->forceFill([
                'verification_status' => VerificationStatus::Rejected,
            ])->save();

            $this->record($verification, $actor, LadderStep::Note, $reason, $note);

            $this->logger->log(
                module: 'verification',
                action: 'rejected',
                subject: $verification,
                description: "Rejected verification: {$reason->label()}",
                new: ['reason_code' => $reason->value, 'note' => $note],
            );

            return $verification;
        });
    }

    /**
     * Route to the restricted queue.
     *
     * A suspected minor never continues through the standard flow, and the
     * member's account is left unverified rather than rejected — the outcome is
     * a safety review, not a verdict on their photo.
     */
    public function escalate(Verification $verification, User $actor, ?string $note = null): Verification
    {
        return DB::transaction(function () use ($verification, $actor, $note): Verification {
            $verification->forceFill([
                'status' => VerificationStatus::Escalated,
                'queue' => 'restricted_minor',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'internal_note' => $note,
                'sla_due_at' => now()->addHours((int) config('platform.sla.restricted_verification_hours', 4)),
            ])->save();

            $this->record($verification, $actor, LadderStep::Escalate, ReasonCode::MinorSafetyConcern, $note);

            $this->logger->log(
                module: 'verification',
                action: 'escalated',
                subject: $verification,
                description: 'Escalated to the minor safety queue',
                sensitive: true,
            );

            return $verification;
        });
    }

    private function record(
        Verification $verification,
        User $actor,
        LadderStep $step,
        ReasonCode $reason,
        ?string $note,
    ): void {
        ModerationAction::query()->create([
            'uuid' => (string) Str::uuid(),
            'verification_id' => $verification->id,
            'subject_app_user_id' => $verification->app_user_id,
            'actor_id' => $actor->id,
            'actor_type' => 'human',
            'ladder_step' => $step,
            'reason_code' => $reason,
            'policy_clause' => $reason->policyClause(),
            'internal_note' => $note,
            'user_facing_message' => $reason->statement(),
            'notified_user' => false,
        ]);
    }
}
