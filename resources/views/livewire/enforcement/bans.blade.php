@php use App\Enums\BanType; @endphp

<div class="space-y-4 md:space-y-6">

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        <x-ui.stat-card label="In force" :value="platform_number($counts['active'])" icon="ban" />
        <x-ui.stat-card label="Shadow bans" :value="platform_number($counts['shadow'])" icon="eye-off"
            :href="route('admin.enforcement.shadow-reviews')" hint="Each carries a review date" />
        <x-ui.stat-card label="Suspensions" :value="platform_number($counts['suspended'])" icon="pause-circle" />
        <x-ui.stat-card label="Permanent bans" :value="platform_number($counts['permanent'])" icon="x-circle" />
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

                <x-ui.select size="sm" placeholder="Any type" wire:model.live="type"
                    :options="BanType::labels()" class="w-44" />
            </div>

            <div class="flex items-center gap-1">
                @foreach (['active' => 'In force', 'expiring' => 'Expiring soon', 'lifted' => 'Lifted', 'all' => 'All'] as $key => $label)
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
                <x-ui.table.head>Member</x-ui.table.head>
                <x-ui.table.head>Type</x-ui.table.head>
                <x-ui.table.head>Reason</x-ui.table.head>
                <x-ui.table.head>Issued by</x-ui.table.head>
                <x-ui.table.head sortable field="starts_at" :direction="$this->sortDirectionFor('starts_at')">Since</x-ui.table.head>
                <x-ui.table.head sortable field="expires_at" :direction="$this->sortDirectionFor('expires_at')">Expires</x-ui.table.head>
                <x-ui.table.head align="right" width="80px"><span class="sr-only">Actions</span></x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($bans as $ban)
                <x-ui.table.row :tint="$ban->lifted_at ? 'opacity-60' : ''">
                    <x-ui.table.cell>
                        <x-platform.user-cell
                            :name="$ban->appUser?->display_name"
                            :photo="$ban->appUser?->primaryPhoto?->thumb_url"
                            :meta="$ban->appUser?->city?->name"
                            :href="$ban->appUser ? route('admin.users.show', $ban->appUser) : null"
                        />
                    </x-ui.table.cell>

                    <x-ui.table.cell><x-platform.status-badge :status="$ban->type" /></x-ui.table.cell>

                    <x-ui.table.cell>
                        <span class="text-sm">{{ $ban->reason_code?->label() ?? '—' }}</span>
                    </x-ui.table.cell>

                    <x-ui.table.cell muted>{{ $ban->issuedBy?->name ?? 'Automated rule' }}</x-ui.table.cell>

                    <x-ui.table.cell muted>{{ platform_date($ban->starts_at) }}</x-ui.table.cell>

                    <x-ui.table.cell>
                        @if ($ban->lifted_at)
                            <span class="text-xs text-muted-foreground">
                                Lifted {{ platform_date($ban->lifted_at) }}
                            </span>
                        @elseif ($ban->expires_at)
                            <span class="text-sm">{{ platform_date($ban->expires_at) }}</span>
                        @else
                            <x-ui.badge variant="destructive" size="sm">No expiry</x-ui.badge>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        @if (! $ban->lifted_at)
                            @can('lift_enforcement')
                                <x-ui.button size="xs" variant="outline" wire:click="lift({{ $ban->id }})"
                                    wire:confirm="Lift this {{ strtolower($ban->type->label()) }}?">Lift</x-ui.button>
                            @endcan
                        @endif
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty-state icon="check-circle" heading="No enforcement matches this view" />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$bans" :per-page="$perPage" :per-page-options="$this->perPageOptions()" />
        </x-slot:footer>
    </x-ui.table>
</div>
