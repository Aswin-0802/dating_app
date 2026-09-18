<?php

declare(strict_types=1);

namespace App\Livewire\Conversations;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Messaging\MessageRevealService;
use App\Support\Branding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Component;

class Show extends Component
{
    public Conversation $conversation;

    public bool $revealOpen = false;

    public string $reasonCode = '';

    public string $justification = '';

    /**
     * Revealed bodies live only in this component's state for this page view.
     *
     * Deliberately not persisted anywhere: navigating away re-locks the
     * conversation, and reading it again means justifying it again.
     *
     * @var array<int, string>
     */
    public array $revealed = [];

    public function mount(Conversation $conversation): void
    {
        $this->conversation = $conversation->load([
            'match.userOne.primaryPhoto',
            'match.userTwo.primaryPhoto',
            'participants',
        ]);
    }

    public function render(): View
    {
        return view('livewire.conversations.show', [
            'messages' => $this->messages(),
            'reasons' => config('veyra.privacy.reveal_reasons', []),
            'canReveal' => auth()->user()?->can('view_message_content') ?? false,
            'isRevealed' => $this->revealed !== [],
        ])->layout('components.layouts.admin', [
            'title' => 'Conversation',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Conversations', 'href' => route('admin.conversations.index')],
                ['label' => 'Thread'],
            ],
        ]);
    }

    /** @return Collection<int, Message> */
    private function messages(): Collection
    {
        return Message::query()
            ->where('conversation_id', $this->conversation->id)
            ->with('sender:id,display_name')
            ->orderBy('created_at')
            ->get();
    }

    public function reveal(): void
    {
        try {
            $messages = app(MessageRevealService::class)->reveal(
                conversation: $this->conversation,
                actor: auth()->user(),
                reasonCode: $this->reasonCode,
                justification: $this->justification,
            );
        } catch (AuthorizationException $e) {
            $this->addError('reveal', $e->getMessage());

            return;
        } catch (InvalidArgumentException $e) {
            $this->addError('justification', $e->getMessage());

            return;
        }

        $this->revealed = $messages
            ->mapWithKeys(fn (Message $m): array => [$m->id => (string) $m->getRawOriginal('body')])
            ->all();

        $this->revealOpen = false;

        session()->flash('status', 'Content revealed. This access has been logged against your name.');
    }

    public function relock(): void
    {
        $this->revealed = [];
        $this->reset(['reasonCode', 'justification']);
    }
}
