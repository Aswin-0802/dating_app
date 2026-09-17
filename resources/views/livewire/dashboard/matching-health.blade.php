@php
    $healthClasses = [
        'healthy' => 'bg-success-subtle text-success-subtle-foreground',
        'warning' => 'bg-warning-subtle text-warning-subtle-foreground',
        'critical' => 'bg-destructive text-white',
    ];
@endphp

<div class="space-y-4 md:space-y-6">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        <x-ui.stat-card label="Matches" :value="veyra_compact_number($overview['matches'])" icon="heart" />
        <x-ui.stat-card label="Match → message" :value="veyra_percent($overview['match_to_message'])" icon="chat" />
        <x-ui.stat-card label="Message → reply" :value="veyra_percent($overview['message_to_reply'])" icon="arrow-path" />
        <x-ui.stat-card label="Matched in 48h" :value="veyra_percent($coldStart['rate'])" icon="fire" />
    </div>

    <x-ui.card
        title="Gender balance by city"
        description="The structural health metric. A city past 60/40 cannot produce matches for the majority side, however good the product is."
    >
        <div class="space-y-2.5">
            @foreach ($cityBalance as $city)
                <div class="flex items-center gap-3">
                    <span class="w-36 shrink-0 truncate text-sm">
                        {{ $city->city }}
                        <span class="text-xs text-muted-foreground">{{ $city->country }}</span>
                    </span>

                    <span class="tabular w-14 shrink-0 text-xs text-muted-foreground">
                        {{ veyra_compact_number($city->members) }}
                    </span>

                    <div class="relative h-5 min-w-0 flex-1 overflow-hidden rounded-full bg-chart-1">
                        <div class="absolute inset-y-0 left-0 bg-chart-3" style="width: {{ $city->man_share }}%"></div>
                        {{-- The midpoint marker makes skew readable without
                             reading the number. --}}
                        <div class="absolute inset-y-0 left-1/2 w-px bg-white/70"></div>
                    </div>

                    <span class="tabular w-12 shrink-0 text-right text-xs text-muted-foreground">{{ $city->man_share }}%</span>

                    <span class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-medium {{ $healthClasses[$city->health] }}">
                        {{ ucfirst($city->health) }}
                    </span>
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex items-center gap-4 text-xs text-muted-foreground">
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-chart-3"></span> Men</span>
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-chart-1"></span> Women</span>
            <span>Marker at 50%</span>
        </div>
    </x-ui.card>

    <x-ui.card
        title="Attention concentration"
        description="How unevenly likes are distributed. A uniform marketplace would put the top decile at 10%."
    >
        <div class="grid gap-6 sm:grid-cols-3">
            @foreach ([
                ['Top 10% of profiles', $concentration['top_decile_share'], 10],
                ['Top 25% of profiles', $concentration['top_quartile_share'], 25],
            ] as [$label, $value, $fair])
                <div>
                    <p class="text-sm text-muted-foreground">{{ $label }}</p>
                    <p class="tabular mt-1 text-3xl font-bold">{{ veyra_percent($value) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ round($value / $fair, 1) }}× a fair share</p>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                        <div class="h-full rounded-full bg-risk-high" style="width: {{ min(100, $value) }}%"></div>
                    </div>
                </div>
            @endforeach

            <div>
                <p class="text-sm text-muted-foreground">Median likes received</p>
                <p class="tabular mt-1 text-3xl font-bold">{{ $concentration['median_likes'] }}</p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Across {{ veyra_compact_number($concentration['population'] ?? 0) }} profiles
                </p>
            </div>
        </div>
    </x-ui.card>
</div>
