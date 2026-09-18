@props([
    'person',
    'me',
    'compact' => false,
])

@php
    use App\Enums\VerificationStatus;
    use App\Support\ProfileOptions;

    $photos = $person->photos->where('moderation_status', '!=', 'rejected')->sortBy('position')->values();
    $distance = ProfileOptions::distanceKm($me, $person);
    $myInterests = $me->interests->pluck('id')->all();
    $shared = $person->interests->whereIn('id', $myInterests);
    $isVerified = $person->verification_status === VerificationStatus::Approved;
    $prompts = collect($person->profile?->prompts ?? [])->filter(fn ($p) => filled($p['a'] ?? null))->values();
@endphp

<article {{ $attributes->class('overflow-hidden rounded-3xl border border-border bg-card shadow-sm') }}>
    {{-- Photos. Tap the left or right half to move, as on the phone apps. --}}
    <div x-data="{ i: 0, n: {{ max(1, $photos->count()) }} }" class="relative aspect-[4/5] bg-muted">
        @forelse ($photos as $index => $photo)
            <img
                src="{{ $photo->url }}"
                alt="{{ $person->display_name }}, photo {{ $index + 1 }}"
                x-show="i === {{ $index }}"
                @if ($index > 0) x-cloak loading="lazy" @endif
                class="absolute inset-0 size-full object-cover"
            >
        @empty
            <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-primary/80 to-accent text-6xl font-bold text-white/90">
                {{ veyra_initials($person->display_name) }}
            </div>
        @endforelse

        @if ($photos->count() > 1)
            <div class="absolute inset-x-0 top-2.5 flex gap-1 px-3">
                @foreach ($photos as $index => $photo)
                    <span class="h-1 flex-1 rounded-full transition-colors" :class="i === {{ $index }} ? 'bg-white' : 'bg-white/40'"></span>
                @endforeach
            </div>
            <button type="button" @click="i = Math.max(0, i - 1)" class="absolute inset-y-0 left-0 w-1/3 cursor-w-resize" aria-label="Previous photo"></button>
            <button type="button" @click="i = Math.min(n - 1, i + 1)" class="absolute inset-y-0 right-0 w-1/3 cursor-e-resize" aria-label="Next photo"></button>
        @endif

        <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 via-black/30 to-transparent p-5 pt-24 text-white">
            <h2 class="flex flex-wrap items-center gap-x-2 text-3xl font-bold tracking-tight">
                {{ $person->display_name }}<span class="font-normal">{{ $person->age }}</span>
                @if ($isVerified)
                    <x-ui.icon name="check-badge" size="lg" class="text-sky-300" title="Photo verified" />
                    <span class="sr-only">Photo verified</span>
                @endif
                @if ($person->premium_tier === 'gold' && $person->is_premium)
                    <span class="rounded-full bg-amber-400/90 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-950">Gold</span>
                @endif
            </h2>
            <p class="mt-1 flex items-center gap-1.5 text-sm text-white/85">
                <x-ui.icon name="map-pin" size="sm" />
                {{ $person->city?->name ?? 'Nearby' }}@if ($distance !== null) · {{ $distance }} km away @endif
            </p>
        </div>
    </div>

    <div class="space-y-5 p-5">
        @if (filled($person->profile?->bio))
            <p class="text-[15px] leading-relaxed">{{ $person->profile->bio }}</p>
        @endif

        @if ($facts = ProfileOptions::facts($person))
            <ul class="flex flex-wrap gap-2">
                @foreach ($facts as $fact)
                    <li class="inline-flex items-center gap-1.5 rounded-full bg-muted px-3 py-1.5 text-sm">
                        <x-ui.icon :name="$fact['icon']" size="sm" class="text-muted-foreground" />
                        {{ $fact['label'] }}
                    </li>
                @endforeach
            </ul>
        @endif

        @unless ($compact)
            @foreach ($prompts as $prompt)
                <div class="rounded-2xl bg-primary-subtle/60 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-primary-subtle-foreground">{{ $prompt['q'] }}</p>
                    <p class="mt-1.5 text-lg font-medium leading-snug">{{ $prompt['a'] }}</p>
                </div>
            @endforeach
        @endunless

        @if ($person->interests->isNotEmpty())
            <div>
                <p class="mb-2 text-sm font-medium">
                    Interests
                    @if ($shared->isNotEmpty())
                        <span class="font-normal text-muted-foreground">· {{ $shared->count() }} in common</span>
                    @endif
                </p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($person->interests->sortByDesc(fn ($interest) => in_array($interest->id, $myInterests, true)) as $interest)
                        <span @class([
                            'rounded-full border px-3 py-1 text-sm',
                            'border-primary bg-primary-subtle font-medium text-primary-subtle-foreground' => in_array($interest->id, $myInterests, true),
                            'border-border' => ! in_array($interest->id, $myInterests, true),
                        ])>{{ $interest->name }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        @if (! $compact && filled($person->profile?->languages))
            <p class="text-sm text-muted-foreground">
                <x-ui.icon name="globe" size="sm" class="-mt-0.5 mr-1 inline" />
                Speaks {{ collect($person->profile->languages)->join(', ', ' and ') }}
            </p>
        @endif

        {{ $slot }}
    </div>
</article>
