<div class="space-y-4 md:space-y-6">
    <x-ui.card
        title="Retention, verified against unverified"
        description="Share of members who come back 1, 7 and 30 days after signing up."
    >
        <div class="grid gap-6 sm:grid-cols-2">
            @foreach (['verified' => 'Verified', 'unverified' => 'Unverified'] as $key => $label)
                <div>
                    <div class="mb-3 flex items-center justify-between">
                        <p class="font-medium">{{ $label }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ platform_compact_number($retention[$key]['cohort']) }} members
                        </p>
                    </div>

                    <div class="space-y-2">
                        @foreach (['d1' => 'Day 1', 'd7' => 'Day 7', 'd30' => 'Day 30'] as $day => $dayLabel)
                            <div>
                                <div class="mb-1 flex items-center justify-between text-sm">
                                    <span class="text-muted-foreground">{{ $dayLabel }}</span>
                                    <span class="tabular font-medium">{{ platform_percent($retention[$key][$day]) }}</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full {{ $key === 'verified' ? 'bg-success' : 'bg-chart-3' }}"
                                        style="width: {{ max(1, $retention[$key][$day]) }}%"
                                    ></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        @php $d30Gap = round($retention['verified']['d30'] - $retention['unverified']['d30'], 1); @endphp

        @if ($d30Gap != 0)
            <div class="mt-5 rounded-lg border border-border bg-muted/40 p-3">
                <p class="text-sm">
                    Verified members retain
                    <span class="font-semibold {{ $d30Gap > 0 ? 'text-success-subtle-foreground' : 'text-destructive-subtle-foreground' }}">
                        {{ abs($d30Gap) }} points {{ $d30Gap > 0 ? 'better' : 'worse' }}
                    </span>
                    at day 30.
                </p>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card title="Signups" description="Last 180 days.">
        <x-ui.chart
            type="area"
            :height="280"
            :categories="$signups['dates']"
            :series="[['name' => 'Signups', 'data' => $signups['signups']]]"
            :options="[
                'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.35, 'opacityTo' => 0.02]],
                'xaxis' => ['type' => 'datetime', 'tickAmount' => 8],
                'stroke' => ['width' => 2],
            ]"
        />
    </x-ui.card>
</div>
