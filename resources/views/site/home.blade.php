@php
    use App\Support\Branding;

    $name = Branding::name();
    $currency = App\Support\Currency::symbol();
    $freeLikes = (int) platform_setting('matching.daily_like_limit_free', 100);
    $minAge = (int) platform_setting('general.min_age', 18);
    $verificationHours = (int) platform_setting('verification.sla_hours', 24);
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

            <div class="relative mx-auto w-full max-w-md lg:max-w-none">
                <div aria-hidden="true" class="absolute -inset-3 -z-10 rotate-3 rounded-[2.5rem] bg-gradient-to-br from-primary/30 to-accent/30 blur-sm"></div>

                <figure class="relative overflow-hidden rounded-[2rem] shadow-2xl ring-1 ring-black/5">
                    <img
                        src="{{ asset('images/site/hero-couple.jpg') }}"
                        alt="A couple laughing together outdoors"
                        width="1100"
                        height="1300"
                        fetchpriority="high"
                        class="aspect-[4/5] w-full object-cover"
                    >
                    <div aria-hidden="true" class="absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-black/45 to-transparent"></div>
                </figure>

                <div class="absolute -left-3 top-10 flex items-center gap-2.5 rounded-2xl border border-border bg-card/95 px-4 py-3 shadow-xl backdrop-blur sm:-left-8">
                    <span class="flex size-9 items-center justify-center rounded-full bg-success-subtle text-success-subtle-foreground"><x-ui.icon name="check-badge" size="md" /></span>
                    <span class="text-sm leading-tight"><span class="block font-semibold">Photo verified</span><span class="block text-xs text-muted-foreground">Reviewed by our team</span></span>
                </div>

                <div class="absolute -right-3 bottom-12 flex items-center gap-2.5 rounded-2xl border border-border bg-card/95 px-4 py-3 shadow-xl backdrop-blur sm:-right-8">
                    <span class="flex size-9 items-center justify-center rounded-full bg-primary text-primary-foreground"><x-ui.icon name="heart" size="md" /></span>
                    <span class="text-sm leading-tight"><span class="block font-semibold">It's a match</span><span class="block text-xs text-muted-foreground">Say hello first</span></span>
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
                <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">Built around your safety</h2>
                <p class="text-sidebar-foreground/75">
                    A dedicated moderation team reviews reports, checks photos and explains every decision.
                    If you disagree with one, you can appeal and a different reviewer will look again.
                </p>
                <x-ui.button variant="secondary" :href="route('site.safety')">
                    Visit the safety centre
                    <x-ui.icon name="arrow-right" size="sm" />
                </x-ui.button>
                <img src="{{ asset('images/site/couple-sunlight.jpg') }}" alt="A couple enjoying a sunny day together" loading="lazy" class="mt-4 hidden aspect-[16/10] w-full rounded-3xl object-cover shadow-2xl lg:block">
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
                    ...App\Support\Masters::plans()->map(fn ($p) => [
                        'name' => $p->name,
                        'price' => $p->monthly_price,
                        'blurb' => $p->tagline,
                        'features' => $p->benefitLines(),
                        'highlight' => $p->is_featured,
                    ])->all(),
                ];
            @endphp

            <div @class([
                'mt-12 grid gap-6',
                'lg:grid-cols-2 lg:max-w-4xl lg:mx-auto' => count($plans) === 2,
                'lg:grid-cols-3' => count($plans) === 3,
                'md:grid-cols-2 xl:grid-cols-4' => count($plans) >= 4,
            ])>
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
                        @if ($plan['blurb'])
                            <p class="mt-1 text-sm text-muted-foreground">{{ $plan['blurb'] }}</p>
                        @endif

                        <p class="mt-6 flex items-baseline gap-1">
                            @if ($plan['price'] !== null)
                                <span class="text-4xl font-bold tracking-tight">{{ App\Support\Currency::format($plan['price']) }}</span>
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
                            {{ $plan['price'] !== null ? 'Start with '.$plan['name'] : 'Join free' }}
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
        <div class="relative mx-auto max-w-6xl overflow-hidden rounded-3xl px-6 py-16 text-center text-white sm:px-12 sm:py-20">
            <img src="{{ asset('images/site/couple-city.jpg') }}" alt="" loading="lazy" class="absolute inset-0 size-full object-cover">
            <div aria-hidden="true" class="absolute inset-0 bg-gradient-to-br from-primary/85 via-primary/60 to-black/70"></div>
            <h2 class="relative text-3xl font-bold tracking-tight sm:text-4xl">Your person could already be here.</h2>
            <p class="relative mx-auto mt-3 max-w-xl text-white/90">Join free in a couple of minutes. Verify when you are ready.</p>
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
