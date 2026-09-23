<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Enums\ReportCategory;
use App\Models\AppUser;
use App\Models\Block;
use App\Models\Report;
use App\Services\Moderation\CaseAggregator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reporting and blocking, shared by the website and the mobile API.
 */
final class SafetyActions
{
    /**
     * File a report.
     *
     * Severity is derived from the category rather than chosen by the reporter:
     * people routinely mislabel it, and letting them set it is how a
     * minor-safety report ends up queued behind a spam complaint.
     */
    public function report(
        AppUser $reporter,
        AppUser $reported,
        ReportCategory $category,
        ?string $description = null,
        ?string $messageUuid = null,
    ): Report {
        if ($reported->id === $reporter->id) {
            throw ValidationException::withMessages(['reported_id' => 'You cannot report yourself.']);
        }

        // One report per person per day. Repeats add nothing the first did not,
        // and would let one member inflate a case against somebody.
        $recent = Report::query()
            ->where('reporter_app_user_id', $reporter->id)
            ->where('reported_app_user_id', $reported->id)
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($recent) {
            throw ValidationException::withMessages([
                'reported_id' => 'You have already reported this person today. Our safety team is reviewing it.',
            ]);
        }

        return DB::transaction(function () use ($reporter, $reported, $category, $description, $messageUuid): Report {
            $message = $this->evidenceFor($reporter, $reported, $messageUuid);

            $report = Report::query()->create([
                'uuid' => (string) Str::uuid(),
                'reporter_app_user_id' => $reporter->id,
                'reported_app_user_id' => $reported->id,
                'category' => $category,
                'severity' => $category->defaultSeverity(),
                'source' => 'user',
                'message_id' => $message?->id,
                'conversation_id' => $message?->conversation_id,
                'description' => $description,
                'reporter_credibility_at_time' => $this->credibilityOf($reporter),
                'created_at' => now(),
            ]);

            // Folded into a case immediately, so the admin queue is the same
            // object whether the report came from the app, the website or a rule.
            app(CaseAggregator::class)->absorb($report);

            return $report;
        });
    }

    /**
     * Blocking also unmatches: leaving the match in place would keep the other
     * person visible in the blocker's list, which defeats the point.
     *
     * It closes the conversation too. Marking the match blocked while the
     * thread stayed open let the blocked person carry on messaging — the block
     * changed a status column and nothing a member could feel.
     */
    public function block(AppUser $member, AppUser $target, ?string $reason = null): void
    {
        if ($target->id === $member->id) {
            throw ValidationException::withMessages(['blocked_id' => 'You cannot block yourself.']);
        }

        DB::transaction(function () use ($member, $target, $reason): void {
            Block::query()->firstOrCreate(
                ['app_user_id' => $member->id, 'blocked_app_user_id' => $target->id],
                ['reason' => $reason, 'created_at' => now()],
            );

            app(MatchActions::class)->endAllBetween($member, $target, 'blocked');
        });
    }

    /**
     * Resolve the message a report points at, if it is really evidence.
     *
     * A message id was previously taken on trust, so a reporter could attach
     * somebody else's message to a report against an unrelated member. A
     * moderator would then open a case against an innocent person and, through
     * the reveal flow, read a private message from a conversation neither of
     * them was in.
     *
     * Failing the check drops the evidence rather than the report: the
     * complaint may still be genuine, and refusing it outright would give an
     * abuser a way to find out which ids are real.
     */
    private function evidenceFor(AppUser $reporter, AppUser $reported, ?string $messageUuid): ?object
    {
        if ($messageUuid === null) {
            return null;
        }

        $message = DB::table('messages')->where('uuid', $messageUuid)->first();

        if ($message === null) {
            return null;
        }

        $reporterIsInIt = DB::table('conversation_participants')
            ->where('conversation_id', $message->conversation_id)
            ->where('app_user_id', $reporter->id)
            ->exists();

        // Reported by somebody in the room, and about something the reported
        // member actually said.
        return $reporterIsInIt && (int) $message->sender_app_user_id === $reported->id
            ? $message
            : null;
    }

    public function unblock(AppUser $member, AppUser $target): void
    {
        Block::query()
            ->where('app_user_id', $member->id)
            ->where('blocked_app_user_id', $target->id)
            ->delete();
    }

    /**
     * A rough reporter-credibility score, snapshotted onto each report, so a
     * later change in their reputation cannot rewrite how a past decision
     * should be read.
     */
    private function credibilityOf(AppUser $reporter): int
    {
        $filed = Report::query()->where('reporter_app_user_id', $reporter->id)->count();

        if ($filed === 0) {
            return 50;
        }

        $actioned = DB::table('reports as r')
            ->join('report_cases as c', 'c.id', '=', 'r.report_case_id')
            ->where('r.reporter_app_user_id', $reporter->id)
            ->whereIn('c.status', ['actioned', 'appealed'])
            ->count();

        return (int) max(5, min(95, round($actioned / $filed * 100)));
    }
}
