<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Livewire\Member\Concerns\HandlesSafety;
use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Models\AppUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Members\MessageSender;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The member's own conversations.
 *
 * This is the one place in the product where message bodies are rendered
 * without a reveal: the reader is a participant, which is the only thing that
 * entitles anybody to a conversation. Every query here is scoped to the signed-
 * in member's participations, and a thread is refused outright otherwise.
 */
class Messages extends Component
{
    use HandlesSafety;
    use InteractsWithMember;

    #[Locked]
    public ?Conversation $conversation = null;

    public string $body = '';

    public function mount(?Conversation $conversation = null): void
    {
        if ($conversation !== null) {
            abort_unless($conversation->hasParticipant($this->member()), 404);
            $this->conversation = $conversation;
            app(MessageSender::class)->markRead($conversation, $this->member());
        }
    }

    public function render(): View
    {
        $me = $this->member();
        $thread = $this->conversation?->loadMissing('match.userOne.primaryPhoto', 'match.userTwo.primaryPhoto');
        $other = $thread?->match?->otherParty($me);

        return view('livewire.member.messages', [
            'me' => $me,
            'conversations' => $this->conversations($me),
            'thread' => $thread,
            'other' => $other,
            'messages' => $thread ? $this->messagesFor($thread) : collect(),
            'categories' => $this->reportCategories(),
        ])->layout('components.layouts.member', ['title' => $other ? $other->display_name : 'Messages', 'wide' => true]);
    }

    public function send(MessageSender $sender): void
    {
        abort_if($this->conversation === null, 404);

        $this->validate(['body' => ['required', 'string', 'max:2000']], ['body.required' => 'Write something first.']);

        $sender->send($this->conversation, $this->member(), trim($this->body));

        $this->body = '';
        $this->conversation->refresh();
        $this->dispatch('message-sent');
    }

    /** Called by wire:poll, so replies arrive without a reload. */
    public function poll(MessageSender $sender): void
    {
        if ($this->conversation !== null) {
            $sender->markRead($this->conversation, $this->member());
        }
    }

    /**
     * SafetyActions::block() closes the thread now, so this only has to move
     * the reader off a page they can no longer open.
     */
    protected function afterSafetyAction(bool $blocked): void
    {
        if ($blocked && $this->conversation !== null) {
            $this->redirectRoute('member.messages', navigate: true);
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    private function conversations(AppUser $me): Collection
    {
        $conversations = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('app_users.id', $me->id))
            ->where('status', 'open')
            ->where('messages_count', '>', 0)
            ->with(['match.userOne.primaryPhoto', 'match.userTwo.primaryPhoto'])
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get();

        $ids = $conversations->pluck('id');

        // Latest message per conversation, and unread counts, in two queries
        // rather than two per row.
        $latest = Message::query()
            ->whereIn('id', Message::query()
                ->selectRaw('MAX(id)')
                ->whereIn('conversation_id', $ids)
                ->groupBy('conversation_id'))
            ->get()
            ->keyBy('conversation_id');

        $unread = DB::table('messages')
            ->whereIn('conversation_id', $ids)
            ->where('sender_app_user_id', '!=', $me->id)
            ->whereNull('read_at')
            ->whereNull('deleted_at')
            ->groupBy('conversation_id')
            ->selectRaw('conversation_id, COUNT(*) as c')
            ->pluck('c', 'conversation_id');

        return $conversations->map(fn (Conversation $c): array => [
            'conversation' => $c,
            'other' => $c->match?->otherParty($me),
            'latest' => $latest->get($c->id),
            'unread' => (int) ($unread[$c->id] ?? 0),
        ]);
    }

    /** @return Collection<int, Message> */
    private function messagesFor(Conversation $conversation): Collection
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->limit(150)
            ->get()
            ->reverse()
            ->values();
    }
}
