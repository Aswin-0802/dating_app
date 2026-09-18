<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\Analytics\MetricRollup;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Retention extends Component
{
    public function render(): View
    {
        $metrics = app(MetricRollup::class);

        return view('livewire.dashboard.retention', [
            'retention' => $metrics->retentionByVerification(),
            'signups' => $metrics->signupTrend(180),
            'overview' => $metrics->overview(),
        ])->layout('components.layouts.admin', [
            'title' => 'Retention',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Analytics'],
                ['label' => 'Retention'],
            ],
        ]);
    }
}
