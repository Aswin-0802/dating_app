<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Enums\ReportCategory;
use App\Models\AppUser;
use App\Models\Block;
use App\Models\MatchRecord;
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
            $message = $messageUuid !== null
                ? DB::table('messages')->where('uuid', $messageUuid)->first()
                : null;

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

            MatchRecord::query()
                ->involving($member)
                ->involving($target)
                ->update(['status' => 'blocked', 'unmatched_by' => $member->id]);
        });
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
