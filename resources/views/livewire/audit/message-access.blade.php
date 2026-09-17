<div class="space-y-4 md:space-y-6">

    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
        <x-ui.icon name="lock" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
        <div class="min-w-0 text-sm">
            <p class="font-medium">Every reveal, permanently</p>
            <p class="mt-0.5 text-muted-foreground">
                The permission gate makes reading message content deliberate. This screen is
                what makes it reviewable — without somewhere the reveals are visible, the gate
                is a speed bump nobody ever checks. These rows cannot be edited or deleted.
            </p>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        <x-ui.stat-card label="Reveals, last 30 days" :value="veyra_number($last30Days)" icon="eye" />

        @foreach (array_slice($byReason, 0, 3, true) as $reason => $count)
            <x-ui.stat-card
                :label="config('veyra.privacy.reveal_reasons.'.$reason, $reason)"
                :value="veyra_number($count)"
                icon="document"
            />
        @endforeach
    </div>

    <x-ui.table :density="$density">
        <x-slot:toolbar>
            <div class="relative min-w-0 flex-1 sm:max-w-sm">
                <x-ui.icon name="search" size="sm"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search staff or justification"
                    class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground">
            </div>
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head sortable field="created_at" :direction="$this->sortDirectionFor('created_at')" width="170px">
                    When
                </x-ui.table.head>
                <x-ui.table.head>Who</x-ui.table.head>
                <x-ui.table.head>Conversation</x-ui.table.head>
                <x-ui.table.head>Reason</x-ui.table.head>
                <x-ui.table.head>Justification</x-ui.table.head>
                <x-ui.table.head sortable field="messages_revealed" align="right" :direction="$this->sortDirectionFor('messages_revealed')">
                    Messages
                </x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($logs as $log)
                @php $match = $log->conversation?->match; @endphp

                <x-ui.table.row>
                    <x-ui.table.cell muted>{{ veyra_datetime($log->created_at) }}</x-ui.table.cell>

                    <x-ui.table.cell>
                        <div class="flex min-w-0 items-center gap-2">
                            <x-ui.avatar :name="$log->user?->name" size="xs" />
                            <span class="truncate text-sm">{{ $log->user?->name ?? 'Deleted staff' }}</span>
                        </div>
                    </x-ui.table.cell>

                    <x-ui.table.cell muted>
                        @if ($match)
                            <span class="text-xs">
                                {{ $match->userOne?->display_name }} &amp; {{ $match->userTwo?->display_name }}
                            </span>
                        @else
                            —
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-ui.badge variant="muted">{{ $log->reasonLabel() }}</x-ui.badge>
                    </x-ui.table.cell>

                    <x-ui.table.cell wrap>
                        <span class="text-sm text-muted-foreground">{{ $log->justification }}</span>
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>{{ $log->messages_revealed }}</x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state
                            icon="lock"
                            heading="No message content has been read"
                            description="Nobody has revealed a conversation. That is the expected state."
                        />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$logs" :per-page="$perPage" :per-page-options="$this->perPageOptions()" />
        </x-slot:footer>
    </x-ui.table>
</div>
