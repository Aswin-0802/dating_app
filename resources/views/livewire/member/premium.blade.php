@php
    use App\Support\Branding;

    $currency = Branding::get('website.currency_symbol', '£');
    $ios = Branding::get('app.ios_url');
    $android = Branding::get('app.android_url');
    $support = Branding::get('brand.support_email');
    $tier = $me->is_premium ? ($me->premium_tier ?? 'plus') : 'free';

    $plans = [
        'plus' => [
            'name' => 'Plus',
            'price' => Branding::get('website.plus_price', '12.99'),
            'features' => ['Unlimited likes', 'See who has already liked you', 'Everything in Free'],
        ],
        'gold' => [
            'name' => 'Gold',
            'price' => Branding::get('website.gold_price', '24.99'),
            'features' => ['Everything in Plus', 'Gold badge on your profile', 'Priority support'],
        ],
    ];
@endphp

<div class="space-y-6">
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-primary to-accent p-6 text-primary-foreground sm:p-8">
        <div aria-hidden="true" class="absolute -right-16 -top-16 size-56 rounded-full bg-white/10 blur-2xl"></div>
        <x-ui.icon name="bolt" size="xl" class="relative" />
        @if ($me->is_premium)
            <h1 class="relative mt-3 text-3xl font-bold tracking-tight">You are on {{ ucfirst($tier) }}</h1>
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

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($plans as $key => $plan)
            <div @class([
                'flex flex-col rounded-3xl border bg-card p-6',
                'border-primary ring-1 ring-primary' => $tier === $key,
                'border-border' => $tier !== $key,
            ])>
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">{{ $plan['name'] }}</h2>
                    @if ($tier === $key)
                        <x-ui.badge variant="primary">Your plan</x-ui.badge>
                    @endif
                </div>
                <p class="mt-3"><span class="text-3xl font-bold">{{ $currency }}{{ $plan['price'] }}</span> <span class="text-sm text-muted-foreground">/ month</span></p>
                <ul class="mt-5 flex-1 space-y-2.5 text-sm">
                    @foreach ($plan['features'] as $feature)
                        <li class="flex gap-2"><x-ui.icon name="check" size="sm" class="mt-0.5 text-primary" /> {{ $feature }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>

    @unless ($me->is_premium)
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
    @endunless
</div>
