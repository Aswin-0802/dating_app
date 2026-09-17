<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\Analytics\MetricRollup;
use Illuminate\Contracts\View\View;
use Livewire\Component;

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

        session()->flash('status', 'Metrics recalculated.');
    }
}
