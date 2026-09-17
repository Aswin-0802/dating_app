<header class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-2 border-b border-border bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/80 md:px-6">
    <button
        type="button"
        @click="toggle()"
        class="inline-flex size-9 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
        <x-ui.icon name="menu" size="sm" />
        <span class="sr-only">Toggle sidebar</span>
    </button>

    {{-- Global search. The palette itself is teleported, so this is only a trigger. --}}
    <button
        type="button"
        @click="$dispatch('veyra:open-command-palette')"
        class="group ml-1 hidden h-9 min-w-64 items-center gap-2 rounded-md border border-border bg-card px-3 text-sm text-muted-foreground transition-colors hover:border-primary/40 md:flex"
    >
        <x-ui.icon name="search" size="sm" />
        <span class="flex-1 text-left">Search users, cases, verifications…</span>
        <x-ui.kbd keys="mod+K" />
    </button>

    <div class="flex-1"></div>

    <button
        type="button"
        @click="$dispatch('veyra:open-command-palette')"
        class="inline-flex size-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground md:hidden"
    >
        <x-ui.icon name="search" size="sm" />
        <span class="sr-only">Search</span>
    </button>

    {{-- Theme: three states, cycled in place rather than hidden behind a menu. --}}
    <div x-data="veyraTheme()">
        <button
            type="button"
            @click="cycle()"
            class="inline-flex size-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            ::title="`Theme: ${preference}`"
        >
            <span x-show="preference === 'light'" x-cloak><x-ui.icon name="sun" size="sm" /></span>
            <span x-show="preference === 'dark'" x-cloak><x-ui.icon name="moon" size="sm" /></span>
            <span x-show="preference === 'system'" x-cloak><x-ui.icon name="computer" size="sm" /></span>
            <span class="sr-only">Toggle theme</span>
        </button>
    </div>

    @auth
        <livewire:shell.notification-bell />

        <x-ui.dropdown align="end">
            <x-slot:trigger>
                <button type="button" class="inline-flex items-center gap-2 rounded-md p-1 transition-colors hover:bg-muted">
                    <x-ui.avatar
                        :name="auth()->user()->name"
                        :src="auth()->user()->avatar_url ?? null"
                        size="sm"
                    />
                    <x-ui.icon name="chevron-down" size="xs" class="hidden text-muted-foreground sm:block" />
                </button>
            </x-slot:trigger>

            <x-ui.dropdown.label>
                <span class="block truncate font-medium text-foreground">{{ auth()->user()->name }}</span>
                <span class="block truncate text-muted-foreground">{{ auth()->user()->email }}</span>
            </x-ui.dropdown.label>

            <x-ui.dropdown.separator />

            <x-ui.dropdown.item icon="user-circle" :href="route('admin.profile')">
                My profile
            </x-ui.dropdown.item>

            <x-ui.dropdown.separator />

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-ui.dropdown.item icon="logout" type="submit" variant="destructive">
                    Sign out
                </x-ui.dropdown.item>
            </form>
        </x-ui.dropdown>
    @endauth
</header>
