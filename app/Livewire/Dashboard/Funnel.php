<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\Analytics\MetricRollup;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Funnel extends Component
{
    public function render(): View
    {
        $metrics = app(MetricRollup::class);

        return view('livewire.dashboard.funnel', [
            'funnel' => $metrics->funnel(),
            'coldStart' => $metrics->coldStart(),
        ])->layout('components.layouts.admin', [
            'title' => 'Funnel',
            'breadcrumbs' => [
                ['label' => 'Veyra', 'href' => route('admin.dashboard')],
                ['label' => 'Analytics'],
                ['label' => 'Funnel'],
            ],
        ]);
    }
}
