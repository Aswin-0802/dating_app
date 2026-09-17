<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReportCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AppUserResource;
use App\Models\AppUser;
use App\Models\Block;
use App\Models\MatchRecord;
use App\Models\Report;
use App\Services\Moderation\CaseAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SafetyController extends Controller
{
    /**
     * File a report.
     *
     * Severity is derived from the category rather than chosen by the reporter:
     * people routinely mislabel it, and letting them set it is how a
     * minor-safety report ends up queued behind a spam complaint.
     */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reported_id' => ['required', 'uuid', 'exists:app_users,uuid'],
            'category' => ['required', 'in:'.implode(',', array_column(ReportCategory::cases(), 'value'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'message_id' => ['nullable', 'uuid', 'exists:messages,uuid'],
        ]);

        $reporter = $request->user();
        $reported = AppUser::query()->where('uuid', $data['reported_id'])->firstOrFail();

        if ($reported->id === $reporter->id) {
            throw ValidationException::withMessages(['reported_id' => 'You cannot report yourself.']);
        }

        $category = ReportCategory::from($data['category']);

        $report = DB::transaction(function () use ($data, $reporter, $reported, $category): Report {
            $message = $data['message_id'] ?? null
                ? DB::table('messages')->where('uuid', $data['message_id'])->first()
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
                'description' => $data['description'] ?? null,
                'reporter_credibility_at_time' => $this->credibilityOf($reporter),
                'created_at' => now(),
            ]);

            // The report is folded into a case immediately, so the admin queue
            // is the same object whether a report arrives from the app or from
            // an automated rule.
            app(CaseAggregator::class)->absorb($report);

            return $report;
        });

        return response()->json([
            'message' => 'Thank you. Our team will review this.',
            'report_id' => $report->uuid,
        ], 201);
    }

    public function blocks(Request $request): JsonResponse
    {
        $blocked = AppUser::query()
            ->whereIn('id', fn ($q) => $q->select('blocked_app_user_id')
                ->from('blocks')
                ->where('app_user_id', $request->user()->id))
            ->with('photos')
            ->get();

        return response()->json(['data' => AppUserResource::collection($blocked)]);
    }

    /**
     * Blocking also unmatches.
     *
     * Leaving the match in place would keep the other person visible in the
     * blocker's list, which defeats the point of the block.
     */
    public function block(Request $request): JsonResponse
    {
        $data = $request->validate([
            'blocked_id' => ['required', 'uuid', 'exists:app_users,uuid'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $member = $request->user();
        $target = AppUser::query()->where('uuid', $data['blocked_id'])->firstOrFail();

        if ($target->id === $member->id) {
            throw ValidationException::withMessages(['blocked_id' => 'You cannot block yourself.']);
        }

        DB::transaction(function () use ($member, $target, $data): void {
            Block::query()->firstOrCreate(
                ['app_user_id' => $member->id, 'blocked_app_user_id' => $target->id],
                ['reason' => $data['reason'] ?? null, 'created_at' => now()],
            );

            MatchRecord::query()
                ->involving($member)
                ->involving($target)
                ->update(['status' => 'blocked', 'unmatched_by' => $member->id]);
        });

        return response()->json(['message' => 'Blocked.'], 201);
    }

    public function unblock(Request $request, string $uuid): JsonResponse
    {
        $target = AppUser::query()->where('uuid', $uuid)->firstOrFail();

        Block::query()
            ->where('app_user_id', $request->user()->id)
            ->where('blocked_app_user_id', $target->id)
            ->delete();

        return response()->json(['message' => 'Unblocked.']);
    }

    /**
     * A rough reporter-credibility score, snapshotted onto each report.
     *
     * Someone whose reports consistently lead to action is worth listening to;
     * someone who reports everybody is noise. Snapshotting means a later change
     * in their reputation cannot rewrite how a past decision should be read.
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
