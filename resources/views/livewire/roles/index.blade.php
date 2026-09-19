<div class="space-y-4 md:space-y-6">

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted-foreground">
            A role decides what its staff can see and do. Built-in roles can be edited but not deleted.
        </p>
        @can('add_roles')
            <x-ui.button size="sm" icon="plus" wire:click="create">New role</x-ui.button>
        @endcan
    </div>

    <div class="grid gap-4 md:gap-6 lg:grid-cols-2 xl:grid-cols-3">
        @foreach ($roles as $role)
            <x-ui.card>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-semibold">{{ $role->name }}</h3>
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $role->badgeClasses() }}">
                                {{ $role->permissions_count }} permissions
                            </span>
                        </div>

                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ $role->users_count }} {{ str('member')->plural($role->users_count) }} of staff
                        </p>
                    </div>

                    @if (in_array($role->name, $builtIn, true))
                        <x-ui.badge variant="muted" icon="lock" size="sm">Built-in</x-ui.badge>
                    @else
                        <x-ui.badge variant="info" size="sm">Custom</x-ui.badge>
                    @endif
                </div>

                <div class="mt-4 flex items-center gap-2">
                    @can('assign_permissions')
                        <x-ui.button size="sm" variant="outline" :href="route('admin.roles.permissions', $role)">
                            Edit permissions
                        </x-ui.button>
                    @else
                        <x-ui.button size="sm" variant="ghost" :href="route('admin.roles.permissions', $role)">
                            View permissions
                        </x-ui.button>
                    @endcan

                    @can('delete_roles')
                        @unless (in_array($role->name, $builtIn, true))
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                class="ml-auto text-destructive"
                                wire:click="delete({{ $role->id }})"
                                wire:confirm="Delete the {{ $role->name }} role? This cannot be undone."
                            >Delete</x-ui.button>
                        @endunless
                    @endcan
                </div>
            </x-ui.card>
        @endforeach
    </div>

    <x-ui.dialog :show="$formOpen" close="closeForm">
        <form wire:submit="save" class="p-6" novalidate>
            <h2 class="text-lg font-semibold">New role</h2>
            <p class="mt-1 text-sm text-muted-foreground">You will choose its permissions on the next screen.</p>

            <div class="mt-5 space-y-4">
                <x-ui.input label="Role name" wire:model="name" placeholder="e.g. Regional moderator" :error="$errors->first('name')" required autofocus />
                <x-ui.select
                    label="Start with the permissions of"
                    placeholder="No permissions"
                    wire:model="copyFrom"
                    :options="$roles->reject(fn ($r) => $r->name === App\Models\Role::SUPER_ADMIN)->mapWithKeys(fn ($r) => [$r->name => $r->name])->all()"
                    :error="$errors->first('copyFrom')"
                />
            </div>

            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="ghost" wire:click="closeForm">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Create role</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
