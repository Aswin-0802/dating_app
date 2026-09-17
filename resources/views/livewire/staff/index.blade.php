<div class="space-y-4 md:space-y-6">
    <x-ui.table :density="$density">
        <x-slot:toolbar>
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1 sm:max-w-xs">
                    <x-ui.icon name="search" size="sm"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or email"
                        class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground">
                </div>

                <x-ui.select size="sm" placeholder="Any role" wire:model.live="role"
                    :options="$roles->mapWithKeys(fn ($r) => [$r->name => $r->name])->all()" class="w-48" />

                <x-ui.select size="sm" placeholder="Any status" wire:model.live="status"
                    :options="['active' => 'Active', 'suspended' => 'Suspended', 'invited' => 'Invited']" class="w-36" />
            </div>

            @can('view_staff_performance')
                <x-ui.button variant="outline" size="sm" icon="chart-bar" :href="route('admin.staff.performance')">
                    Performance
                </x-ui.button>
            @endcan
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head sortable field="name" :direction="$this->sortDirectionFor('name')">Name</x-ui.table.head>
                <x-ui.table.head>Role</x-ui.table.head>
                <x-ui.table.head>Status</x-ui.table.head>
                <x-ui.table.head align="right">Decisions</x-ui.table.head>
                <x-ui.table.head>2FA</x-ui.table.head>
                <x-ui.table.head sortable field="last_login_at" align="right" :direction="$this->sortDirectionFor('last_login_at')">
                    Last sign-in
                </x-ui.table.head>
                <x-ui.table.head align="right" width="110px"><span class="sr-only">Actions</span></x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($staff as $member)
                <x-ui.table.row>
                    <x-ui.table.cell>
                        <div class="flex min-w-0 items-center gap-2.5">
                            <x-ui.avatar :name="$member->name" :src="$member->avatar_url" size="sm" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ $member->name }}</p>
                                <p class="truncate text-xs text-muted-foreground">{{ $member->email }}</p>
                            </div>
                        </div>
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        @foreach ($member->roles as $memberRole)
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $memberRole->badgeClasses() }}">
                                {{ $memberRole->name }}
                            </span>
                        @endforeach
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-ui.badge :variant="$member->status === 'active' ? 'success' : 'muted'">
                            {{ ucfirst($member->status) }}
                        </x-ui.badge>
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric muted>{{ $member->moderation_actions_count }}</x-ui.table.cell>

                    <x-ui.table.cell>
                        @if ($member->hasTwoFactorEnabled())
                            <x-ui.badge variant="success" icon="shield-check" size="sm">On</x-ui.badge>
                        @else
                            {{-- Staff hold powers over other people's accounts;
                                 unprotected access to that is worth flagging. --}}
                            <x-ui.badge variant="warning" size="sm">Off</x-ui.badge>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" muted>
                        {{ $member->last_login_at ? veyra_duration($member->last_login_at).' ago' : 'Never' }}
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        @can('staff_status_toggle')
                            @if ($member->id !== auth()->id())
                                <x-ui.button
                                    size="xs"
                                    :variant="$member->status === 'active' ? 'outline' : 'success'"
                                    wire:click="toggleStatus({{ $member->id }})"
                                    wire:confirm="{{ $member->status === 'active' ? 'Suspend' : 'Reactivate' }} {{ $member->name }}?"
                                >{{ $member->status === 'active' ? 'Suspend' : 'Reactivate' }}</x-ui.button>
                            @else
                                <span class="text-xs text-muted-foreground">You</span>
                            @endif
                        @endcan
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="7"><x-ui.empty-state icon="user-circle" heading="No staff match this view" /></td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$staff" :per-page="$perPage" :per-page-options="$this->perPageOptions()" />
        </x-slot:footer>
    </x-ui.table>
</div>
