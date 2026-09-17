@props([
    'icon' => null,
    'href' => null,
    'variant' => 'default',
    'shortcut' => null,
    'type' => 'button',
])

@php
    $tag = $href ? 'a' : 'button';

    $variants = [
        'default' => 'text-popover-foreground hover:bg-muted hover:text-foreground',
        'destructive' => 'text-destructive hover:bg-destructive/10',
    ];
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    role="menuitem"
    {{ $attributes->class([
        'relative flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm transition-colors',
        'disabled:pointer-events-none disabled:opacity-50',
        $variants[$variant] ?? $variants['default'],
    ]) }}
>
    @if ($icon)
        {{-- Icons stay muted unless the item carries its own colour. --}}
        <x-ui.icon :name="$icon" size="sm" @class(['text-muted-foreground' => $variant === 'default']) />
    @endif

    <span class="min-w-0 flex-1 truncate">{{ $slot }}</span>

    @if ($shortcut)
        <span class="ml-auto text-xs tracking-widest text-muted-foreground">{{ $shortcut }}</span>
    @endif
</{{ $tag }}>
