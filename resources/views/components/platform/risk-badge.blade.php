@props([
    'score' => 0,
    'band' => null,
    'factors' => null,
    'showScore' => true,
])

{{--
    Risk badge with an expandable factor breakdown.

    A score with no explanation gets ignored by moderators and is indefensible in
    an appeal, so the breakdown is never optional when factors are available. The
    factors shown are the STORED rows that produced the score — they are not
    recomputed here, which is what guarantees they always sum to the number on
    the badge.
--}}

@php
    use App\Enums\RiskBand;

    $score = (int) $score;
    $band = $band instanceof RiskBand ? $band : RiskBand::fromScore($score);
    $factors = $factors ? collect($factors) : null;
    $expandable = $factors !== null && $factors->isNotEmpty();
@endphp

@if (! $expandable)
    <span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', $band->badgeClasses()]) }}>
        <span class="size-1.5 rounded-full bg-current"></span>
        {{ $band->label() }}
        @if ($showScore)
            <span class="tabular opacity-70">{{ $score }}</span>
        @endif
    </span>
@else
    <div x-data="{ open: false }" class="relative inline-block">
        <button
            type="button"
            @click="open = !open"
            @click.outside="open = false"
            {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium transition-opacity hover:opacity-80', $band->badgeClasses()]) }}
        >
            <span class="size-1.5 rounded-full bg-current"></span>
            {{ $band->label() }}
            @if ($showScore)
                <span class="tabular opacity-70">{{ $score }}</span>
            @endif
            <x-ui.icon name="chevron-down" size="xs" class="opacity-60" />
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            class="absolute left-0 z-40 mt-1.5 w-80 origin-top-left overflow-hidden rounded-lg border border-border bg-popover text-popover-foreground shadow-lg"
        >
            <div class="flex items-center justify-between border-b border-border px-3 py-2">
                <span class="text-xs font-medium">Risk factors</span>
                <span class="tabular text-xs text-muted-foreground">
                    {{ $score }} / 100 · {{ $band->range() }}
                </span>
            </div>

            <div class="max-h-72 overflow-y-auto p-1">
                @foreach ($factors->sortByDesc(fn ($factor) => abs((int) ($factor['points'] ?? $factor->points ?? 0))) as $factor)
                    @php
                        $points = (int) ($factor['points'] ?? $factor->points ?? 0);
                        $label = $factor['label'] ?? $factor->label ?? '—';
                    @endphp

                    <div class="flex items-start justify-between gap-3 rounded-sm px-2 py-1.5 hover:bg-muted">
                        <span class="min-w-0 text-xs leading-snug text-foreground">{{ $label }}</span>

                        {{-- Mitigating factors read as credits, not smaller penalties. --}}
                        <span @class([
                            'tabular shrink-0 text-xs font-medium',
                            'text-destructive' => $points > 0,
                            'text-success' => $points < 0,
                            'text-muted-foreground' => $points === 0,
                        ])>{{ $points > 0 ? '+' : '' }}{{ $points }}</span>
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-between border-t border-border bg-muted/40 px-3 py-2">
                <span class="text-xs text-muted-foreground">Total</span>
                <span class="tabular text-xs font-semibold text-foreground">{{ $score }}</span>
            </div>
        </div>
    </div>
@endif
