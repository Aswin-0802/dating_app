<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\Analytics\MetricRollup;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class SafetyTrends extends Component
{
    public int $days = 60;

    public function render(): View
    {
        $metrics = app(MetricRollup::class);

        return view('livewire.dashboard.safety-trends', [
            'safety' => $metrics->safetyTrend($this->days),
            'overview' => $metrics->overview(),
            'byCategory' => $this->byCategory(),
            'byLadderStep' => $this->byLadderStep(),
        ])->layout('components.layouts.admin', [
            'title' => 'Safety trends',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Analytics'],
                ['label' => 'Safety trends'],
            ],
        ]);
    }

    /** @return array<string, int> */
    private function byCategory(): array
    {
        return DB::table('reports')
            ->selectRaw('category, COUNT(*) as c')
            ->groupBy('category')
            ->orderByDesc('c')
            ->pluck('c', 'category')
            ->all();
    }

    /** @return array<string, int> */
    private function byLadderStep(): array
    {
        return DB::table('moderation_actions')
            ->selectRaw('ladder_step, COUNT(*) as c')
            ->groupBy('ladder_step')
            ->orderByDesc('c')
            ->pluck('c', 'ladder_step')
            ->all();
    }
}
