<div class="space-y-4 md:space-y-6">

    {{-- Said plainly, because a moderator arriving here needs to know what this
         screen will and will not show them. --}}
    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
        <x-ui.icon name="lock" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
        <div class="min-w-0 text-sm">
            <p class="font-medium">Metadata only</p>
            <p class="mt-0.5 text-muted-foreground">
                This list never shows message content. Reading a thread requires a reason
                and a written justification, and is recorded permanently.
            </p>
        </div>
    </div>

    <x-ui.table :density="$density">
        <x-slot:toolbar>
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1 sm:max-w-xs">
                    <x-ui.icon name="search" size="sm"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search by participant"
                        class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground"
                    >
                </div>

                <x-ui.select size="sm" placeholder="All threads" wire:model.live="flagged"
                    :options="['yes' => 'Flagged only', 'contact' => 'Contact sharing']" class="w-44" />

                <x-ui.select size="sm" placeholder="Any status" wire:model.live="status"
                    :options="['open' => 'Open', 'closed' => 'Closed', 'frozen' => 'Frozen']" class="w-36" />
            </div>

            <p class="text-xs text-muted-foreground">
                {{ platform_number($flaggedTotal) }} flagged
            </p>
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head>Participants</x-ui.table.head>
                <x-ui.table.head sortable field="messages_count" align="right" :direction="$this->sortDirectionFor('messages_count')">
                    Messages
                </x-ui.table.head>
                <x-ui.table.head align="right">Flagged</x-ui.table.head>
                <x-ui.table.head align="right">Contact shares</x-ui.table.head>
                <x-ui.table.head sortable field="started_at" align="right" :direction="$this->sortDirectionFor('started_at')">
                    Started
                </x-ui.table.head>
                <x-ui.table.head sortable field="last_message_at" align="right" :direction="$this->sortDirectionFor('last_message_at')">
                    Last message
                </x-ui.table.head>
                <x-ui.table.head align="right" width="90px"><span class="sr-only">Open</span></x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($conversations as $conversation)
                @php
                    $one = $conversation->match?->userOne;
                    $two = $conversation->match?->userTwo;
                @endphp

                <x-ui.table.row :tint="$conversation->is_flagged ? 'border-l-2 border-l-destructive' : ''">
                    <x-ui.table.cell>
                        <div class="flex items-center gap-2">
                            <div class="flex -space-x-2">
                                <x-ui.avatar :src="$one?->primaryPhoto?->thumb_url" :name="$one?->display_name" size="sm"
                                    class="ring-2 ring-card" />
                                <x-ui.avatar :src="$two?->primaryPhoto?->thumb_url" :name="$two?->display_name" size="sm"
                                    class="ring-2 ring-card" />
                            </div>
                            <span class="min-w-0 truncate text-sm">
                                {{ $one?->display_name ?? '—' }}
                                <span class="text-muted-foreground">&amp;</span>
                                {{ $two?->display_name ?? '—' }}
                            </span>
                        </div>
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>{{ $conversation->messages_count }}</x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>
                        @if ($conversation->flagged_messages_count > 0)
                            <span class="font-medium text-destructive-subtle-foreground">
                                {{ $conversation->flagged_messages_count }}
                            </span>
                        @else
                            <span class="text-muted-foreground">—</span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>
                        @if ($conversation->contact_messages_count > 0)
                            <span class="font-medium text-warning-subtle-foreground">
                                {{ $conversation->contact_messages_count }}
                            </span>
                        @else
                            <span class="text-muted-foreground">—</span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" muted>{{ platform_date($conversation->started_at) }}</x-ui.table.cell>

                    <x-ui.table.cell align="right" muted>
                        {{ $conversation->last_message_at ? platform_duration($conversation->last_message_at).' ago' : '—' }}
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        <x-ui.button size="xs" variant="outline" :href="route('admin.conversations.show', $conversation)">
                            Open
                        </x-ui.button>
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty-state icon="chat" heading="No conversations match this view" />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$conversations" :per-page="$perPage" :per-page-options="$this->perPageOptions()" />
        </x-slot:footer>
    </x-ui.table>
</div>
