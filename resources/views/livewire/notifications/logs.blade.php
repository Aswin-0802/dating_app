<div class="space-y-4 md:space-y-6">

    @php $total = max(1, array_sum($breakdown)); @endphp

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        @foreach (['delivered' => 'Delivered', 'opened' => 'Opened', 'failed' => 'Failed', 'queued' => 'Queued'] as $key => $label)
            <x-ui.stat-card
                :label="$label"
                :value="platform_compact_number($breakdown[$key] ?? 0)"
                :icon="match ($key) { 'delivered' => 'check-circle', 'opened' => 'eye', 'failed' => 'x-circle', default => 'clock' }"
                :hint="platform_percent(($breakdown[$key] ?? 0) / $total * 100, 1).' of all sends'"
                :invert-delta="$key === 'failed'"
            />
        @endforeach
    </div>

    @if (($breakdown['failed'] ?? 0) / $total > 0.05)
        <div class="flex items-start gap-3 rounded-xl border border-warning/40 bg-warning-subtle px-4 py-3">
            <x-ui.icon name="warning" size="sm" class="mt-0.5 shrink-0 text-warning-subtle-foreground" />
            <p class="min-w-0 text-sm text-warning-subtle-foreground">
                Over 5% of sends are failing. This is usually caused by out-of-date device tokens.
            </p>
        </div>
    @endif

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
                    :options="['queued' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'opened' => 'Opened', 'failed' => 'Failed']"
                    class="w-40" />
            </div>
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head sortable field="created_at" :direction="$this->sortDirectionFor('created_at')" width="170px">
                    When
                </x-ui.table.head>
                <x-ui.table.head>Member</x-ui.table.head>
                <x-ui.table.head>Campaign</x-ui.table.head>
                <x-ui.table.head wrap>Title</x-ui.table.head>
                <x-ui.table.head>Status</x-ui.table.head>
                <x-ui.table.head>Reason</x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($logs as $log)
                <x-ui.table.row>
                    <x-ui.table.cell muted>{{ platform_datetime($log->created_at) }}</x-ui.table.cell>

                    <x-ui.table.cell>
                        @if ($log->appUser)
                            <a href="{{ route('admin.users.show', $log->appUser) }}" wire:navigate
                                class="text-sm hover:text-primary">{{ $log->appUser->display_name }}</a>
                        @else
                            <span class="text-sm text-muted-foreground">Deleted account</span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell muted>
                        <span class="text-sm">{{ $log->campaign?->name ?? 'Transactional' }}</span>
                    </x-ui.table.cell>

                    <x-ui.table.cell wrap>
                        <span class="text-sm">{{ $log->title }}</span>
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $log->statusClasses() }}">
                            {{ ucfirst($log->status) }}
                        </span>
                    </x-ui.table.cell>

                    <x-ui.table.cell muted>
                        <span class="text-xs">{{ $log->failure_reason ?? '—' }}</span>
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="6"><x-ui.empty-state icon="inbox" heading="No deliveries match this view" /></td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$logs" :per-page="$perPage" :per-page-options="$this->perPageOptions()" />
        </x-slot:footer>
    </x-ui.table>
</div>
