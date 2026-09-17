<div class="space-y-4 md:space-y-6">

    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
        <x-ui.icon name="key" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
        <div class="min-w-0 text-sm">
            <p class="font-medium">Two separations are load-bearing</p>
            <p class="mt-0.5 text-muted-foreground">
                Admin runs the platform; T&amp;S Lead owns safety policy. Admin cannot read
                message content, decide appeals, or change moderation settings — that split
                is what makes the audit log meaningful. And Moderators cannot decide appeals,
                which guarantees the "never the original decider" rule always has somebody
                to route to.
            </p>
        </div>
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

                    @if ($role->isProtected())
                        <x-ui.badge variant="muted" icon="lock" size="sm">Protected</x-ui.badge>
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
                </div>
            </x-ui.card>
        @endforeach
    </div>
</div>
