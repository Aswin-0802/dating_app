@props([
    'label',
    'value',
    'icon' => null,
    'delta' => null,
    'deltaLabel' => 'vs. previous period',
    'invertDelta' => false,
    'href' => null,
    'sparkline' => null,
    'hint' => null,
])

{{--
    Anatomy: icon chip -> label -> value -> delta pill -> optional sparkline.

    `invertDelta` matters for safety metrics: a rising report rate is bad news, so
    the same +12% that is green on signups must be red there.
--}}

@php
    $hasDelta = $delta !== null;
    $rising = $hasDelta && (float) $delta > 0;
    $flat = $hasDelta && (float) $delta == 0;
    $good = $invertDelta ? ! $rising : $rising;

    $deltaClasses = match (true) {
        ! $hasDelta, $flat => 'bg-muted text-muted-foreground',
        $good => 'bg-success-subtle text-success-subtle-foreground',
        default => 'bg-destructive-subtle text-destructive-subtle-foreground',
    };

    $deltaIcon = match (true) {
        $flat => 'minus',
        $rising => 'arrow-up-right',
        default => 'arrow-down-right',
    };

    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class([
        'block rounded-xl border border-border bg-card p-5 shadow-sm transition-colors md:p-6',
        'dark:bg-white/[0.03]',
        'hover:border-primary/40' => (bool) $href,
    ]) }}
>
    <div class="flex items-start justify-between gap-3">
        @if ($icon)
            <div class="flex size-12 items-center justify-center rounded-xl bg-muted text-muted-foreground">
                <x-ui.icon :name="$icon" size="md" />
            </div>
        @endif

        @if ($hasDelta)
            <span class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-medium {{ $deltaClasses }}">
                <x-ui.icon :name="$deltaIcon" size="xs" />
                {{ abs((float) $delta) }}%
            </span>
        @endif
    </div>

    <div class="mt-4 space-y-1">
        <p class="text-sm text-muted-foreground">{{ $label }}</p>

        <div class="flex items-end justify-between gap-3">
            <p class="tabular text-2xl font-bold leading-none text-foreground md:text-3xl">{{ $value }}</p>

            @if ($sparkline)
                <div class="shrink-0 pb-0.5">{{ $sparkline }}</div>
            @endif
        </div>
    </div>

    @if ($hasDelta || $hint)
        <p class="mt-2 text-xs text-muted-foreground">{{ $hint ?? $deltaLabel }}</p>
    @endif
</{{ $tag }}>
