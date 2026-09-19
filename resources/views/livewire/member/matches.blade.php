<div class="space-y-10">
    {{-- ---- liked you -------------------------------------------------------- --}}
    @if ($likers->isNotEmpty())
        <section>
            <div class="mb-4 flex items-end justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold tracking-tight">Liked you</h2>
                    <p class="text-sm text-muted-foreground">{{ $likers->count() }}{{ $likers->count() >= 24 ? '+' : '' }} {{ Str::plural('person', $likers->count()) }} waiting for your answer</p>
                </div>
            </div>

            @if ($me->hasPremiumFeature('see_likers'))
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                    @foreach ($likers as $liker)
                        <a href="{{ route('member.person', $liker) }}" wire:navigate class="group relative aspect-[3/4] overflow-hidden rounded-2xl bg-muted">
                            @if ($liker->primaryPhoto)
                                <img src="{{ $liker->primaryPhoto->thumb_url }}" alt="" class="size-full object-cover transition group-hover:scale-105">
                            @endif
                            <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/75 to-transparent p-2.5 pt-8 text-sm font-semibold text-white">
                                {{ $liker->display_name }}, {{ $liker->age }}
                            </span>
                        </a>
                    @endforeach
                </div>
            @else
                {{-- The count is always free; who they are is the Premium feature. --}}
                <div class="relative overflow-hidden rounded-3xl border border-border bg-card p-4">
                    <div class="grid grid-cols-3 gap-3 sm:grid-cols-6" aria-hidden="true">
                        @foreach ($likers->take(6) as $liker)
                            <div class="aspect-[3/4] overflow-hidden rounded-2xl bg-muted">
                                @if ($liker->primaryPhoto)
                                    <img src="{{ $liker->primaryPhoto->thumb_url }}" alt="" class="size-full scale-110 object-cover blur-xl">
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="absolute inset-0 flex flex-col items-center justify-center bg-background/40 p-6 text-center backdrop-blur-sm">
                        <x-ui.icon name="heart" size="xl" class="text-primary" />
                        <p class="mt-2 text-lg font-bold">See who likes you</p>
                        <p class="mt-1 max-w-xs text-sm text-muted-foreground">Match with them instantly instead of waiting for them in your deck.</p>
                        <x-ui.button class="mt-4" :href="route('member.premium')" wire:navigate>
                            <x-ui.icon name="bolt" size="sm" /> Unlock with {{ App\Support\Masters::plans()->first(fn ($p) => $p->hasFeature('see_likers'))?->name ?? 'Premium' }}
                        </x-ui.button>
                    </div>
                </div>
            @endif
        </section>
    @endif

    {{-- ---- new matches ------------------------------------------------------ --}}
    <section>
        <h2 class="mb-4 text-xl font-bold tracking-tight">New matches</h2>

        @if ($new->isEmpty())
            <p class="rounded-2xl border border-dashed border-border bg-card px-5 py-8 text-center text-sm text-muted-foreground">
                New matches you have not messaged yet show up here.
                <a href="{{ route('member.discover') }}" wire:navigate class="font-medium text-primary hover:underline">Keep discovering</a>
            </p>
        @else
            <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                @foreach ($new as $match)
                    @php $person = $match->otherParty($me); @endphp
                    <div class="group relative" wire:key="new-{{ $match->uuid }}">
                        <a href="{{ $match->conversation ? route('member.messages', $match->conversation) : route('member.person', $person) }}" wire:navigate class="block aspect-[3/4] overflow-hidden rounded-2xl bg-muted ring-2 ring-primary ring-offset-2 ring-offset-background">
                            @if ($person?->primaryPhoto)
                                <img src="{{ $person->primaryPhoto->thumb_url }}" alt="" class="size-full object-cover transition group-hover:scale-105">
                            @else
                                <span class="flex size-full items-center justify-center text-2xl font-bold text-muted-foreground">{{ veyra_initials($person?->display_name) }}</span>
                            @endif
                            <span class="absolute inset-x-0 bottom-0 rounded-b-2xl bg-gradient-to-t from-black/75 to-transparent p-2.5 pt-8 text-sm font-semibold text-white">{{ $person?->display_name }}</span>
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ---- conversations ---------------------------------------------------- --}}
    <section>
        <h2 class="mb-4 text-xl font-bold tracking-tight">Talking</h2>

        @if ($talking->isEmpty())
            <p class="rounded-2xl border border-dashed border-border bg-card px-5 py-8 text-center text-sm text-muted-foreground">
                Once you start chatting, your conversations appear here.
            </p>
        @else
            <ul class="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                @foreach ($talking as $match)
                    @php $person = $match->otherParty($me); @endphp
                    <li class="flex items-center gap-3 px-4 py-3" wire:key="talk-{{ $match->uuid }}">
                        <a href="{{ route('member.person', $person) }}" wire:navigate class="shrink-0">
                            <x-ui.avatar :src="$person?->primaryPhoto?->thumb_url" :name="$person?->display_name" size="lg" />
                        </a>
                        <a href="{{ $match->conversation ? route('member.messages', $match->conversation) : '#' }}" wire:navigate class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ $person?->display_name }}</p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ $match->messages_count }} {{ Str::plural('message', $match->messages_count) }}
                                @if ($match->last_message_at) · {{ $match->last_message_at->diffForHumans() }} @endif
                            </p>
                        </a>
                        <x-ui.dropdown align="end">
                            <x-slot:trigger>
                                <button type="button" class="inline-flex size-9 items-center justify-center rounded-full text-muted-foreground hover:bg-muted">
                                    <x-ui.icon name="dots-horizontal" size="sm" /><span class="sr-only">More</span>
                                </button>
                            </x-slot:trigger>
                            <x-ui.dropdown.item :href="route('member.person', $person)" icon="user-circle">View profile</x-ui.dropdown.item>
                            <x-ui.dropdown.item icon="x-mark" wire:click="unmatch('{{ $match->uuid }}')" wire:confirm="Unmatch {{ $person?->display_name }}? You will not be able to message each other again.">Unmatch</x-ui.dropdown.item>
                        </x-ui.dropdown>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
