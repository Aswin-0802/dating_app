@props([
    'name' => null,
    'photo' => null,
    'age' => null,
    'meta' => null,
    'href' => null,
    'verified' => false,
    'size' => 'sm',
])

{{--
    The identity cell used in every table that lists members. Avatar, name with a
    verification tick, and one line of secondary context.
--}}

@php
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class(['flex min-w-0 items-center gap-2.5', 'group' => (bool) $href]) }}
>
    <x-ui.avatar :src="$photo" :name="$name" :size="$size" />

    <div class="min-w-0">
        <div class="flex items-center gap-1">
            <span @class([
                'truncate text-sm font-medium text-foreground',
                'group-hover:text-primary' => (bool) $href,
            ])>{{ $name ?? 'Unknown' }}</span>

            @if ($age)
                <span class="tabular shrink-0 text-sm text-muted-foreground">{{ $age }}</span>
            @endif

            @if ($verified)
                <x-ui.icon
                    name="shield-check"
                    size="xs"
                    class="shrink-0 text-success"
                    title="Photo verified"
                />
            @endif
        </div>

        @if ($meta)
            <p class="truncate text-xs text-muted-foreground">{{ $meta }}</p>
        @endif
    </div>
</{{ $tag }}>
