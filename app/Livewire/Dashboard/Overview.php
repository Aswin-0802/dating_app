<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\Analytics\MetricRollup;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Overview extends Component
{
    public function render(): View
    {
        $metrics = app(MetricRollup::class);

        return view('livewire.dashboard.overview', [
            'overview' => $metrics->overview(),
            'funnel' => $metrics->funnel(),
            'cityBalance' => $metrics->cityBalance(8),
            'concentration' => $metrics->attentionConcentration(),
            'retention' => $metrics->retentionByVerification(),
            'coldStart' => $metrics->coldStart(),
            'signups' => $metrics->signupTrend(90),
            'safety' => $metrics->safetyTrend(60),
        ])->layout('components.layouts.admin', [
            'title' => 'Overview',
        ]);
    }

    public function refreshMetrics(): void
    {
        app(MetricRollup::class)->flush();

        session()->flash('status', 'Dashboard figures recalculated.');
    }

    /** The headline figures and the funnel as a CSV, for a report or a board pack. */
    public function export(): StreamedResponse
    {
        $metrics = app(MetricRollup::class);
        $overview = $metrics->overview();
        $funnel = $metrics->funnel();

        $filename = str(Branding::name())->slug().'-dashboard-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($overview, $funnel): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Metric', 'Value']);
            foreach ([
                'Total members' => $overview['total_members'],
                'Active members' => $overview['active_members'],
                'Active in last 30 days' => $overview['mau'],
                'Verified members' => $overview['verified_members'],
                'Verification coverage (%)' => $overview['verification_coverage'],
                'Matches' => $overview['matches'],
                'Matches with a message (%)' => $overview['match_to_message'],
                'Messages that got a reply (%)' => $overview['message_to_reply'],
                'Reports per 1,000 matches' => $overview['report_rate_per_1k_matches'],
                'Open cases' => $overview['open_cases'],
                'Awaiting verification' => $overview['open_verifications'],
                'Enforcement in force' => $overview['active_bans'],
            ] as $label => $value) {
                fputcsv($out, [$label, $value]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Funnel step', 'Members', 'Share of sign-ups (%)']);
            foreach ($funnel as $stage) {
                fputcsv($out, [$stage['step'], $stage['count'], $stage['rate']]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
