<div>
    <template x-teleport="body">
        <div
            x-data="{ show: @entangle('open') }"
            @veyra:open-command-palette.window="show = true; $nextTick(() => $refs.field?.focus())"
            @keydown.escape.window="show = false"
        >
            <div x-show="show" x-cloak class="fixed inset-0 z-[90]">
                <div
                    x-show="show"
                    x-transition.opacity
                    @click="show = false"
                    class="absolute inset-0 bg-black/50 backdrop-blur-[1px]"
                ></div>

                <div class="absolute inset-x-0 top-[12vh] mx-auto w-full max-w-xl px-4">
                    <div
                        x-show="show"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        role="dialog"
                        aria-modal="true"
                        class="overflow-hidden rounded-xl border border-border bg-popover shadow-2xl"
                    >
                        {{-- 48px input, zero padding on the shell: the field is
                             the whole affordance here. --}}
                        <div class="flex h-12 items-center gap-2 border-b border-border px-4">
                            <x-ui.icon name="search" size="sm" class="shrink-0 text-muted-foreground" />

                            <input
                                x-ref="field"
                                type="search"
                                wire:model.live.debounce.250ms="query"
                                placeholder="Search members, cases and verifications…"
                                class="h-full w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                            >

                            <div wire:loading.delay wire:target="query" class="shrink-0">
                                <x-ui.icon name="arrow-path" size="xs" class="animate-spin text-muted-foreground" />
                            </div>

                            <x-ui.kbd>Esc</x-ui.kbd>
                        </div>

                        <div class="max-h-[22rem] overflow-y-auto p-1">
                            @forelse ($groups as $group => $results)
                                <p class="px-2 py-1.5 text-xs font-medium text-muted-foreground">{{ $group }}</p>

                                @foreach ($results as $result)
                                    <a
                                        href="{{ $result['url'] }}"
                                        wire:navigate
                                        @click="show = false"
                                        class="flex items-center gap-2.5 rounded-sm px-2 py-2 text-sm transition-colors hover:bg-muted"
                                    >
                                        <x-ui.avatar :src="$result['photo']" :name="$result['label']" size="xs" />

                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium">{{ $result['label'] }}</span>
                                            <span class="block truncate text-xs text-muted-foreground">{{ $result['meta'] }}</span>
                                        </span>

                                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $result['badge'] }}">
                                            {{ $result['badge_label'] }}
                                        </span>
                                    </a>
                                @endforeach
                            @empty
                                @if (strlen(trim($query)) >= 2)
                                    <div class="px-2 py-8 text-center">
                                        <p class="text-sm text-muted-foreground">
                                            Nothing matches &ldquo;{{ $query }}&rdquo;.
                                        </p>
                                    </div>
                                @else
                                    {{-- An empty palette is a dead end. These
                                         turn it into a launcher. --}}
                                    <p class="px-2 py-1.5 text-xs font-medium text-muted-foreground">Jump to</p>

                                    @foreach ($shortcuts as $shortcut)
                                        <a
                                            href="{{ $shortcut['url'] }}"
                                            wire:navigate
                                            @click="show = false"
                                            class="flex items-center gap-2.5 rounded-sm px-2 py-2 text-sm transition-colors hover:bg-muted"
                                        >
                                            <x-ui.icon :name="$shortcut['icon']" size="sm" class="text-muted-foreground" />
                                            <span class="min-w-0 flex-1 truncate">{{ $shortcut['label'] }}</span>
                                        </a>
                                    @endforeach
                                @endif
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
