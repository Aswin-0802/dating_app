<div class="space-y-4 md:space-y-6">

    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
        <x-ui.icon name="info" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
        <div class="min-w-0 text-sm">
            <p class="font-medium">The quietest safety signal</p>
            <p class="mt-0.5 text-muted-foreground">
                Blocking takes one tap and files no report, so a member who makes people
                uncomfortable can accumulate a dozen blocks without ever reaching a queue.
                {{ veyra_number($totalBlocks) }} blocks recorded in total.
            </p>
        </div>
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

                <x-ui.select size="sm" label="" wire:model.live="threshold" :selected="$threshold"
                    :options="['3' => 'Blocked 3+ times', '5' => 'Blocked 5+ times', '8' => 'Blocked 8+ times', '15' => 'Blocked 15+ times']"
                    class="w-48" />
            </div>
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head>Member</x-ui.table.head>
                <x-ui.table.head>Status</x-ui.table.head>
                <x-ui.table.head>Risk</x-ui.table.head>
                <x-ui.table.head align="right">Blocked by</x-ui.table.head>
                <x-ui.table.head align="right">Joined</x-ui.table.head>
                <x-ui.table.head align="right" width="90px"><span class="sr-only">View</span></x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($members as $member)
                <x-ui.table.row :tint="$member->risk_band->rowClasses()">
                    <x-ui.table.cell>
                        <x-veyra.user-cell
                            :name="$member->display_name"
                            :age="$member->age"
                            :photo="$member->primaryPhoto?->thumb_url"
                            :meta="$member->city?->name"
                            :href="route('admin.users.show', $member)"
                        />
                    </x-ui.table.cell>

                    <x-ui.table.cell><x-veyra.status-badge :status="$member->account_status" /></x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-veyra.risk-badge :score="$member->risk_score" :band="$member->risk_band" />
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>
                        <span @class([
                            'font-medium',
                            'text-destructive-subtle-foreground' => $member->blocks_received_count >= 8,
                            'text-warning-subtle-foreground' => $member->blocks_received_count >= 5 && $member->blocks_received_count < 8,
                        ])>{{ $member->blocks_received_count }}</span>
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" muted>{{ veyra_date($member->created_at) }}</x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        <x-ui.button size="xs" variant="outline" :href="route('admin.users.show', $member)">View</x-ui.button>
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state
                            icon="check-circle"
                            heading="Nobody is blocked this often"
                            description="Lower the threshold to see members with fewer blocks."
                        />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$members" :per-page="$perPage" :per-page-options="$this->perPageOptions()" />
        </x-slot:footer>
    </x-ui.table>
</div>
