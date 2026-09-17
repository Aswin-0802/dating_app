<?php

declare(strict_types=1);

namespace App\Livewire\Conversations;

use App\Livewire\Concerns\WithDataTable;
use App\Models\Conversation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Conversation metadata only.
 *
 * This screen never renders message content, and cannot: Message::$hidden keeps
 * `body` out of anything that serialises a model. Moderators triage from shape
 * and signals — length, direction, flags, contact-sharing — which is enough for
 * most decisions and keeps the number of justified reveals down.
 */
class Index extends Component
{
    use WithDataTable;

    #[Url(except: '')]
    public string $flagged = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $conversations = $this->applySort($this->baseQuery())
            ->with(['match.userOne.primaryPhoto', 'match.userTwo.primaryPhoto'])
            ->withCount([
                'messages as flagged_messages_count' => fn (Builder $q) => $q->where('is_flagged', true),
                'messages as contact_messages_count' => fn (Builder $q) => $q->where('contains_contact_info', true),
            ])
            ->paginate($this->perPage);

        return view('livewire.conversations.index', [
            'conversations' => $conversations,
            'flaggedTotal' => Conversation::query()->flagged()->count(),
        ])->layout('components.layouts.admin', [
            'title' => 'Conversations',
            'breadcrumbs' => [
                ['label' => 'Veyra', 'href' => route('admin.dashboard')],
                ['label' => 'Conversations'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['last_message_at', 'messages_count', 'started_at', 'risk_score'];
    }

    protected function defaultSortField(): string
    {
        return 'last_message_at';
    }

    protected function baseQuery(): Builder
    {
        return Conversation::query()
            ->when($this->flagged === 'yes', fn (Builder $q) => $q->flagged())
            ->when($this->flagged === 'contact', fn (Builder $q) => $q->whereHas(
                'messages',
                fn (Builder $m) => $m->where('contains_contact_info', true),
            ))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn (Builder $q) => $q->whereHas(
                'participants',
                fn (Builder $p) => $p->search($this->search),
            ));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['flagged', 'status', 'search'], true)) {
            $this->resetPage();
        }
    }
}
