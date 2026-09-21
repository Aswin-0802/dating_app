<div class="space-y-4 md:space-y-6">

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        <x-ui.stat-card label="Total matches" :value="platform_compact_number($stats['total'])" icon="heart" />
        <x-ui.stat-card
            label="Never messaged"
            :value="platform_percent($stats['silent_rate'])"
            icon="chat"
            hint="A match nobody speaks in is indistinguishable from no match"
        />
        <x-ui.stat-card label="Got a first message" :value="platform_compact_number($stats['messaged'])" icon="inbox" />
        <x-ui.stat-card
            label="Reply rate"
            :value="platform_percent($stats['reply_rate'])"
            icon="arrow-path"
            hint="Of matches where somebody opened"
        />
    </div>

    <x-ui.table :density="$density">
        <x-slot:toolbar>
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1 sm:max-w-xs">
                    <x-ui.icon name="search" size="sm"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by member"
                        class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground">
                </div>

                <x-ui.select size="sm" placeholder="Any status" wire:model.live="status"
                    :options="['active' => 'Active', 'unmatched' => 'Unmatched', 'blocked' => 'Blocked', 'expired' => 'Expired']"
                    class="w-40" />

                <x-ui.select size="sm" placeholder="Any engagement" wire:model.live="engagement"
                    :options="['silent' => 'Never messaged', 'one_sided' => 'No reply', 'replied' => 'Two-way']"
                    class="w-44" />
            </div>
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head>Pair</x-ui.table.head>
                <x-ui.table.head>Location</x-ui.table.head>
                <x-ui.table.head>Status</x-ui.table.head>
                <x-ui.table.head sortable field="matched_at" align="right" :direction="$this->sortDirectionFor('matched_at')">
                    Matched
                </x-ui.table.head>
                <x-ui.table.head sortable field="messages_count" align="right" :direction="$this->sortDirectionFor('messages_count')">
                    Messages
                </x-ui.table.head>
                <x-ui.table.head>Engagement</x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($matches as $match)
                <x-ui.table.row>
                    <x-ui.table.cell>
                        <div class="flex items-center gap-2">
                            <div class="flex -space-x-2">
                                <x-ui.avatar :src="$match->userOne?->primaryPhoto?->thumb_url"
                                    :name="$match->userOne?->display_name" size="sm" class="ring-2 ring-card" />
                                <x-ui.avatar :src="$match->userTwo?->primaryPhoto?->thumb_url"
                                    :name="$match->userTwo?->display_name" size="sm" class="ring-2 ring-card" />
                            </div>
                            <span class="min-w-0 truncate text-sm">
                                <a href="{{ $match->userOne ? route('admin.users.show', $match->userOne) : '#' }}"
                                    wire:navigate class="hover:text-primary">{{ $match->userOne?->display_name }}</a>
                                <span class="text-muted-foreground">&amp;</span>
                                <a href="{{ $match->userTwo ? route('admin.users.show', $match->userTwo) : '#' }}"
                                    wire:navigate class="hover:text-primary">{{ $match->userTwo?->display_name }}</a>
                            </span>
                        </div>
                    </x-ui.table.cell>

                    <x-ui.table.cell muted>
                        @if ($match->same_city)
                            {{ $match->userOne?->city?->name ?? '—' }}
                        @else
                            <span class="text-xs">
                                {{ $match->userOne?->city?->name ?? '—' }} → {{ $match->userTwo?->city?->name ?? '—' }}
                            </span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-ui.badge :variant="$match->status === 'active' ? 'success' : 'muted'">
                            {{ ucfirst($match->status) }}
                        </x-ui.badge>
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" muted>{{ platform_date($match->matched_at) }}</x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>{{ $match->messages_count }}</x-ui.table.cell>

                    <x-ui.table.cell>
                        @if ($match->first_reply_at)
                            <x-ui.badge variant="success" size="sm">Two-way</x-ui.badge>
                        @elseif ($match->first_message_at)
                            <x-ui.badge variant="warning" size="sm">No reply</x-ui.badge>
                        @else
                            <x-ui.badge variant="muted" size="sm">Silent</x-ui.badge>
                        @endif
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state icon="heart" heading="No matches match this view" />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$matches" :per-page="$perPage" :per-page-options="$this->perPageOptions()" />
        </x-slot:footer>
    </x-ui.table>
</div>
