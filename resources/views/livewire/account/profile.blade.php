<div class="grid gap-4 md:gap-6 lg:grid-cols-3">
    <div class="space-y-4 md:space-y-6 lg:col-span-2">
        <form wire:submit="saveDetails">
            <x-ui.card title="Your details">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Name" wire:model="name" :error="$errors->first('name')" required />
                    <x-ui.input label="Job title" wire:model="jobTitle" :error="$errors->first('jobTitle')" />
                    <x-ui.input label="Email" :value="$user->email" disabled hint="Ask an administrator to change your sign-in email." />
                    <x-ui.input label="Role" :value="$user->role_name" disabled />
                </div>
                <div class="mt-5 flex justify-end">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveDetails">Save details</x-ui.button>
                </div>
            </x-ui.card>
        </form>

        <form wire:submit="changePassword">
            <x-ui.card title="Password" description="At least 10 characters with upper and lower case letters and a number. Changing it signs out your other sessions.">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.input label="Current password" type="password" wire:model="currentPassword" :error="$errors->first('currentPassword')" autocomplete="current-password" required />
                    <x-ui.input label="New password" type="password" wire:model="password" :error="$errors->first('password')" autocomplete="new-password" required />
                    <x-ui.input label="Repeat new password" type="password" wire:model="password_confirmation" autocomplete="new-password" required />
                </div>
                <div class="mt-5 flex justify-end">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="changePassword">Change password</x-ui.button>
                </div>
            </x-ui.card>
        </form>

        <x-ui.card title="Sign-in activity">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Last sign-in</dt>
                    <dd class="font-medium">{{ platform_datetime($user->last_login_at) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">From IP address</dt>
                    <dd class="font-medium">{{ $user->last_login_ip ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>
    </div>

    <x-ui.card title="Your permissions" :description="$user->getAllPermissions()->count().' granted through your role'">
        <div class="max-h-[32rem] space-y-3 overflow-y-auto">
            @foreach ($user->getAllPermissions()->groupBy('group_name')->sortKeys() as $group => $permissions)
                <div>
                    <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ $group }}</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($permissions as $permission)
                            <x-ui.badge :variant="$permission->is_sensitive ? 'destructive' : 'muted'" size="sm">{{ $permission->display_label }}</x-ui.badge>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-ui.card>
</div>
