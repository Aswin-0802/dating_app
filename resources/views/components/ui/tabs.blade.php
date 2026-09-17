@props([
    'tabs' => [],
    'active' => null,
    'variant' => 'line',
])

{{--
    `tabs` is a list of ['key' => ..., 'label' => ..., 'href' => ?, 'count' => ?].

    The `line` variant draws its indicator with an ::after bar rather than a
    border, so switching tabs never shifts the layout by a pixel.
--}}

@php
    $active ??= $tabs[0]['key'] ?? null;
@endphp

<div
    {{ $attributes->class([
        'flex items-center gap-1 overflow-x-auto',
        'border-b border-border' => $variant === 'line',
        'w-fit rounded-lg bg-muted p-[3px]' => $variant === 'pill',
    ]) }}
    role="tablist"
>
    @foreach ($tabs as $tab)
        @php $isActive = ($tab['key'] ?? null) === $active; @endphp

        <a
            href="{{ $tab['href'] ?? '#' }}"
            @if (isset($tab['href'])) wire:navigate @endif
            role="tab"
            aria-selected="{{ $isActive ? 'true' : 'false' }}"
            @class([
                'relative inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap text-sm font-medium transition-colors',
                // line
                'px-3 py-2.5 -mb-px' => $variant === 'line',
                "after:absolute after:inset-x-3 after:bottom-0 after:h-0.5 after:content-['']" => $variant === 'line',
                'text-foreground after:bg-primary' => $variant === 'line' && $isActive,
                'text-muted-foreground after:bg-transparent hover:text-foreground' => $variant === 'line' && ! $isActive,
                // pill
                'rounded-md px-3 py-1' => $variant === 'pill',
                'bg-card text-foreground shadow-sm' => $variant === 'pill' && $isActive,
                'text-muted-foreground hover:text-foreground' => $variant === 'pill' && ! $isActive,
            ])
        >
            @if (isset($tab['icon']))
                <x-ui.icon :name="$tab['icon']" size="sm" />
            @endif

            {{ $tab['label'] }}

            @if (isset($tab['count']))
                <span @class([
                    'tabular rounded-full px-1.5 py-px text-[11px] font-medium',
                    'bg-primary-subtle text-primary-subtle-foreground' => $isActive,
                    'bg-muted text-muted-foreground' => ! $isActive,
                ])>{{ veyra_compact_number($tab['count']) }}</span>
            @endif
        </a>
    @endforeach

    {{ $slot }}
</div>
