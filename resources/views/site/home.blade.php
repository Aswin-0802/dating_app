@php
    use App\Support\Branding;

    $name = Branding::name();
    $currency = Branding::get('website.currency_symbol', '£');
    $freeLikes = (int) veyra_setting('matching.daily_like_limit_free', 100);
    $minAge = (int) veyra_setting('general.min_age', 18);
    $verificationHours = (int) veyra_setting('verification.sla_hours', 24);
@endphp

<x-layouts.site>
    {{-- ---- hero ----------------------------------------------------------- --}}
    <section class="relative overflow-hidden">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div class="absolute -top-40 left-1/2 size-[42rem] -translate-x-1/2 rounded-full bg-primary/15 blur-3xl"></div>
            <div class="absolute -right-40 top-40 size-96 rounded-full bg-accent/15 blur-3xl"></div>
        </div>

        <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-4 pb-16 pt-12 sm:px-6 md:pt-20 lg:grid-cols-[1.1fr_1fr] lg:pb-24">
            <div class="space-y-6">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-primary-subtle px-3 py-1 text-xs font-medium text-primary-subtle-foreground">
                    <x-ui.icon name="check-badge" size="sm" />
                    Every profile photo-verified by a person
                </span>

                <h1 class="text-4xl font-bold leading-[1.08] tracking-tight text-balance sm:text-5xl lg:text-6xl">
                    {{ Branding::get('website.hero_title', 'Meet people who are exactly who they say they are.') }}
                </h1>

                <p class="max-w-xl text-lg text-muted-foreground text-pretty">
                    {{ Branding::get('website.hero_subtitle') }}
                </p>

                <div class="flex flex-col gap-3 sm:flex-row">
                    <x-ui.button size="lg" :href="route('member.register')" class="h-12 px-7 text-base">
                        Join free
                        <x-ui.icon name="arrow-right" size="sm" />
                    </x-ui.button>
                    <x-ui.button size="lg" variant="outline" href="#how" class="h-12 px-7 text-base">See how it works</x-ui.button>
                </div>

                <ul class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-muted-foreground">
                    <li class="flex items-center gap-1.5"><x-ui.icon name="check" size="sm" class="text-success" /> Free to join</li>
                    <li class="flex items-center gap-1.5"><x-ui.icon name="check" size="sm" class="text-success" /> {{ $minAge }}+ only</li>
                    <li class="flex items-center gap-1.5"><x-ui.icon name="check" size="sm" class="text-success" /> Report or block in two taps</li>
                </ul>
            </div>

            {{-- Illustrated, never real members: a marketing page is not a place
                 anybody agreed to have their profile shown. --}}
            <div class="relative mx-auto h-[30rem] w-full max-w-sm" aria-hidden="true">
                <div class="absolute inset-x-6 top-6 h-[26rem] rotate-6 rounded-3xl bg-gradient-to-br from-accent/70 to-primary/50 shadow-xl"></div>

                <div class="absolute inset-x-0 top-0 h-[27rem] overflow-hidden rounded-3xl border border-border bg-card shadow-2xl">
                    <div class="relative h-full bg-gradient-to-br from-primary via-primary/80 to-accent">
                        <svg viewBox="0 0 200 240" class="absolute inset-x-0 bottom-24 mx-auto w-3/5 text-white/25" fill="currentColor">
                            <circle cx="100" cy="80" r="46" />
                            <path d="M20 240c0-60 36-100 80-100s80 40 80 100Z" />
                        </svg>

                        <div class="absolute inset-x-0 top-3 flex gap-1 px-3">
                            <span class="h-1 flex-1 rounded-full bg-white"></span>
                            <span class="h-1 flex-1 rounded-full bg-white/40"></span>
                            <span class="h-1 flex-1 rounded-full bg-white/40"></span>
                        </div>

                        <div class="absolute inset-x-0 bottom-0 space-y-3 bg-gradient-to-t from-black/70 to-transparent p-5 pt-16 text-white">
                            <div>
                                <p class="flex items-center gap-2 text-2xl font-bold">
                                    Maya, 29
                                    <x-ui.icon name="check-badge" size="lg" class="text-sky-300" />
                                </p>
                                <p class="flex items-center gap-1.5 text-sm text-white/85">
                                    <x-ui.icon name="briefcase" size="sm" /> Product designer · 3 km away
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-1.5 text-xs">
                                <span class="rounded-full bg-white/20 px-2.5 py-1 backdrop-blur">Climbing</span>
                                <span class="rounded-full bg-white/20 px-2.5 py-1 backdrop-blur">Live music</span>
                                <span class="rounded-full bg-white/20 px-2.5 py-1 backdrop-blur">Ramen</span>
                            </div>
                            <div class="flex items-center justify-center gap-4 pt-1">
                                <span class="flex size-12 items-center justify-center rounded-full bg-white text-muted-foreground shadow-lg"><x-ui.icon name="x-mark" size="lg" /></span>
                                <span class="flex size-10 items-center justify-center rounded-full bg-white text-sky-500 shadow-lg"><x-ui.icon name="star" size="md" /></span>
                                <span class="flex size-12 items-center justify-center rounded-full bg-white text-primary shadow-lg"><x-ui.icon name="heart" size="lg" /></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="absolute -left-4 top-16 flex items-center gap-2 rounded-2xl border border-border bg-card px-3.5 py-2.5 shadow-lg sm:-left-10">
                    <span class="flex size-8 items-center justify-center rounded-full bg-success-subtle text-success-subtle-foreground"><x-ui.icon name="shield-check" size="sm" /></span>
                    <span class="text-sm leading-tight"><span class="block font-semibold">Verified</span><span class="block text-xs text-muted-foreground">Checked by our team</span></span>
                </div>

                <div class="absolute -right-2 bottom-10 flex items-center gap-2 rounded-2xl border border-border bg-card px-3.5 py-2.5 shadow-lg sm:-right-8">
                    <span class="flex size-8 items-center justify-center rounded-full bg-primary-subtle text-primary-subtle-foreground"><x-ui.icon name="heart" size="sm" /></span>
                    <span class="text-sm font-semibold">It's a match!</span>
                </div>
            </div>
        </div>
    </section>

    {{-- ---- live proof ------------------------------------------------------ --}}
    @if ($stats)
        <section class="border-y border-border bg-muted/30">
            <dl class="mx-auto grid max-w-6xl grid-cols-3 divide-x divide-border px-4 py-8 text-center sm:px-6">
                @foreach ($stats as $label => $value)
                    <div class="px-2">
                        <dt class="text-xs text-muted-foreground sm:text-sm">{{ $label }}</dt>
                        <dd class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    {{-- ---- how it works ---------------------------------------------------- --}}
    <section id="how" class="scroll-mt-20">
        <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-semibold text-primary">How it works</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">Three steps to a real first date</h2>
                <p class="mt-3 text-muted-foreground">No endless swiping through profiles that turn out to be somebody else.</p>
            </div>

            <ol class="mt-12 grid gap-6 md:grid-cols-3">
                @foreach ([
                    ['icon' => 'user-circle', 'title' => 'Build your profile', 'body' => 'Photos, a few prompts and the things you are into. It takes about five minutes, and a complete profile gets shown to more people.'],
                    ['icon' => 'camera', 'title' => 'Verify with a selfie', 'body' => "Take one selfie copying a gesture code. A real person on our team compares it with your photos, usually within {$verificationHours} hours."],
                    ['icon' => 'chat', 'title' => 'Match and talk', 'body' => 'When you both like each other, a chat opens. Nobody can message you until you have matched.'],
                ] as $i => $step)
                    <li class="relative rounded-2xl border border-border bg-card p-6 shadow-sm">
                        <span class="absolute right-5 top-5 text-5xl font-bold text-muted/80">{{ $i + 1 }}</span>
                        <span class="flex size-11 items-center justify-center rounded-xl bg-primary-subtle text-primary-subtle-foreground">
                            <x-ui.icon :name="$step['icon']" size="md" />
                        </span>
                        <h3 class="mt-5 text-lg font-semibold">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ $step['body'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ---- safety ---------------------------------------------------------- --}}
    <section id="safety" class="scroll-mt-20 bg-sidebar text-sidebar-foreground">
        <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-2">
            <div class="space-y-5">
                <p class="text-sm font-semibold text-sidebar-primary">Safety</p>
                <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">Safety is not a feature here. It is the product.</h2>
                <p class="text-sidebar-foreground/75">
                    Behind every profile is a moderation team with real tools and real deadlines.
                    Reports are read by people, decisions are explained to you, and every decision can be appealed.
                </p>
                <x-ui.button variant="secondary" :href="route('site.safety')">
                    Visit the safety centre
                    <x-ui.icon name="arrow-right" size="sm" />
                </x-ui.button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ([
                    ['icon' => 'check-badge', 'title' => 'Human-checked photos', 'body' => 'Verification selfies are reviewed by a person and never shown on your profile.'],
                    ['icon' => 'flag', 'title' => 'Reports read by people', 'body' => 'The most serious reports jump the queue and are looked at within the hour.'],
                    ['icon' => 'ban', 'title' => 'Block means gone', 'body' => 'Block someone and you disappear from each other completely, match included.'],
                    ['icon' => 'lock', 'title' => 'Match before message', 'body' => 'No unsolicited messages. A chat only exists once you have both said yes.'],
                ] as $item)
                    <div class="rounded-2xl border border-sidebar-border bg-sidebar-accent/60 p-5">
                        <x-ui.icon :name="$item['icon']" size="lg" class="text-sidebar-primary" />
                        <h3 class="mt-4 font-semibold">{{ $item['title'] }}</h3>
                        <p class="mt-1.5 text-sm text-sidebar-foreground/70">{{ $item['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ---- features -------------------------------------------------------- --}}
    <section>
        <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-semibold text-primary">Made for meeting</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">Everything you need, nothing that wastes your evening</h2>
            </div>

            <div class="mt-12 grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['icon' => 'map-pin', 'title' => 'People near you', 'body' => 'Discovery starts in your city. Switch on global mode when you are travelling or open to distance.'],
                    ['icon' => 'shield-check', 'title' => 'Verified-only mode', 'body' => 'Only want to see people who have passed a photo check? One switch in your preferences.'],
                    ['icon' => 'sparkles', 'title' => 'Interests that match', 'body' => 'Pick what you are into and see what you have in common before you say hello.'],
                    ['icon' => 'star', 'title' => 'Superlikes', 'body' => 'Really keen? A superlike tells them before they have even seen your profile.'],
                    ['icon' => 'chat', 'title' => 'Conversations that stay yours', 'body' => 'Your chats are private. Staff only ever see them when investigating a report, and every look is logged.'],
                    ['icon' => 'scale', 'title' => 'Fair, explained decisions', 'body' => 'If we ever restrict your account we tell you exactly why, and a different person reviews your appeal.'],
                ] as $feature)
                    <div class="flex gap-4">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-subtle text-primary-subtle-foreground">
                            <x-ui.icon :name="$feature['icon']" size="md" />
                        </span>
                        <div>
                            <h3 class="font-semibold">{{ $feature['title'] }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-muted-foreground">{{ $feature['body'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ---- premium --------------------------------------------------------- --}}
    <section id="premium" class="scroll-mt-20 border-t border-border bg-muted/30">
        <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-semibold text-primary">Premium</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">Free is genuinely enough. Premium is faster.</h2>
                <p class="mt-3 text-muted-foreground">Matching, messaging and safety tools are never behind a paywall.</p>
            </div>

            @php
                $plans = [
                    [
                        'name' => 'Free',
                        'price' => null,
                        'blurb' => 'Everything you need to meet someone.',
                        'features' => ["{$freeLikes} likes a day", 'Unlimited messaging with matches', 'Photo verification', 'Every safety tool'],
                        'highlight' => false,
                    ],
                    [
                        'name' => 'Plus',
                        'price' => Branding::get('website.plus_price', '12.99'),
                        'blurb' => 'For people who know what they want.',
                        'features' => ['Unlimited likes', 'See who has already liked you', 'Everything in Free'],
                        'highlight' => true,
                    ],
                    [
                        'name' => 'Gold',
                        'price' => Branding::get('website.gold_price', '24.99'),
                        'blurb' => 'The full experience.',
                        'features' => ['Everything in Plus', 'Gold badge on your profile', 'Priority support'],
                        'highlight' => false,
                    ],
                ];
            @endphp

            <div class="mt-12 grid gap-6 lg:grid-cols-3">
                @foreach ($plans as $plan)
                    <div @class([
                        'relative flex flex-col rounded-2xl border bg-card p-7 shadow-sm',
                        'border-primary ring-1 ring-primary lg:-my-3 lg:py-10' => $plan['highlight'],
                        'border-border' => ! $plan['highlight'],
                    ])>
                        @if ($plan['highlight'])
                            <span class="absolute -top-3 left-7 rounded-full bg-primary px-3 py-0.5 text-xs font-semibold text-primary-foreground">Most popular</span>
                        @endif

                        <h3 class="text-lg font-semibold">{{ $plan['name'] }}</h3>
                        <p class="mt-1 text-sm text-muted-foreground">{{ $plan['blurb'] }}</p>

                        <p class="mt-6 flex items-baseline gap-1">
                            @if ($plan['price'])
                                <span class="text-4xl font-bold tracking-tight">{{ $currency }}{{ $plan['price'] }}</span>
                                <span class="text-sm text-muted-foreground">/ month</span>
                            @else
                                <span class="text-4xl font-bold tracking-tight">{{ $currency }}0</span>
                                <span class="text-sm text-muted-foreground">forever</span>
                            @endif
                        </p>

                        <ul class="mt-6 flex-1 space-y-3 text-sm">
                            @foreach ($plan['features'] as $feature)
                                <li class="flex gap-2.5"><x-ui.icon name="check" size="sm" class="mt-0.5 text-primary" /> {{ $feature }}</li>
                            @endforeach
                        </ul>

                        <x-ui.button
                            class="mt-8 w-full"
                            size="lg"
                            :variant="$plan['highlight'] ? 'default' : 'outline'"
                            :href="route('member.register')"
                        >
                            {{ $plan['price'] ? 'Start with '.$plan['name'] : 'Join free' }}
                        </x-ui.button>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ---- faq ------------------------------------------------------------- --}}
    <section id="faq" class="scroll-mt-20">
        <div class="mx-auto max-w-3xl px-4 py-20 sm:px-6">
            <h2 class="text-center text-3xl font-bold tracking-tight sm:text-4xl">Questions people ask</h2>

            <div class="mt-10 divide-y divide-border rounded-2xl border border-border bg-card">
                @foreach ([
                    ["Is {$name} free?", "Yes. Creating a profile, matching, messaging, verification and every safety tool are free. Premium adds unlimited likes and shows you who has already liked you."],
                    ['How does verification work?', 'You take one selfie while copying a short gesture code we show you. A trained member of our team compares it with your profile photos. Once approved you get the verified badge.'],
                    ['Who can see my verification selfie?', 'Only our review team. It is stored separately from your profile, never shown to other members, and every time a reviewer opens it is recorded.'],
                    ['What happens when I report someone?', 'The report goes straight to our moderation queue. The most serious categories are prioritised and reviewed within the hour. The person you reported is never told who reported them.'],
                    ['Can staff read my messages?', 'Not routinely. A moderator can only open a conversation while investigating a report, has to record a reason, and every access is logged for audit.'],
                    ["Who can join?", "Anyone aged {$minAge} or over. Accounts that appear to belong to someone younger are removed."],
                    ['How do I delete my account?', 'From Account settings in the app. Your profile disappears from discovery straight away.'],
                ] as [$question, $answer])
                    <details class="group px-5 [&_summary::-webkit-details-marker]:hidden">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-4 font-medium">
                            {{ $question }}
                            <x-ui.icon name="chevron-down" size="sm" class="shrink-0 text-muted-foreground transition-transform group-open:rotate-180" />
                        </summary>
                        <p class="pb-5 text-sm leading-relaxed text-muted-foreground">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ---- closing CTA ----------------------------------------------------- --}}
    <section class="px-4 pb-20 sm:px-6">
        <div class="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-gradient-to-br from-primary to-accent px-6 py-14 text-center text-primary-foreground sm:px-12">
            <div aria-hidden="true" class="absolute -right-20 -top-20 size-72 rounded-full bg-white/10 blur-2xl"></div>
            <h2 class="relative text-3xl font-bold tracking-tight sm:text-4xl">Your person is probably already here.</h2>
            <p class="relative mx-auto mt-3 max-w-xl text-primary-foreground/85">Join free in a couple of minutes. Verify when you are ready.</p>
            <div class="relative mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ route('member.register') }}" class="inline-flex h-12 items-center gap-2 rounded-lg bg-white px-7 text-base font-semibold text-foreground shadow-sm transition hover:bg-white/90">
                    Create your profile
                    <x-ui.icon name="arrow-right" size="sm" />
                </a>
                @if ($ios = Branding::get('app.ios_url'))
                    <x-site.store-badge store="ios" :href="$ios" class="bg-black text-white" />
                @endif
                @if ($android = Branding::get('app.android_url'))
                    <x-site.store-badge store="android" :href="$android" class="bg-black text-white" />
                @endif
            </div>
        </div>
    </section>
</x-layouts.site>
