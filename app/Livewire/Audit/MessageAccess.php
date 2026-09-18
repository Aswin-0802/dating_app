<?php

declare(strict_types=1);

namespace App\Livewire\Audit;

use App\Livewire\Concerns\WithDataTable;
use App\Models\MessageAccessLog;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Who read whose messages, and why.
 *
 * The permission gate makes reading message content deliberate; this screen is
 * what makes it reviewable. Without somewhere the reveals are visible, the gate
 * is a speed bump that nobody ever checks.
 */
class MessageAccess extends Component
{
    use WithDataTable;

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $logs = $this->applySort($this->baseQuery())
            ->with(['user', 'conversation.match.userOne', 'conversation.match.userTwo', 'reportCase'])
            ->paginate($this->perPage);

        return view('livewire.audit.message-access', [
            'logs' => $logs,
            'last30Days' => MessageAccessLog::query()->where('created_at', '>=', now()->subDays(30))->count(),
            'byReason' => MessageAccessLog::query()
                ->selectRaw('reason_code, COUNT(*) as c')
                ->groupBy('reason_code')
                ->pluck('c', 'reason_code')
                ->all(),
        ])->layout('components.layouts.admin', [
            'title' => 'Message access log',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Audit log', 'href' => route('admin.audit.index')],
                ['label' => 'Message access'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['created_at', 'messages_revealed'];
    }

    protected function baseQuery(): Builder
    {
        return MessageAccessLog::query()
            ->when($this->search !== '', fn (Builder $q) => $q->where(function (Builder $inner): void {
                $inner->where('justification', 'like', "%{$this->search}%")
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$this->search}%"));
            }));
    }
}
