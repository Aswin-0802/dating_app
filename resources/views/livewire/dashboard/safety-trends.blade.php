@php
    use App\Enums\LadderStep;
    use App\Enums\ReportCategory;
@endphp

<div class="space-y-4 md:space-y-6">
    <div class="grid gap-4 sm:grid-cols-3 md:gap-6">
        <x-ui.stat-card
            label="Report rate / 1k matches"
            :value="$overview['report_rate_per_1k_matches']"
            icon="flag"
            invert-delta
            hint="Trend this — a rise is the earliest quality alarm"
        />
        <x-ui.stat-card label="Open cases" :value="veyra_number($overview['open_cases'])" icon="inbox" />
        <x-ui.stat-card label="Enforcement in force" :value="veyra_number($overview['active_bans'])" icon="ban" />
    </div>

    <x-ui.card
        title="Reports and enforcement"
        description="Reports received and actions taken each day."
    >
        <x-ui.chart
            type="line"
            :height="300"
            :categories="$safety['dates']"
            :series="[
                ['name' => 'Reports filed', 'data' => $safety['reports']],
                ['name' => 'Actions taken', 'data' => $safety['actions']],
            ]"
            :options="[
                'xaxis' => ['type' => 'datetime', 'tickAmount' => 8],
                'stroke' => ['width' => 2],
                'legend' => ['show' => true, 'position' => 'top', 'horizontalAlign' => 'right'],
            ]"
        />
    </x-ui.card>

    <div class="grid gap-4 md:gap-6 xl:grid-cols-2">
        <x-ui.card title="Reports by category" description="Report categories, most common first.">
            @php $maxCategory = max($byCategory ?: [1]); @endphp

            <div class="space-y-2">
                @foreach ($byCategory as $category => $count)
                    @php $enum = ReportCategory::tryFrom($category); @endphp

                    <div class="flex items-center gap-3">
                        <span class="w-52 shrink-0 truncate text-sm">{{ $enum?->label() ?? $category }}</span>
                        <div class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-muted">
                            <div class="h-full rounded-full bg-chart-1"
                                style="width: {{ $count / $maxCategory * 100 }}%"></div>
                        </div>
                        <span class="tabular w-12 shrink-0 text-right text-xs text-muted-foreground">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card title="Enforcement by rung" description="How often each type of action is used.">
            @php $maxStep = max($byLadderStep ?: [1]); @endphp

            <div class="space-y-2">
                @foreach ($byLadderStep as $stepValue => $count)
                    @php $enum = LadderStep::tryFrom($stepValue); @endphp

                    <div class="flex items-center gap-3">
                        <span class="w-40 shrink-0 truncate text-sm">{{ $enum?->label() ?? $stepValue }}</span>
                        <div class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-muted">
                            <div class="h-full rounded-full bg-chart-2"
                                style="width: {{ $count / $maxStep * 100 }}%"></div>
                        </div>
                        <span class="tabular w-12 shrink-0 text-right text-xs text-muted-foreground">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>
</div>
