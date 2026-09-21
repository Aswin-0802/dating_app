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

            <div class="flex items-center gap-2">
                @can('view_staff_performance')
                    <x-ui.button variant="outline" size="sm" icon="chart-bar" :href="route('admin.staff.performance')">
                        Performance
                    </x-ui.button>
                @endcan
                @can('add_staff')
                    <x-ui.button size="sm" icon="plus" wire:click="create">Add staff</x-ui.button>
                @endcan
            </div>
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
                        {{ $member->last_login_at ? platform_duration($member->last_login_at).' ago' : 'Never' }}
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        @canany(['edit_staff', 'staff_status_toggle', 'delete_staff'])
                            <x-ui.dropdown align="end">
                                <x-slot:trigger>
                                    <x-ui.button variant="ghost" size="icon-sm" icon="dots-horizontal">
                                        <span class="sr-only">Actions for {{ $member->name }}</span>
                                    </x-ui.button>
                                </x-slot:trigger>

                                @can('edit_staff')
                                    <x-ui.dropdown.item icon="pencil" wire:click="edit({{ $member->id }})">Edit</x-ui.dropdown.item>
                                @endcan

                                @if ($member->id !== auth()->id())
                                    @can('staff_status_toggle')
                                        <x-ui.dropdown.item
                                            :icon="$member->status === 'active' ? 'pause-circle' : 'check-circle'"
                                            wire:click="toggleStatus({{ $member->id }})"
                                            wire:confirm="{{ $member->status === 'active' ? 'Suspend' : 'Reactivate' }} {{ $member->name }}?"
                                        >{{ $member->status === 'active' ? 'Suspend' : 'Reactivate' }}</x-ui.dropdown.item>
                                    @endcan
                                    @can('delete_staff')
                                        <x-ui.dropdown.separator />
                                        <x-ui.dropdown.item
                                            icon="trash"
                                            variant="destructive"
                                            wire:click="remove({{ $member->id }})"
                                            wire:confirm="Remove {{ $member->name }}? They will lose access immediately. Their past decisions stay in the audit log."
                                        >Remove</x-ui.dropdown.item>
                                    @endcan
                                @endif
                            </x-ui.dropdown>
                        @endcanany
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

    <x-ui.dialog :show="$formOpen" close="closeForm">
        <form wire:submit="saveStaff" class="p-6" novalidate>
            <h2 class="text-lg font-semibold">{{ $editingId ? 'Edit staff member' : 'Add staff member' }}</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ $editingId ? 'Changes take effect the next time they load a page.' : 'They will be emailed a link to set their own password.' }}
            </p>

            <div class="mt-5 space-y-4">
                <x-ui.input label="Name" wire:model="formName" :error="$errors->first('formName')" required autofocus />
                <x-ui.input label="Email" type="email" wire:model="formEmail" :error="$errors->first('formEmail')" required />
                <x-ui.input label="Job title" wire:model="formJobTitle" :error="$errors->first('formJobTitle')" />
                <x-ui.select
                    label="Role"
                    wire:model="formRole"
                    :selected="$formRole"
                    :options="$roles->mapWithKeys(fn ($r) => [$r->name => $r->name])->all()"
                    :error="$errors->first('formRole')"
                    hint="The role decides everything they can see and do."
                />
            </div>

            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="ghost" wire:click="closeForm">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveStaff">{{ $editingId ? 'Save changes' : 'Add staff member' }}</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
