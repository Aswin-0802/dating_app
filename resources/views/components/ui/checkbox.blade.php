@props([
    'label' => null,
    'description' => null,
    'id' => null,
    'checked' => false,
    'indeterminate' => false,
])

@php
    $id ??= 'checkbox-'.str()->random(6);
@endphp

<label for="{{ $id }}" @class([
    'inline-flex items-start gap-2.5',
    'cursor-pointer select-none' => true,
])>
    <input
        id="{{ $id }}"
        type="checkbox"
        @checked($checked)
        @if ($indeterminate) x-init="$el.indeterminate = true" @endif
        {{ $attributes->class([
            // The tick and dash glyphs come from .veyra-checkbox in components.css.
            'veyra-checkbox',
            'mt-px size-4 shrink-0 cursor-pointer appearance-none rounded-xs border border-input bg-card transition-colors',
            'checked:border-primary checked:bg-primary',
            'indeterminate:border-primary indeterminate:bg-primary',
            'disabled:cursor-not-allowed disabled:opacity-50',
        ]) }}
    >

    @if ($label || $description)
        <span class="min-w-0 space-y-0.5">
            @if ($label)
                <span class="block text-sm leading-tight text-foreground">{{ $label }}</span>
            @endif

            @if ($description)
                <span class="block text-xs text-muted-foreground">{{ $description }}</span>
            @endif
        </span>
    @endif
</label>
