@props([
    'src' => null,
    'name' => null,
    'size' => 'md',
    'ring' => null,
])

{{--
    Size is a data attribute rather than a variant class so one component covers
    every call site and the fallback text scales with it.

    `ring` takes a token colour name and is used to carry a signal on the avatar
    itself — a verified tick ring, or a risk band on a queue row.
--}}

@php
    $sizes = [
        'xs' => 'size-6 text-[10px]',
        'sm' => 'size-8 text-xs',
        'md' => 'size-10 text-sm',
        'lg' => 'size-12 text-base',
        'xl' => 'size-16 text-lg',
        '2xl' => 'size-24 text-2xl',
    ];

    $rings = [
        'primary' => 'ring-2 ring-primary ring-offset-2 ring-offset-background',
        'success' => 'ring-2 ring-success ring-offset-2 ring-offset-background',
        'warning' => 'ring-2 ring-warning ring-offset-2 ring-offset-background',
        'destructive' => 'ring-2 ring-destructive ring-offset-2 ring-offset-background',
    ];

    $initials = veyra_initials($name);
@endphp

<span {{ $attributes->class([
    'relative inline-flex shrink-0 select-none items-center justify-center overflow-hidden rounded-full bg-muted font-medium text-muted-foreground',
    $sizes[$size] ?? $sizes['md'],
    $ring ? ($rings[$ring] ?? '') : '',
]) }}>
    @if ($src)
        <img
            src="{{ $src }}"
            alt="{{ $name }}"
            loading="lazy"
            class="aspect-square size-full object-cover"
        >
    @else
        {{ $initials }}
    @endif
</span>
