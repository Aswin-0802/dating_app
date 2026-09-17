@props([
    'label' => null,
    'hint' => null,
    'error' => null,
    'rows' => 4,
    'id' => null,
    'required' => false,
])

@php
    $id ??= 'textarea-'.str()->random(6);
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

    <textarea
        id="{{ $id }}"
        rows="{{ $rows }}"
        @if ($required) required @endif
        @if ($hasError) aria-invalid="true" @endif
        {{ $attributes->class([
            'w-full rounded-md border bg-card px-3 py-2 text-sm text-foreground transition-colors',
            'placeholder:text-muted-foreground resize-y',
            'disabled:cursor-not-allowed disabled:opacity-50',
            'border-destructive' => $hasError,
            'border-input' => ! $hasError,
        ]) }}
    >{{ $slot }}</textarea>

    @if ($hasError)
        <p class="text-xs text-destructive">{{ $error }}</p>
    @elseif ($hint)
        <p class="text-xs text-muted-foreground">{{ $hint }}</p>
    @endif
</div>
