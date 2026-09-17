@php
    $healthClasses = [
        'healthy' => 'bg-success-subtle text-success-subtle-foreground',
        'warning' => 'bg-warning-subtle text-warning-subtle-foreground',
        'critical' => 'bg-destructive text-white',
    ];
@endphp

<div class="space-y-4 md:space-y-6">

    {{-- ---- headline ---------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        <x-ui.stat-card
            label="Active members"
            :value="veyra_compact_number($overview['active_members'])"
            icon="users"
            :href="route('admin.users.index')"
            :hint="veyra_compact_number($overview['mau']).' active in the last 30 days'"
        />

        <x-ui.stat-card
            label="Match → conversation"
            :value="veyra_percent($overview['match_to_message'])"
            icon="chat"
            hint="Matches where somebody sent a message"
        />

        <x-ui.stat-card
            label="Report rate / 1k matches"
            :value="$overview['report_rate_per_1k_matches']"
            icon="flag"
            invert-delta
            hint="The earliest quality alarm there is"
        />

        <x-ui.stat-card
            label="Verification coverage"
            :value="veyra_percent($overview['verification_coverage'])"
            icon="shield-check"
            :href="route('admin.verifications.index')"
            :hint="veyra_compact_number($overview['verified_members']).' verified members'"
        />
    </div>

    {{-- ---- queue load -------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-3 md:gap-6">
        <x-ui.stat-card label="Open cases" :value="veyra_number($overview['open_cases'])" icon="flag"
            :href="route('admin.cases.index')" />
        <x-ui.stat-card label="Awaiting verification" :value="veyra_number($overview['open_verifications'])"
            icon="shield-check" :href="route('admin.verifications.index')" />
        <x-ui.stat-card label="Enforcement in force" :value="veyra_number($overview['active_bans'])" icon="ban"
            :href="route('admin.enforcement.bans')" />
    </div>

    <div class="grid gap-4 md:gap-6 xl:grid-cols-2">

        {{-- ---- funnel -------------------------------------------------- --}}
        <x-ui.card
            title="Member funnel"
            description="Where people fall out. Step-to-step conversion matters more than the total."
        >
            <div class="space-y-2.5">
                @foreach ($funnel as $stage)
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                            <span class="min-w-0 truncate">{{ $stage['step'] }}</span>
                            <span class="tabular shrink-0 text-muted-foreground">
                                {{ veyra_compact_number($stage['count']) }}
                                <span class="ml-1 font-medium text-foreground">{{ $stage['rate'] }}%</span>
                            </span>
                        </div>

                        <div class="h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full bg-primary transition-all"
                                style="width: {{ max(1, $stage['rate']) }}%"
                            ></div>
                        </div>

                        @if (! $loop->first && $stage['step_rate'] < 70)
                            {{-- Only annotate the steps that are actually losing
                                 people; labelling every step trains the eye to
                                 skip them all. --}}
                            <p class="mt-0.5 text-xs text-warning-subtle-foreground">
                                {{ 100 - $stage['step_rate'] }}% drop from the previous step
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        {{-- ---- city balance -------------------------------------------- --}}
        <x-ui.card
            title="Gender balance by city"
            description="A city past 60/40 cannot produce matches for the majority side, however good the product is."
        >
            <div class="space-y-2.5">
                @foreach ($cityBalance as $city)
                    <div class="flex items-center gap-3">
                        <span class="w-28 shrink-0 truncate text-sm">
                            {{ $city->city }}
                            <span class="text-xs text-muted-foreground">{{ $city->country }}</span>
                        </span>

                        <div class="relative h-5 min-w-0 flex-1 overflow-hidden rounded-full bg-chart-1">
                            <div class="absolute inset-y-0 left-0 bg-chart-3" style="width: {{ $city->man_share }}%"></div>
                            {{-- The 50% marker makes skew readable at a glance
                                 without reading the number. --}}
                            <div class="absolute inset-y-0 left-1/2 w-px bg-white/70"></div>
                        </div>

                        <span class="tabular w-12 shrink-0 text-right text-xs text-muted-foreground">
                            {{ $city->man_share }}%
                        </span>

                        <span class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-medium {{ $healthClasses[$city->health] }}">
                            {{ ucfirst($city->health) }}
                        </span>
                    </div>
                @endforeach
            </div>

            <div class="mt-3 flex items-center gap-4 text-xs text-muted-foreground">
                <span class="flex items-center gap-1.5">
                    <span class="size-2 rounded-full bg-chart-3"></span> Men
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="size-2 rounded-full bg-chart-1"></span> Women
                </span>
            </div>
        </x-ui.card>

        {{-- ---- signups ------------------------------------------------- --}}
        <x-ui.card title="Signups" description="Last 90 days.">
            <x-ui.chart
                type="area"
                :height="240"
                :categories="$signups['dates']"
                :series="[['name' => 'Signups', 'data' => $signups['signups']]]"
                :options="[
                    'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.35, 'opacityTo' => 0.02]],
                    'xaxis' => ['type' => 'datetime', 'tickAmount' => 6],
                    'stroke' => ['width' => 2],
                ]"
            />
        </x-ui.card>

        {{-- ---- safety load --------------------------------------------- --}}
        <x-ui.card
            title="Safety load"
            description="Reports filed against enforcement issued, last 60 days."
        >
            <x-ui.chart
                type="line"
                :height="240"
                :categories="$safety['dates']"
                :series="[
                    ['name' => 'Reports', 'data' => $safety['reports']],
                    ['name' => 'Actions', 'data' => $safety['actions']],
                ]"
                :options="[
                    'xaxis' => ['type' => 'datetime', 'tickAmount' => 6],
                    'stroke' => ['width' => 2],
                    'legend' => ['show' => true, 'position' => 'top', 'horizontalAlign' => 'right'],
                ]"
            />
        </x-ui.card>
    </div>

    <div class="grid gap-4 md:gap-6 xl:grid-cols-3">

        {{-- ---- concentration ------------------------------------------- --}}
        <x-ui.card
            title="Attention concentration"
            description="Under a uniform model the top decile would receive 10%."
        >
            <div class="space-y-4">
                <div>
                    <div class="flex items-end justify-between gap-2">
                        <span class="text-sm text-muted-foreground">Likes to the top 10% of profiles</span>
                        <span class="tabular text-2xl font-bold">
                            {{ veyra_percent($concentration['top_decile_share']) }}
                        </span>
                    </div>

                    <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-muted">
                        <div class="h-full rounded-full bg-risk-high"
                            style="width: {{ min(100, $concentration['top_decile_share']) }}%"></div>
                    </div>

                    <p class="mt-1.5 text-xs text-muted-foreground">
                        {{ round($concentration['top_decile_share'] / 10, 1) }}× their share. Runaway
                        concentration predicts churn among everyone else.
                    </p>
                </div>

                <dl class="divide-y divide-border text-sm">
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-muted-foreground">Top quartile share</dt>
                        <dd class="tabular font-medium">{{ veyra_percent($concentration['top_quartile_share']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-muted-foreground">Median likes received</dt>
                        <dd class="tabular font-medium">{{ $concentration['median_likes'] }}</dd>
                    </div>
                </dl>
            </div>
        </x-ui.card>

        {{-- ---- cold start ---------------------------------------------- --}}
        <x-ui.card
            title="Cold start"
            description="New members matched within 48 hours."
        >
            <div class="flex flex-col items-center justify-center py-2">
                <p class="tabular text-4xl font-bold">{{ veyra_percent($coldStart['rate']) }}</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ veyra_number($coldStart['matched_in_48h']) }} of
                    {{ veyra_number($coldStart['cohort']) }} recent signups
                </p>
            </div>

            <p class="mt-3 text-xs text-muted-foreground">
                Somebody who gets no match in their first two days rarely returns, and
                no amount of later polish recovers them.
            </p>
        </x-ui.card>

        {{-- ---- retention ----------------------------------------------- --}}
        <x-ui.card
            title="Retention by verification"
            description="The business case for stricter verification."
        >
            <div class="space-y-3">
                @foreach (['verified' => 'Verified', 'unverified' => 'Unverified'] as $key => $label)
                    <div>
                        <div class="mb-1.5 flex items-center justify-between text-sm">
                            <span class="font-medium">{{ $label }}</span>
                            <span class="text-xs text-muted-foreground">
                                {{ veyra_compact_number($retention[$key]['cohort']) }} members
                            </span>
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            @foreach (['d1' => 'D1', 'd7' => 'D7', 'd30' => 'D30'] as $day => $dayLabel)
                                <div class="rounded-lg border border-border px-2 py-1.5 text-center">
                                    <p class="text-[10px] uppercase tracking-wide text-muted-foreground">{{ $dayLabel }}</p>
                                    <p class="tabular text-sm font-semibold">
                                        {{ veyra_percent($retention[$key][$day], 0) }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>
</div>
