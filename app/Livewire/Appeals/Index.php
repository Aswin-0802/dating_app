<?php

declare(strict_types=1);

namespace App\Livewire\Appeals;

use App\Enums\AppealStatus;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Appeal;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use WithDataTable;

    #[Url(except: 'open')]
    public string $view = 'open';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $appeals = $this->applySort($this->baseQuery())
            ->with(['appUser.primaryPhoto', 'ban', 'originalDecider', 'assignedTo'])
            ->paginate($this->perPage);

        return view('livewire.appeals.index', [
            'appeals' => $appeals,
            'stats' => $this->stats(),
        ])->layout('components.layouts.admin', [
            'title' => 'Appeals',
            'breadcrumbs' => [
                ['label' => 'Veyra', 'href' => route('admin.dashboard')],
                ['label' => 'Appeals'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['created_at', 'sla_due_at', 'decided_at'];
    }

    protected function defaultSortField(): string
    {
        return 'sla_due_at';
    }

    protected function defaultSortDirection(): string
    {
        return 'asc';
    }

    protected function baseQuery(): Builder
    {
        return Appeal::query()
            ->when($this->view === 'open', fn (Builder $q) => $q->open())
            ->when($this->view === 'breaching', fn (Builder $q) => $q->breachingSla())
            ->when($this->view === 'mine', fn (Builder $q) => $q->where('assigned_to_id', auth()->id()))
            // An appeal this user may not touch, because they made the original
            // call. Surfaced so the rule is visible rather than mysterious.
            ->when($this->view === 'conflicted', fn (Builder $q) => $q->where('original_decider_id', auth()->id()))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn (Builder $q) => $q->whereHas(
                'appUser',
                fn (Builder $u) => $u->search($this->search),
            ));
    }

    public function setView(string $view): void
    {
        $this->view = $view;
        $this->resetPage();
    }

    /** @return array<string, float|int> */
    private function stats(): array
    {
        $decided = Appeal::query()
            ->whereIn('status', [
                AppealStatus::Upheld->value,
                AppealStatus::Overturned->value,
                AppealStatus::PartiallyOverturned->value,
            ])->count();

        $overturned = Appeal::query()
            ->whereIn('status', [
                AppealStatus::Overturned->value,
                AppealStatus::PartiallyOverturned->value,
            ])->count();

        return [
            'open' => Appeal::query()->open()->count(),
            'breaching' => Appeal::query()->breachingSla()->count(),
            'decided' => $decided,
            // The single most useful number on this screen: a high overturn rate
            // means first-instance decisions are wrong, not that appeals work.
            'overturn_rate' => $decided > 0 ? round($overturned / $decided * 100, 1) : 0.0,
        ];
    }
}
