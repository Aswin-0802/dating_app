<div class="space-y-4 md:space-y-6">

    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
        <x-ui.icon name="device" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
        <div class="min-w-0 text-sm">
            <p class="font-medium">Why shared devices matter</p>
            <p class="mt-0.5 text-muted-foreground">
                A permanently banned member signing up again almost always does so from the
                same phone. Clusters that include a banned account are the ones worth acting
                on. {{ platform_number($bannedDeviceCount) }} devices are currently blocked.
            </p>
        </div>
    </div>

    <x-ui.table :density="$density">
        <x-slot:toolbar>
            <p class="text-sm text-muted-foreground">
                Fingerprints seen on more than one account
            </p>

            <div class="flex items-center gap-1">
                @foreach (['banned_linked' => 'Linked to a ban', 'all' => 'All shared'] as $key => $label)
                    <button type="button" wire:click="setView('{{ $key }}')" @class([
                        'rounded-md px-2.5 py-1.5 text-xs font-medium transition-colors',
                        'bg-primary-subtle text-primary-subtle-foreground' => $view === $key,
                        'text-muted-foreground hover:bg-muted hover:text-foreground' => $view !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head>Device fingerprint</x-ui.table.head>
                <x-ui.table.head>Accounts on this device</x-ui.table.head>
                <x-ui.table.head align="right">Accounts</x-ui.table.head>
                <x-ui.table.head align="right">Banned</x-ui.table.head>
                <x-ui.table.head align="right">Last seen</x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($clusters as $cluster)
                <x-ui.table.row :tint="$cluster->banned_accounts > 0 ? 'border-l-2 border-l-destructive bg-destructive-subtle/30' : ''">
                    <x-ui.table.cell>
                        <span class="font-mono text-xs text-muted-foreground">
                            {{ substr($cluster->fingerprint_hash, 0, 16) }}…
                        </span>
                    </x-ui.table.cell>

                    <x-ui.table.cell wrap>
                        <span class="text-sm">{{ $cluster->members }}</span>
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>{{ $cluster->accounts }}</x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        @if ($cluster->banned_accounts > 0)
                            <x-ui.badge variant="solid-destructive">{{ $cluster->banned_accounts }}</x-ui.badge>
                        @else
                            <span class="text-muted-foreground">—</span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" muted>
                        {{ $cluster->last_seen_at ? platform_duration($cluster->last_seen_at).' ago' : '—' }}
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="5">
                        <x-ui.empty-state
                            icon="check-circle"
                            heading="No shared devices in this view"
                            description="Nothing links a banned account to another signup."
                        />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$clusters" :per-page="$perPage" :per-page-options="$this->perPageOptions()" />
        </x-slot:footer>
    </x-ui.table>
</div>
