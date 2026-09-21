@props([
    'status',
    'showIcon' => true,
    'size' => 'md',
])

{{--
    Renders any Platform enum that uses the HasBadge trait.

    Colour is never the only signal — an icon or the label always carries the
    meaning too, which keeps this readable for colourblind users and satisfies
    WCAG 1.4.1.
--}}

@php
    $sizes = [
        'sm' => 'px-1.5 py-0 text-[11px] gap-1',
        'md' => 'px-2 py-0.5 text-xs gap-1',
    ];

    $icon = $showIcon && method_exists($status, 'icon') ? $status->icon() : null;
@endphp

<span {{ $attributes->class([
    'inline-flex w-fit shrink-0 items-center rounded-full font-medium whitespace-nowrap',
    $status->badgeClasses(),
    $sizes[$size] ?? $sizes['md'],
]) }}>
    @if ($icon)
        <x-ui.icon :name="$icon" size="xs" />
    @endif

    {{ $slot->isNotEmpty() ? $slot : $status->label() }}
</span>
