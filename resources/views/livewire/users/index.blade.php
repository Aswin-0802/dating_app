@php
    use App\Enums\AccountStatus;
    use App\Enums\Gender;
    use App\Enums\RiskBand;
    use App\Enums\VerificationStatus;

    $activeFilters = $this->activeFilters();
@endphp

<div>
    <x-ui.table :density="$density">

        {{-- ---- toolbar ------------------------------------------------ --}}
        <x-slot:toolbar>
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1 sm:max-w-xs">
                    <x-ui.icon
                        name="search"
                        size="sm"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Name, email, phone or user ID"
                        class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground"
                    >
                </div>

                <x-ui.button
                    variant="outline"
                    size="sm"
                    icon="filter"
                    wire:click="$toggle('filtersOpen')"
                >
                    Filters
                    @if ($activeFilters)
                        <span class="tabular ml-0.5 rounded-full bg-primary px-1.5 text-[10px] font-semibold text-primary-foreground">
                            {{ count($activeFilters) }}
                        </span>
                    @endif
                </x-ui.button>

                <div wire:loading.delay wire:target="search,status,verification,risk,gender,city,state,premium,source,photos,reported,joined,lastActive">
                    <x-ui.icon name="arrow-path" size="sm" class="animate-spin text-muted-foreground" />
                </div>
            </div>

            <div class="flex items-center gap-2">
                <x-ui.button
                    variant="ghost"
                    size="icon-sm"
                    wire:click="toggleDensity"
                    :title="$density === 'comfortable' ? 'Compact rows' : 'Comfortable rows'"
                >
                    <x-ui.icon name="list-bullet" size="sm" />
                    <span class="sr-only">Toggle row density</span>
                </x-ui.button>

                @can('export_users')
                    <x-ui.button variant="outline" size="sm" icon="download" wire:click="export">Export CSV</x-ui.button>
                @endcan
            </div>
        </x-slot:toolbar>

        {{-- ---- filters ------------------------------------------------ --}}
        @if ($filtersOpen || $activeFilters)
            <x-slot:filters>
                @if ($filtersOpen)
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <x-ui.select size="sm" label="Status" placeholder="Any status"
                            wire:model.live="status" :options="AccountStatus::labels()" />

                        <x-ui.select size="sm" label="Verification" placeholder="Any"
                            wire:model.live="verification" :options="VerificationStatus::labels()" />

                        <x-ui.select size="sm" label="Risk band" placeholder="Any"
                            wire:model.live="risk" :options="RiskBand::labels()" />

                        <x-ui.select size="sm" label="Gender" placeholder="Any"
                            wire:model.live="gender" :options="Gender::labels()" />

                        <x-ui.select size="sm" label="State" placeholder="Anywhere"
                            wire:model.live="state"
                            :options="$states->mapWithKeys(fn ($s) => [$s->id => $s->name.', '.($s->country?->iso2 ?? '')])->all()" />

                        <x-ui.select size="sm" label="City" placeholder="Anywhere"
                            wire:model.live="city"
                            :options="$cities->mapWithKeys(fn ($c) => [$c->id => $c->name.', '.($c->country?->iso2 ?? '')])->all()" />

                        <x-ui.select size="sm" label="Subscription" placeholder="Any"
                            wire:model.live="premium" :options="['yes' => 'Premium', 'no' => 'Free']" />

                        <x-ui.select size="sm" label="Signed up on" placeholder="Any platform"
                            wire:model.live="source" :options="['ios' => 'iOS', 'android' => 'Android', 'web' => 'Web']" />

                        <x-ui.select size="sm" label="Photos" placeholder="Any"
                            wire:model.live="photos" :options="['none' => 'No photos', 'some' => 'Has photos']" />

                        <x-ui.select size="sm" label="Reports" placeholder="Any"
                            wire:model.live="reported" :options="['yes' => 'Ever reported', 'open' => 'Has an open case']" />

                        <x-ui.select size="sm" label="Joined" placeholder="Any time"
                            wire:model.live="joined" :options="['1' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days']" />

                        <x-ui.select size="sm" label="Last active" placeholder="Any time"
                            wire:model.live="lastActive" :options="['1' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days']" />
                    </div>
                @endif

                @if ($activeFilters)
                    <div class="flex flex-wrap items-center gap-1.5 {{ $filtersOpen ? 'mt-3 border-t border-border pt-3' : '' }}">
                        @foreach ($activeFilters as $property => $label)
                            <button
                                type="button"
                                wire:click="clearFilter('{{ $property }}')"
                                class="inline-flex items-center gap-1 rounded-full bg-primary-subtle px-2 py-0.5 text-xs font-medium text-primary-subtle-foreground transition-opacity hover:opacity-75"
                            >
                                {{ $label }}
                                <x-ui.icon name="x-mark" size="xs" />
                            </button>
                        @endforeach

                        <button
                            type="button"
                            wire:click="resetFilters"
                            class="ml-1 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                        >Reset all</button>
                    </div>
                @endif
            </x-slot:filters>
        @endif

        {{-- ---- bulk actions ------------------------------------------- --}}
        @if ($this->hasSelection())
            <x-slot:bulkBar>
                <div class="flex flex-wrap items-center gap-3 border-b border-border bg-primary-subtle px-3 py-2.5 md:px-4">
                    <span class="text-sm font-medium text-primary-subtle-foreground">
                        {{ veyra_number($this->selectedCount()) }} selected
                    </span>

                    @if (! $selectAllMatching && $users->total() > count($selected))
                        <button
                            type="button"
                            wire:click="selectAll"
                            class="text-xs text-primary-subtle-foreground underline underline-offset-2"
                        >Select all {{ veyra_number($users->total()) }} matching</button>
                    @endif

                    <button
                        type="button"
                        wire:click="clearSelection"
                        class="text-xs text-muted-foreground underline-offset-2 hover:underline"
                    >Clear</button>

                    <div class="flex-1"></div>

                    @can('warn_users')
                        <x-ui.button size="xs" variant="outline" wire:click="openBulkStep('warn')">Warn</x-ui.button>
                    @endcan
                    @can('suspend_users')
                        <x-ui.button size="xs" variant="destructive" wire:click="openBulkStep('suspend')">Suspend</x-ui.button>
                    @endcan
                    @can('export_users')
                        <x-ui.button size="xs" variant="outline" icon="download" wire:click="export">Export</x-ui.button>
                    @endcan
                </div>
            </x-slot:bulkBar>
        @endif

        {{-- ---- head --------------------------------------------------- --}}
        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head>
                    <x-ui.checkbox
                        role="checkbox"
                        wire:model.live="selectPage"
                        :indeterminate="$selected && ! $selectPage"
                    />
                </x-ui.table.head>

                <x-ui.table.head sortable field="display_name" :direction="$this->sortDirectionFor('display_name')">
                    Member
                </x-ui.table.head>
                <x-ui.table.head>Location</x-ui.table.head>
                <x-ui.table.head>Status</x-ui.table.head>
                <x-ui.table.head>Verification</x-ui.table.head>
                <x-ui.table.head sortable field="risk_score" :direction="$this->sortDirectionFor('risk_score')">
                    Risk
                </x-ui.table.head>
                <x-ui.table.head align="right">Reports</x-ui.table.head>
                <x-ui.table.head sortable field="created_at" align="right" :direction="$this->sortDirectionFor('created_at')">
                    Joined
                </x-ui.table.head>
                <x-ui.table.head sortable field="last_active_at" align="right" :direction="$this->sortDirectionFor('last_active_at')">
                    Last active
                </x-ui.table.head>
                <x-ui.table.head width="48px"><span class="sr-only">Actions</span></x-ui.table.head>
            </tr>
        </thead>

        {{-- ---- body --------------------------------------------------- --}}
        <tbody wire:loading.class="opacity-50" wire:target="search,status,verification,risk,gender,city,state,premium,source,photos,reported,joined,lastActive,gotoPage,previousPage,nextPage,sort">
            @forelse ($users as $user)
                <x-ui.table.row :selected="$this->isSelected($user->id)" :tint="$user->risk_band->rowClasses()">
                    <x-ui.table.cell>
                        <x-ui.checkbox
                            role="checkbox"
                            wire:model.live="selected"
                            value="{{ $user->id }}"
                            :checked="$this->isSelected($user->id)"
                        />
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-veyra.user-cell
                            :name="$user->display_name"
                            :age="$user->age"
                            :photo="$user->primaryPhoto?->thumb_url"
                            :meta="$user->email"
                            :verified="$user->verification_status === VerificationStatus::Approved"
                            :href="route('admin.users.show', $user)"
                        />
                    </x-ui.table.cell>

                    <x-ui.table.cell muted>
                        {{ $user->city?->name ?? '—' }}
                        @if ($user->city?->country)
                            <span class="text-muted-foreground/60">{{ $user->city->country->iso2 }}</span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-veyra.status-badge :status="$user->account_status" />
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-veyra.status-badge :status="$user->verification_status" />
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-veyra.risk-badge :score="$user->risk_score" :band="$user->risk_band" />
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>
                        @if ($user->reports_against_count > 0)
                            <span class="font-medium text-destructive-subtle-foreground">
                                {{ $user->reports_against_count }}
                            </span>
                        @else
                            <span class="text-muted-foreground">—</span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" muted>{{ veyra_date($user->created_at) }}</x-ui.table.cell>

                    <x-ui.table.cell align="right" muted>
                        {{ $user->last_active_at ? veyra_duration($user->last_active_at).' ago' : '—' }}
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        <x-ui.dropdown align="end" width="w-48">
                            <x-slot:trigger>
                                <button type="button" class="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground">
                                    <x-ui.icon name="dots-horizontal" size="sm" />
                                    <span class="sr-only">Actions for {{ $user->display_name }}</span>
                                </button>
                            </x-slot:trigger>

                            <x-ui.dropdown.item icon="eye" :href="route('admin.users.show', $user)">
                                View profile
                            </x-ui.dropdown.item>

                            @can('warn_users')
                                <x-ui.dropdown.separator />
                                <x-ui.dropdown.item icon="warning" wire:click="openRowStep('warn', {{ $user->id }})">Warn</x-ui.dropdown.item>
                            @endcan

                            @can('suspend_users')
                                <x-ui.dropdown.item icon="pause-circle" variant="destructive" wire:click="openRowStep('suspend', {{ $user->id }})">Suspend</x-ui.dropdown.item>
                            @endcan
                        </x-ui.dropdown>
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="10">
                        <x-ui.empty-state
                            icon="users"
                            heading="No members match these filters"
                            :description="$activeFilters || $search
                                ? 'Try widening the search, or reset the filters to see everyone.'
                                : 'Members will appear here once the app has signups.'"
                        >
                            @if ($activeFilters || $search)
                                <x-slot:actions>
                                    <x-ui.button size="sm" variant="outline" wire:click="resetFilters">
                                        Reset filters
                                    </x-ui.button>
                                </x-slot:actions>
                            @endif
                        </x-ui.empty-state>
                    </td>
                </tr>
            @endforelse
        </tbody>

        {{-- ---- footer ------------------------------------------------- --}}
        <x-slot:footer>
            <x-ui.pagination :paginator="$users" :per-page="$perPage" :per-page-options="$this->perPageOptions()" />
        </x-slot:footer>
    </x-ui.table>
    <x-veyra.enforcement-dialog
        :step="$pendingStep"
        :target="$rowTarget ? (App\Models\AppUser::find($rowTarget)?->display_name ?? '') : $this->selectedCount().' selected '.str('member')->plural($this->selectedCount())"
        :reason-code="$reasonCode"
        :duration-hours="$durationHours"
        :notify-user="$notifyUser"
    />
</div>
