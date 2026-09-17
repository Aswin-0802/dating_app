@props([
    'label' => null,
    'description' => null,
    'size' => 'md',
    'id' => null,
    'checked' => false,
])

{{--
    Pure CSS via `peer-checked:` — no JavaScript at all, so it works inside a
    Livewire re-render without needing to re-bind anything.

    Use size="lg" for destructive settings (shadow ban, disable account): the
    larger track clears the 24px touch-target floor.
--}}

@php
    $id ??= 'toggle-'.str()->random(6);

    $tracks = [
        'md' => 'h-5 w-9 after:h-4 after:w-4',
        'lg' => 'h-6 w-11 after:h-5 after:w-5',
    ];
@endphp

<label for="{{ $id }}" @class([
    'flex cursor-pointer items-start gap-3',
    'select-none' => true,
])>
    <input
        id="{{ $id }}"
        type="checkbox"
        class="peer sr-only"
        @checked($checked)
        {{ $attributes }}
    >

    <div @class([
        'relative shrink-0 rounded-full bg-input transition-colors',
        'peer-checked:bg-primary',
        'peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-background',
        'peer-disabled:cursor-not-allowed peer-disabled:opacity-50',
        // Logical properties keep this RTL-correct for free.
        "after:absolute after:top-0.5 after:start-0.5 after:rounded-full after:bg-white after:shadow-sm after:transition-all after:content-['']",
        'peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full',
        $tracks[$size] ?? $tracks['md'],
    ])></div>

    @if ($label || $description)
        <span class="min-w-0 space-y-0.5">
            @if ($label)
                <span class="block text-sm font-medium leading-none text-foreground">{{ $label }}</span>
            @endif

            @if ($description)
                <span class="block text-xs text-muted-foreground">{{ $description }}</span>
            @endif
        </span>
    @endif
</label>
