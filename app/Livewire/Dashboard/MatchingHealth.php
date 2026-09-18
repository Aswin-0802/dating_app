<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\Analytics\MetricRollup;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class MatchingHealth extends Component
{
    public function render(): View
    {
        $metrics = app(MetricRollup::class);

        return view('livewire.dashboard.matching-health', [
            'overview' => $metrics->overview(),
            'cityBalance' => $metrics->cityBalance(20),
            'concentration' => $metrics->attentionConcentration(),
            'coldStart' => $metrics->coldStart(),
        ])->layout('components.layouts.admin', [
            'title' => 'Matching health',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Analytics'],
                ['label' => 'Matching health'],
            ],
        ]);
    }
}
