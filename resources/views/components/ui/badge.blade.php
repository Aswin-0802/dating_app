@props([
    'variant' => 'muted',
    'icon' => null,
    'dot' => false,
    'size' => 'md',
])

{{--
    Soft/tinted for states, solid only for urgency — so the eye is drawn to the
    few things that actually need action.

    Pass `class` directly (e.g. from an enum's badgeClasses()) to override the
    variant entirely; colour is never the only signal, so keep the label or icon.
--}}

@php
    $variants = [
        'muted' => 'bg-muted text-muted-foreground',
        'primary' => 'bg-primary-subtle text-primary-subtle-foreground',
        'accent' => 'bg-accent-subtle text-accent-subtle-foreground',
        'success' => 'bg-success-subtle text-success-subtle-foreground',
        'warning' => 'bg-warning-subtle text-warning-subtle-foreground',
        'info' => 'bg-info-subtle text-info-subtle-foreground',
        'destructive' => 'bg-destructive-subtle text-destructive-subtle-foreground',
        'outline' => 'bg-transparent text-foreground ring-1 ring-inset ring-border',
        // solid — reserve for genuinely urgent values
        'solid-destructive' => 'bg-destructive text-white',
        'solid-info' => 'bg-info text-white',
        'solid-success' => 'bg-success text-success-foreground',
    ];

    $sizes = [
        'sm' => 'px-1.5 py-0 text-[11px] gap-1',
        'md' => 'px-2 py-0.5 text-xs gap-1',
        'lg' => 'px-2.5 py-1 text-xs gap-1.5',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex w-fit shrink-0 items-center justify-center rounded-full font-medium whitespace-nowrap',
    $variants[$variant] ?? $variants['muted'],
    $sizes[$size] ?? $sizes['md'],
]) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full bg-current"></span>
    @elseif ($icon)
        <x-ui.icon :name="$icon" size="xs" />
    @endif

    {{ $slot }}
</span>
