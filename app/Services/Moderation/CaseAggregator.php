<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Enums\CaseStatus;
use App\Enums\Severity;
use App\Models\Report;
use App\Models\ReportCase;
use Illuminate\Support\Facades\DB;

/**
 * Folds reports into cases.
 *
 * Reports are the raw signal; the case is the unit of work. Without this, three
 * people reporting the same account produce three queue items, three moderators
 * review the same profile, and the fact that there were three reports — the most
 * important fact available — is invisible on each of them.
 */
final class CaseAggregator
{
    /**
     * Attach a report to the subject's open case, or open one.
     */
    public function absorb(Report $report): ReportCase
    {
        return DB::transaction(function () use ($report): ReportCase {
            $case = ReportCase::query()
                ->where('subject_app_user_id', $report->reported_app_user_id)
                ->open()
                ->lockForUpdate()
                ->first();

            $case === null
                ? $case = $this->open($report)
                : $this->merge($case, $report);

            $report->forceFill(['report_case_id' => $case->id])->save();

            $this->refreshCounters($case);

            return $case;
        });
    }

    private function open(Report $report): ReportCase
    {
        $severity = $report->severity ?? Severity::Low;

        return ReportCase::query()->create([
            'case_number' => $this->nextCaseNumber(),
            'subject_app_user_id' => $report->reported_app_user_id,
            'status' => CaseStatus::New,
            'severity' => $severity,
            'reports_count' => 0,
            'distinct_reporters_count' => 0,
            'risk_score_at_open' => $report->reported?->risk_score ?? 0,
            // The deadline comes from severity, so triage is by harm potential
            // rather than arrival order.
            'sla_due_at' => now()->addHours($severity->slaHours()),
        ]);
    }

    /**
     * A new report can only raise a case's severity, never lower it.
     *
     * A spam complaint arriving after a violence report must not relax the
     * deadline on the violence report.
     */
    private function merge(ReportCase $case, Report $report): void
    {
        $incoming = $report->severity ?? Severity::Low;

        if ($incoming->weight() <= $case->severity->weight()) {
            return;
        }

        $case->forceFill([
            'severity' => $incoming,
            'sla_due_at' => min(
                $case->sla_due_at,
                now()->addHours($incoming->slaHours()),
            ),
        ])->save();
    }

    private function refreshCounters(ReportCase $case): void
    {
        $counts = DB::table('reports')
            ->where('report_case_id', $case->id)
            ->selectRaw('COUNT(*) as total, COUNT(DISTINCT reporter_app_user_id) as reporters')
            ->first();

        $case->forceFill([
            'reports_count' => (int) $counts->total,
            'distinct_reporters_count' => (int) $counts->reporters,
        ])->save();
    }

    private function nextCaseNumber(): string
    {
        $year = now()->year;

        $last = ReportCase::query()
            ->where('case_number', 'like', "VEY-{$year}-%")
            ->orderByDesc('id')
            ->value('case_number');

        $sequence = $last !== null
            ? ((int) substr($last, -6)) + 1
            : 1;

        return sprintf('VEY-%d-%06d', $year, $sequence);
    }
}
