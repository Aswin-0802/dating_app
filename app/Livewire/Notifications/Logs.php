<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\WithDataTable;
use App\Models\PushLog;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

class Logs extends Component
{
    use WithDataTable;

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $logs = $this->applySort($this->baseQuery())
            ->with(['appUser', 'campaign'])
            ->paginate($this->perPage);

        return view('livewire.notifications.logs', [
            'logs' => $logs,
            'breakdown' => $this->breakdown(),
        ])->layout('components.layouts.admin', [
            'title' => 'Delivery logs',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Notifications', 'href' => route('admin.notifications.campaigns')],
                ['label' => 'Delivery logs'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['created_at', 'sent_at'];
    }

    protected function baseQuery(): Builder
    {
        return PushLog::query()
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn (Builder $q) => $q->whereHas(
                'appUser',
                fn (Builder $u) => $u->search($this->search),
            ));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Delivery outcomes across the whole log.
     *
     * A rising failure rate usually means stale push tokens rather than a
     * content problem, and it is invisible from campaign-level numbers alone.
     *
     * @return array<string, int>
     */
    private function breakdown(): array
    {
        return DB::table('push_logs')
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();
    }
}
