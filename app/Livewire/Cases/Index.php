<?php

declare(strict_types=1);

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\Severity;
use App\Livewire\Concerns\WithDataTable;
use App\Models\ReportCase;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use WithDataTable;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $severity = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: 'open')]
    public string $view = 'open';

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $cases = $this->applySort($this->baseQuery())
            ->with(['subject.primaryPhoto', 'subject.city', 'claimedBy'])
            ->paginate($this->perPage);

        return view('livewire.cases.index', [
            'cases' => $cases,
            'stats' => $this->stats(),
        ])->layout('components.layouts.admin', [
            'title' => 'Cases',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Cases'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['sla_due_at', 'created_at', 'reports_count', 'severity', 'risk_score_at_open'];
    }

    /**
     * Deadline first, not creation date.
     *
     * Pure FIFO buries a critical case behind a day of spam complaints filed
     * earlier; the SLA window already encodes severity, so ordering by it is
     * ordering by harm potential.
     */
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
        return ReportCase::query()
            ->when($this->view === 'open', fn (Builder $q) => $q->open())
            ->when($this->view === 'breaching', fn (Builder $q) => $q->breachingSla())
            ->when($this->view === 'mine', fn (Builder $q) => $q->where('claimed_by', auth()->id()))
            ->when($this->view === 'unclaimed', fn (Builder $q) => $q->open()->whereNull('claimed_by'))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->severity !== '', fn (Builder $q) => $q->where('severity', $this->severity))
            ->when($this->category !== '', fn (Builder $q) => $q->whereHas(
                'reports',
                fn (Builder $r) => $r->where('category', $this->category),
            ))
            ->when($this->search !== '', fn (Builder $q) => $q->where(function (Builder $inner): void {
                $inner->where('case_number', 'like', "%{$this->search}%")
                    ->orWhereHas('subject', fn (Builder $u) => $u->search($this->search));
            }));
    }

    public function setView(string $view): void
    {
        $this->view = $view;
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'severity', 'category', 'search'], true)) {
            $this->resetPage();
        }
    }

    /** @return array<string, int> */
    private function stats(): array
    {
        return [
            'open' => ReportCase::query()->open()->count(),
            'breaching' => ReportCase::query()->breachingSla()->count(),
            'unclaimed' => ReportCase::query()->open()->whereNull('claimed_by')->count(),
            'critical' => ReportCase::query()->open()->where('severity', Severity::Critical->value)->count(),
        ];
    }

    /** @return array<string, string> */
    public function statusOptions(): array
    {
        return CaseStatus::labels();
    }
}
