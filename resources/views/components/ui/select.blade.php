@props([
    'label' => null,
    'hint' => null,
    'error' => null,
    'placeholder' => null,
    'options' => [],
    'grouped' => [],
    'selected' => null,
    'id' => null,
    'required' => false,
    'size' => 'md',
])

{{--
    `options` takes either ['value' => 'Label'] or a list of
    ['value' => ..., 'label' => ...]; `grouped` takes ['Group' => [...options]].
--}}

@php
    $id ??= 'select-'.str()->random(6);
    $hasError = filled($error);

    $normalise = function (array $items): array {
        $out = [];

        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $out[$item['value']] = $item['label'];
            } else {
                $out[$key] = $item;
            }
        }

        return $out;
    };

    $flat = $normalise($options);
    $sizes = ['sm' => 'h-8 text-xs', 'md' => 'h-9 text-sm'];
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
        <select
            id="{{ $id }}"
            @if ($required) required @endif
            @if ($hasError) aria-invalid="true" @endif
            {{ $attributes->class([
                'w-full appearance-none rounded-md border bg-card pl-3 pr-8 text-foreground transition-colors',
                'disabled:cursor-not-allowed disabled:opacity-50',
                $sizes[$size] ?? $sizes['md'],
                'border-destructive' => $hasError,
                'border-input' => ! $hasError,
            ]) }}
        >
            @if ($placeholder)
                <option value="">{{ $placeholder }}</option>
            @endif

            @foreach ($flat as $value => $optionLabel)
                <option value="{{ $value }}" @selected((string) $value === (string) $selected)>
                    {{ $optionLabel }}
                </option>
            @endforeach

            @foreach ($grouped as $group => $items)
                <optgroup label="{{ $group }}">
                    @foreach ($normalise($items) as $value => $optionLabel)
                        <option value="{{ $value }}" @selected((string) $value === (string) $selected)>
                            {{ $optionLabel }}
                        </option>
                    @endforeach
                </optgroup>
            @endforeach

            {{ $slot }}
        </select>

        <x-ui.icon
            name="chevron-up-down"
            size="sm"
            class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground"
        />
    </div>

    @if ($hasError)
        <p class="text-xs text-destructive">{{ $error }}</p>
    @elseif ($hint)
        <p class="text-xs text-muted-foreground">{{ $hint }}</p>
    @endif
</div>
