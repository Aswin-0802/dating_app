@props([
    'selected' => false,
    'href' => null,
    'tint' => null,
])

{{--
    Selection is a data attribute, not a class toggle, so Livewire can bind it
    with a single expression and CSS owns the appearance.

    `tint` takes a complete class string (typically RiskBand::rowClasses()) —
    never a fragment assembled at runtime, which Tailwind would purge.
--}}

<tr
    @if ($selected) data-state="selected" @endif
    @if ($href) wire:navigate.hover @endif
    {{ $attributes->class([
        'border-b border-border transition-colors last:border-0',
        'hover:bg-muted/50 data-[state=selected]:bg-muted',
        'cursor-pointer' => (bool) $href,
        $tint => filled($tint),
    ]) }}
>
    {{ $slot }}
</tr>
