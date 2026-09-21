@props([
    'align' => 'end',
    'width' => 'w-56',
])

{{--
    Anchored menu. Kept intentionally small: Alpine handles open state and
    outside-click, CSS handles placement. No positioning library — every menu in
    Platform anchors to a corner, and a 2kB dependency for that is not worth it.
--}}

@php
    $alignments = [
        'start' => 'left-0 origin-top-left',
        'end' => 'right-0 origin-top-right',
        'center' => 'left-1/2 -translate-x-1/2 origin-top',
    ];
@endphp

<div x-data="{ open: false }" @keydown.escape.stop="open = false" class="relative inline-block text-left">
    <div @click="open = !open">
        {{ $trigger }}
    </div>

    <div
        x-show="open"
        x-cloak
        @click.outside="open = false"
        @click="open = false"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        role="menu"
        {{ $attributes->class([
            'absolute z-40 mt-1.5 min-w-32 overflow-hidden rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-md',
            $alignments[$align] ?? $alignments['end'],
            $width,
        ]) }}
    >
        {{ $slot }}
    </div>
</div>
