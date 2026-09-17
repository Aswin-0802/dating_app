@props([
    'label' => null,
    'hint' => null,
    'error' => null,
    'icon' => null,
    'trailing' => null,
    'type' => 'text',
    'id' => null,
    'required' => false,
])

@php
    $id ??= 'input-'.str()->random(6);
    $hasError = filled($error);
@endphp

<div class="space-y-1.5">
    @if ($label)
        <label for="{{ $id }}" class="block text-sm font-medium text-foreground">
            {{ $label }}
            @if ($required)
                <span class="text-destructive" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    <div class="relative">
        @if ($icon)
            <x-ui.icon
                :name="$icon"
                size="sm"
                class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"
            />
        @endif

        <input
            id="{{ $id }}"
            type="{{ $type }}"
            @if ($required) required @endif
            @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            {{ $attributes->class([
                'h-9 w-full rounded-md border bg-card px-3 text-sm text-foreground transition-colors',
                'placeholder:text-muted-foreground',
                'disabled:cursor-not-allowed disabled:opacity-50',
                'pl-9' => (bool) $icon,
                'pr-9' => (bool) $trailing,
                'border-destructive' => $hasError,
                'border-input' => ! $hasError,
            ]) }}
        >

        @if ($trailing)
            <div class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                {{ $trailing }}
            </div>
        @endif
    </div>

    @if ($hasError)
        <p id="{{ $id }}-error" class="text-xs text-destructive">{{ $error }}</p>
    @elseif ($hint)
        <p class="text-xs text-muted-foreground">{{ $hint }}</p>
    @endif
</div>
