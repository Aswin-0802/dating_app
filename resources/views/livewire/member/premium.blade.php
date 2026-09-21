@php
    use App\Support\Branding;

    $currency = App\Support\Currency::symbol();
    $ios = Branding::get('app.ios_url');
    $android = Branding::get('app.android_url');
    $support = Branding::get('brand.support_email');
    $current = $me->activePlan();
    $plans = App\Support\Masters::plans();
@endphp

<div class="space-y-6">
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-primary to-accent p-6 text-primary-foreground sm:p-8">
        <div aria-hidden="true" class="absolute -right-16 -top-16 size-56 rounded-full bg-white/10 blur-2xl"></div>
        <x-ui.icon name="bolt" size="xl" class="relative" />
        @if ($current)
            <h1 class="relative mt-3 text-3xl font-bold tracking-tight">You are on {{ $current->name }}</h1>
            <p class="relative mt-1 text-primary-foreground/85">
                @if ($me->premium_until)
                    Renews {{ $me->premium_until->format('j F Y') }}.
                @endif
                Thanks for supporting {{ Branding::name() }}.
            </p>
        @else
            <h1 class="relative mt-3 text-3xl font-bold tracking-tight">Meet people faster</h1>
            <p class="relative mt-1 max-w-md text-primary-foreground/85">
                Free always covers matching, messaging and safety. Premium removes the daily limit and shows you who already likes you.
                @if ($likesLeft !== null) You have {{ $likesLeft }} {{ Str::plural('like', $likesLeft) }} left today. @endif
            </p>
        @endif
    </div>

    @if ($plans->isNotEmpty())
        <div @class(['grid gap-4', 'sm:grid-cols-2' => $plans->count() === 2, 'sm:grid-cols-2 lg:grid-cols-3' => $plans->count() > 2])>
            @foreach ($plans as $plan)
                @php $isCurrent = $current?->is($plan); @endphp
                <div @class([
                    'flex flex-col rounded-3xl border bg-card p-6',
                    'border-primary ring-1 ring-primary' => $isCurrent,
                    'border-border' => ! $isCurrent,
                ])>
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="flex items-center gap-2 text-lg font-semibold">
                            <span class="size-2.5 rounded-full" style="background-color: {{ $plan->badge_color }}"></span>
                            {{ $plan->name }}
                        </h2>
                        @if ($isCurrent)
                            <x-ui.badge variant="primary">Your plan</x-ui.badge>
                        @elseif ($plan->is_featured)
                            <x-ui.badge variant="accent">Most popular</x-ui.badge>
                        @endif
                    </div>
                    @if ($plan->tagline)
                        <p class="mt-1 text-sm text-muted-foreground">{{ $plan->tagline }}</p>
                    @endif
                    <p class="mt-3"><span class="text-3xl font-bold">{{ App\Support\Currency::format($plan->monthly_price) }}</span> <span class="text-sm text-muted-foreground">/ month</span></p>
                    @if ($plan->yearly_price !== null)
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            or {{ App\Support\Currency::format($plan->yearly_price) }} a year
                            @if ($saving = $plan->yearlySavingPercent()) <span class="font-medium text-success">(save {{ $saving }}%)</span> @endif
                        </p>
                    @endif
                    <ul class="mt-5 flex-1 space-y-2.5 text-sm">
                        @foreach ($plan->benefitLines() as $line)
                            <li class="flex gap-2"><x-ui.icon name="check" size="sm" class="mt-0.5 text-primary" /> {{ $line }}</li>
                        @endforeach
                    </ul>

                    @if ($gateways->isNotEmpty())
                        <div class="mt-5 space-y-2">
                            @foreach ([['monthly', $plan->monthly_price, 'month'], ['yearly', $plan->yearly_price, 'year']] as [$period, $price, $unit])
                                @if ($price !== null)
                                    <form method="POST" action="{{ route('member.checkout.start') }}">
                                        @csrf
                                        <input type="hidden" name="plan" value="{{ $plan->slug }}">
                                        <input type="hidden" name="period" value="{{ $period }}">

                                        @if ($gateways->count() > 1)
                                            <label class="sr-only" for="gw-{{ $plan->slug }}-{{ $period }}">Pay with</label>
                                            <select
                                                id="gw-{{ $plan->slug }}-{{ $period }}"
                                                name="gateway"
                                                class="mb-2 h-9 w-full rounded-md border border-input bg-card px-3 text-sm"
                                            >
                                                @foreach ($gateways as $gateway)
                                                    <option value="{{ $gateway->slug }}">Pay with {{ $gateway->name }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <input type="hidden" name="gateway" value="{{ $gateways->first()->slug }}">
                                        @endif

                                        <x-ui.button
                                            type="submit"
                                            class="w-full"
                                            :variant="$period === 'monthly' ? 'default' : 'outline'"
                                        >
                                            {{ $isCurrent ? 'Extend' : 'Get' }} {{ $plan->name }} —
                                            {{ App\Support\Currency::format($price) }} / {{ $unit }}
                                        </x-ui.button>
                                    </form>
                                @endif
                            @endforeach

                            @if ($gateways->contains(fn ($g) => $g->is_test_mode))
                                <p class="text-center text-[11px] text-muted-foreground">Test mode — no money will be taken.</p>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($gateways->isEmpty() && ! $current && $plans->isNotEmpty())
        <div class="rounded-3xl border border-border bg-card p-6 text-center">
            <p class="font-semibold">How to upgrade</p>
            @if ($ios || $android)
                <p class="mt-1 text-sm text-muted-foreground">Upgrades are handled through the app store, so you can manage or cancel them from your phone.</p>
                <div class="mt-4 flex flex-wrap justify-center gap-2">
                    @if ($ios) <x-site.store-badge store="ios" :href="$ios" /> @endif
                    @if ($android) <x-site.store-badge store="android" :href="$android" /> @endif
                </div>
            @elseif ($support)
                <p class="mt-1 text-sm text-muted-foreground">Email us and we will set it up for you.</p>
                <x-ui.button class="mt-4" :href="'mailto:'.$support.'?subject='.rawurlencode(Branding::name().' Premium')">Contact {{ $support }}</x-ui.button>
            @else
                <p class="mt-1 text-sm text-muted-foreground">Premium is coming soon.</p>
            @endif
        </div>
    @endif

    @if ($orders->isNotEmpty())
        <div class="rounded-3xl border border-border bg-card p-6">
            <h2 class="text-base font-semibold">Your payments</h2>
            <ul class="mt-3 divide-y divide-border text-sm">
                @foreach ($orders as $order)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{{ $order->description }}</span>
                            <span class="block text-xs text-muted-foreground">{{ veyra_datetime($order->created_at) }}</span>
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="tabular">{{ $order->formattedAmount() }}</span>
                            @if ($order->isPaid())
                                <x-ui.badge size="sm" variant="success">Paid</x-ui.badge>
                            @elseif ($order->status === 'pending')
                                <a href="{{ route('member.checkout.show', $order) }}" class="text-xs font-medium text-primary hover:underline">Waiting — check</a>
                            @else
                                <x-ui.badge size="sm" variant="muted">{{ ucfirst($order->status) }}</x-ui.badge>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
