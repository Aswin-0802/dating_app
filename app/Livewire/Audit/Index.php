<?php

declare(strict_types=1);

namespace App\Livewire\Audit;

use App\Livewire\Concerns\WithDataTable;
use App\Models\ActivityLog;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use WithDataTable;

    #[Url(except: '')]
    public string $module = '';

    #[Url(except: '')]
    public string $actor = '';

    #[Url(except: '')]
    public string $sensitive = '';

    public ?int $expanded = null;

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $logs = $this->applySort($this->baseQuery())
            ->with('user')
            ->paginate($this->perPage);

        return view('livewire.audit.index', [
            'logs' => $logs,
            'modules' => DB::table('activity_logs')->distinct()->orderBy('module')->pluck('module', 'module')->all(),
            'actors' => DB::table('activity_logs')
                ->whereNotNull('actor_name')
                ->distinct()
                ->orderBy('actor_name')
                ->pluck('actor_name', 'user_id')
                ->all(),
        ])->layout('components.layouts.admin', [
            'title' => 'Audit log',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Audit log'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['created_at', 'module', 'action'];
    }

    protected function baseQuery(): Builder
    {
        $query = ActivityLog::query()
            ->when($this->module !== '', fn (Builder $q) => $q->where('module', $this->module))
            ->when($this->actor !== '', fn (Builder $q) => $q->where('user_id', $this->actor))
            ->when($this->sensitive === 'yes', fn (Builder $q) => $q->sensitive())
            ->when($this->search !== '', fn (Builder $q) => $q->where(function (Builder $inner): void {
                $inner->where('description', 'like', "%{$this->search}%")
                    ->orWhere('actor_name', 'like', "%{$this->search}%");
            }));

        /*
         * A moderator sees only their own entries.
         *
         * The log exists to make decisions reviewable by people with oversight,
         * not to let colleagues browse each other's work.
         */
        if (! auth()->user()?->can('export_audit_logs')) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['module', 'actor', 'sensitive', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function toggleExpanded(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }
}
