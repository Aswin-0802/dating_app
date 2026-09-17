<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Staff notification centre: bell with an unread count, opening a right drawer.
 *
 * A drawer rather than a dropdown, because items carry inline actions ("Review
 * now", "Assign to me") that need room to breathe — a 320px dropdown turns them
 * into a cramped list nobody acts on.
 */
class NotificationBell extends Component
{
    public bool $open = false;

    public function render(): View
    {
        return view('livewire.shell.notification-bell', [
            'unreadCount' => $this->unreadCount(),
            'groups' => $this->open ? $this->groupedNotifications() : collect(),
        ]);
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    #[On('notification-received')]
    public function refreshCount(): void
    {
        // The count re-reads on the next render; no extra work needed here.
    }

    public function markAsRead(string $id): void
    {
        auth()->user()?->notifications()->where('id', $id)->first()?->markAsRead();
    }

    public function markAllAsRead(): void
    {
        auth()->user()?->unreadNotifications->markAsRead();
    }

    private function unreadCount(): int
    {
        return auth()->user()?->unreadNotifications()->count() ?? 0;
    }

    /**
     * Today / Earlier, because "3 days ago" mixed into today's queue alerts is
     * how a breaching SLA notice gets scrolled past.
     *
     * @return Collection<string, Collection<int, mixed>>
     */
    private function groupedNotifications(): Collection
    {
        return auth()->user()
            ?->notifications()
            ->latest()
            ->limit(30)
            ->get()
            ->groupBy(fn ($notification): string => $notification->created_at->isToday() ? 'Today' : 'Earlier')
            ?? collect();
    }
}
