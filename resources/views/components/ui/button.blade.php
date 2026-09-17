@props([
    'variant' => 'default',
    'size' => 'md',
    'icon' => null,
    'iconRight' => null,
    'href' => null,
    'type' => 'button',
    'loading' => false,
])

@php
    $variants = [
        'default' => 'bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm',
        'secondary' => 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
        'outline' => 'border border-border bg-card text-foreground hover:bg-muted',
        'ghost' => 'text-muted-foreground hover:bg-muted hover:text-foreground',
        'destructive' => 'bg-destructive text-white hover:bg-destructive/90 shadow-sm',
        'success' => 'bg-success text-success-foreground hover:bg-success/90 shadow-sm',
        'link' => 'text-primary underline-offset-4 hover:underline',
    ];

    $sizes = [
        'xs' => 'h-7 gap-1 px-2 text-xs rounded-sm',
        'sm' => 'h-8 gap-1.5 px-3 text-sm rounded-md',
        'md' => 'h-9 gap-2 px-4 text-sm rounded-md',
        'lg' => 'h-10 gap-2 px-5 text-sm rounded-lg',
        // Square variants for icon-only buttons; the label goes in an sr-only span.
        'icon-sm' => 'size-8 rounded-md',
        'icon' => 'size-9 rounded-md',
    ];

    $iconSize = str_contains($size, 'sm') || $size === 'xs' ? 'sm' : 'sm';

    $classes = implode(' ', [
        'inline-flex shrink-0 items-center justify-center whitespace-nowrap font-medium',
        'transition-colors outline-none',
        'disabled:pointer-events-none disabled:opacity-50',
        $variants[$variant] ?? $variants['default'],
        $sizes[$size] ?? $sizes['md'],
    ]);

    $tag = $href ? 'a' : 'button';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    @if ($loading) disabled aria-busy="true" @endif
    {{ $attributes->class($classes) }}
>
    @if ($loading)
        <x-ui.icon name="arrow-path" size="{{ $iconSize }}" class="animate-spin" />
    @elseif ($icon)
        <x-ui.icon :name="$icon" size="{{ $iconSize }}" />
    @endif

    {{ $slot }}

    @if ($iconRight)
        <x-ui.icon :name="$iconRight" size="{{ $iconSize }}" />
    @endif
</{{ $tag }}>
