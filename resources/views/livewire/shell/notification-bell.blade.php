<div>
    <button
        type="button"
        wire:click="toggle"
        class="relative inline-flex size-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
        <x-ui.icon name="bell" size="sm" />

        @if ($unreadCount > 0)
            <span class="tabular absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-primary-foreground">
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif

        <span class="sr-only">Notifications{{ $unreadCount ? " ({$unreadCount} unread)" : '' }}</span>
    </button>

    {{-- Teleported so the drawer is never clipped by the sticky header. --}}
    <template x-teleport="body">
        <div x-data="{ show: @entangle('open') }">
            <div x-show="show" x-cloak class="fixed inset-0 z-50">
                <div
                    x-show="show"
                    x-transition.opacity
                    @click="show = false"
                    class="absolute inset-0 bg-black/50"
                ></div>

                <div
                    x-show="show"
                    x-transition:enter="transition ease-in-out duration-300"
                    x-transition:enter-start="translate-x-full"
                    x-transition:enter-end="translate-x-0"
                    x-transition:leave="transition ease-in-out duration-200"
                    x-transition:leave-start="translate-x-0"
                    x-transition:leave-end="translate-x-full"
                    @keydown.escape.window="show = false"
                    role="dialog"
                    aria-modal="true"
                    class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col border-l border-border bg-card shadow-2xl"
                >
                    <div class="flex shrink-0 items-center justify-between gap-3 border-b border-border px-5 py-4">
                        <h2 class="text-base font-semibold">Notifications</h2>

                        <div class="flex items-center gap-1">
                            @if ($unreadCount > 0)
                                <x-ui.button variant="ghost" size="sm" wire:click="markAllAsRead">
                                    Mark all read
                                </x-ui.button>
                            @endif

                            <button
                                type="button"
                                @click="show = false"
                                class="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            >
                                <x-ui.icon name="x-mark" size="sm" />
                                <span class="sr-only">Close</span>
                            </button>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto">
                        @forelse ($groups as $label => $notifications)
                            <p class="sticky top-0 z-10 bg-muted/80 px-5 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground backdrop-blur">
                                {{ $label }}
                            </p>

                            @foreach ($notifications as $notification)
                                @php $data = $notification->data; @endphp

                                <div @class([
                                    'flex gap-3 border-b border-border px-5 py-3 transition-colors hover:bg-muted/40',
                                    'bg-primary-subtle/40' => $notification->unread(),
                                ])>
                                    <div class="mt-0.5 shrink-0 text-muted-foreground">
                                        <x-ui.icon :name="$data['icon'] ?? 'info'" size="sm" />
                                    </div>

                                    <div class="min-w-0 flex-1 space-y-1">
                                        <p class="text-sm leading-snug text-foreground">
                                            {{ $data['message'] ?? 'Notification' }}
                                        </p>

                                        <p class="text-xs text-muted-foreground">
                                            {{ veyra_duration($notification->created_at) }} ago
                                        </p>

                                        @if (isset($data['action_url']))
                                            <div class="flex items-center gap-2 pt-1">
                                                <x-ui.button
                                                    size="xs"
                                                    variant="outline"
                                                    :href="$data['action_url']"
                                                >{{ $data['action_label'] ?? 'Review' }}</x-ui.button>

                                                @if ($notification->unread())
                                                    <x-ui.button
                                                        size="xs"
                                                        variant="ghost"
                                                        wire:click="markAsRead('{{ $notification->id }}')"
                                                    >Dismiss</x-ui.button>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        @empty
                            <x-ui.empty-state
                                icon="bell"
                                heading="No notifications"
                                description="Queue alerts, SLA breaches and escalations will appear here."
                            />
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
