@php
    use App\Support\Navigation;

    $sections = Navigation::sections();
    $counts = app()->bound('platform.nav-counts') ? app('platform.nav-counts') : [];
@endphp

{{--
    The rail keeps its own --sidebar-* tokens, so it stays dark plum-navy while
    the content canvas is light. Width is driven by the shell's --sidebar-width
    variable rather than swapped classes, so the collapse animates one property.
--}}

<aside
    class="fixed inset-y-0 left-0 z-40 flex shrink-0 flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-[width,transform] duration-300 ease-in-out lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
    style="width: var(--sidebar-width)"
    :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
>
    {{-- Brand --}}
    <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-sidebar-border px-4">
        <a href="{{ route('admin.dashboard') }}" wire:navigate class="flex min-w-0 items-center gap-2.5">
            <x-brand.mark variant="admin" size="sm" tone="sidebar" />

            <span class="platform-nav-label min-w-0">
                <span class="block truncate text-sm font-semibold leading-tight">{{ App\Support\Branding::name() }}</span>
                <span class="block truncate text-[11px] leading-tight text-sidebar-muted-foreground">
                    {{ App\Support\Branding::tagline() }}
                </span>
            </span>
        </a>
    </div>

    {{-- Navigation --}}
    <nav class="min-h-0 flex-1 space-y-4 overflow-y-auto px-3 py-4">
        @foreach ($sections as $section)
            <div class="space-y-0.5">
                @if ($section['label'])
                    <p class="platform-nav-section-label px-2.5 pb-1 text-[11px] font-semibold uppercase tracking-wider text-sidebar-muted-foreground">
                        {{ $section['label'] }}
                    </p>
                @endif

                @foreach ($section['items'] as $item)
                    @php $active = Navigation::isActive($item); @endphp

                    @if (isset($item['children']))
                        <div x-data="{ open: @js($active) }">
                            <button
                                type="button"
                                @click="open = !open"
                                @class([
                                    'platform-nav-link flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-sm transition-colors',
                                    'bg-sidebar-accent text-sidebar-accent-foreground' => $active,
                                    'text-sidebar-foreground/80 hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground' => ! $active,
                                ])
                            >
                                <x-ui.icon :name="$item['icon']" size="sm" class="shrink-0" />
                                <span class="platform-nav-label min-w-0 flex-1 truncate text-left">{{ $item['label'] }}</span>
                                <x-ui.icon
                                    name="chevron-down"
                                    size="xs"
                                    class="platform-nav-chevron shrink-0 transition-transform"
                                    ::class="open && 'rotate-180'"
                                />
                            </button>

                            <div x-show="open" x-collapse class="platform-nav-label mt-0.5 space-y-0.5 pl-9">
                                @foreach ($item['children'] as $child)
                                    @php
                                        $childActive = request()->routeIs($child['route']);
                                        $childCount = $counts[$child['badge'] ?? ''] ?? null;
                                    @endphp

                                    <a
                                        href="{{ route($child['route']) }}"
                                        wire:navigate
                                        @class([
                                            'flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm transition-colors',
                                            'text-sidebar-accent-foreground' => $childActive,
                                            'text-sidebar-foreground/65 hover:text-sidebar-accent-foreground' => ! $childActive,
                                        ])
                                    >
                                        <span class="min-w-0 flex-1 truncate">{{ $child['label'] }}</span>

                                        @if ($childCount)
                                            <span class="tabular shrink-0 rounded-full bg-sidebar-primary px-1.5 py-px text-[10px] font-semibold text-sidebar-primary-foreground">
                                                {{ platform_compact_number($childCount) }}
                                            </span>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @else
                        @php $count = $counts[$item['badge'] ?? ''] ?? null; @endphp

                        <a
                            href="{{ route($item['route']) }}"
                            wire:navigate
                            @if ($active) aria-current="page" @endif
                            title="{{ $item['label'] }}"
                            @class([
                                'platform-nav-link relative flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm transition-colors',
                                'bg-sidebar-accent font-medium text-sidebar-accent-foreground' => $active,
                                'text-sidebar-foreground/80 hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground' => ! $active,
                            ])
                        >
                            {{-- Active marker survives the collapsed rail, where the label does not. --}}
                            @if ($active)
                                <span class="absolute inset-y-1.5 left-0 w-0.5 rounded-full bg-sidebar-primary"></span>
                            @endif

                            <x-ui.icon :name="$item['icon']" size="sm" class="shrink-0" />
                            <span class="platform-nav-label min-w-0 flex-1 truncate">{{ $item['label'] }}</span>

                            @if ($count)
                                <span class="platform-nav-badge tabular shrink-0 rounded-full bg-sidebar-primary px-1.5 py-px text-[10px] font-semibold text-sidebar-primary-foreground">
                                    {{ platform_compact_number($count) }}
                                </span>
                            @endif
                        </a>
                    @endif
                @endforeach
            </div>
        @endforeach
    </nav>

    {{-- Current operator: who is on duty and what their queue looks like. --}}
    @auth
        <div class="shrink-0 border-t border-sidebar-border p-3">
            <div class="flex items-center gap-2.5 rounded-md px-1.5 py-1.5">
                <x-ui.avatar
                    :name="auth()->user()->name"
                    :src="auth()->user()->avatar_url ?? null"
                    size="sm"
                    class="shrink-0"
                />

                <div class="platform-nav-label min-w-0 flex-1">
                    <p class="truncate text-sm font-medium leading-tight">{{ auth()->user()->name }}</p>
                    <p class="truncate text-[11px] leading-tight text-sidebar-muted-foreground">
                        {{ auth()->user()->getRoleNames()->first() ?? 'No role' }}
                    </p>
                </div>
            </div>
        </div>
    @endauth
</aside>
