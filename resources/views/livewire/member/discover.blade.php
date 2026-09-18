<div
    class="mx-auto max-w-md"
    {{-- Arrow keys on desktop: left passes, right likes, up superlikes. --}}
    x-data
    @keydown.window="
        if ($event.target.closest('input, textarea, select, [role=dialog]')) return;
        if ($event.key === 'ArrowLeft') $wire.swipe('pass');
        if ($event.key === 'ArrowRight') $wire.swipe('like');
        if ($event.key === 'ArrowUp') { $event.preventDefault(); $wire.swipe('superlike'); }
    "
>
    @if ($isPending)
        {{-- Pending members are not in anybody's deck yet, so showing them
             one would be a one-way street. Finish the profile first. --}}
        <div class="rounded-3xl border border-border bg-card p-6 text-center shadow-sm sm:p-8">
            <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-primary-subtle text-primary-subtle-foreground">
                <x-ui.icon name="user-circle" size="lg" />
            </span>
            <h1 class="mt-5 text-2xl font-bold tracking-tight">Finish your profile to start</h1>
            <p class="mt-2 text-muted-foreground">People only see profiles that are at least half complete. You are nearly there.</p>

            <ul class="mt-6 space-y-2 text-left">
                @foreach ($checklist as $item => $done)
                    <li @class(['flex items-center gap-3 rounded-xl px-3 py-2 text-sm', 'bg-muted/60' => ! $done])>
                        @if ($done)
                            <span class="flex size-6 items-center justify-center rounded-full bg-success text-success-foreground"><x-ui.icon name="check" size="xs" /></span>
                            <span class="text-muted-foreground line-through">{{ $item }}</span>
                        @else
                            <span class="size-6 rounded-full border-2 border-border"></span>
                            <span class="font-medium">{{ $item }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>

            <x-ui.button size="lg" class="mt-6 h-12 w-full" :href="route('member.profile')" wire:navigate>Complete my profile</x-ui.button>
        </div>
    @elseif ($current)
        @if ($widened)
            <p class="mb-4 flex items-center gap-2 rounded-2xl bg-info-subtle px-4 py-2.5 text-sm text-info-subtle-foreground">
                <x-ui.icon name="globe" size="sm" class="shrink-0" />
                You have seen everyone new in {{ $me->city?->name ?? 'your city' }}, so we are showing people further away.
            </p>
        @endif

        <div wire:key="card-{{ $current->uuid }}" class="animate-in">
            <x-member.profile-card :person="$current" :me="$me">
                <div class="flex items-center justify-end gap-3 border-t border-border pt-4 text-sm">
                    <a href="{{ route('member.person', $current) }}" wire:navigate class="mr-auto font-medium text-primary hover:underline">Full profile</a>
                    <button type="button" wire:click="openReport('{{ $current->uuid }}')" class="inline-flex items-center gap-1.5 text-muted-foreground hover:text-foreground">
                        <x-ui.icon name="flag" size="sm" /> Report
                    </button>
                </div>
            </x-member.profile-card>
        </div>

        {{-- Actions stay under the thumb on phones, above the tab bar. --}}
        <div class="sticky bottom-20 z-10 mx-auto mt-5 flex w-fit items-center justify-center gap-5 rounded-full border border-border/60 bg-background/80 px-5 py-2.5 shadow-xl backdrop-blur-md md:bottom-6">
            <button type="button" wire:click="swipe('pass')" wire:loading.attr="disabled" class="flex size-16 items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-lg transition hover:scale-105 hover:text-foreground active:scale-95 disabled:opacity-60" title="Pass (←)">
                <x-ui.icon name="x-mark" size="xl" />
                <span class="sr-only">Pass</span>
            </button>
            <button type="button" wire:click="swipe('superlike')" wire:loading.attr="disabled" class="flex size-12 items-center justify-center rounded-full border border-border bg-card text-sky-500 shadow-lg transition hover:scale-105 active:scale-95 disabled:opacity-60" title="Superlike (↑)">
                <x-ui.icon name="star" size="lg" />
                <span class="sr-only">Superlike</span>
            </button>
            <button type="button" wire:click="swipe('like')" wire:loading.attr="disabled" class="flex size-16 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg shadow-primary/30 transition hover:scale-105 active:scale-95 disabled:opacity-60" title="Like (→)">
                <x-ui.icon name="heart" size="xl" />
                <span class="sr-only">Like</span>
            </button>
        </div>

        <div class="mt-3 text-center text-xs text-muted-foreground">
            @if ($limitMessage)
                <p class="mx-auto max-w-xs rounded-xl bg-warning-subtle px-3 py-2 text-sm text-warning-subtle-foreground">
                    {{ $limitMessage }}
                    <a href="{{ route('member.premium') }}" wire:navigate class="font-semibold underline">Get unlimited likes</a>
                </p>
            @elseif ($likesLeft !== null)
                <p>{{ $likesLeft }} {{ Str::plural('like', $likesLeft) }} left today</p>
            @endif
            <p class="mt-1 hidden md:block">Tip: use ← and → on your keyboard.</p>
        </div>
    @else
        <div class="rounded-3xl border border-dashed border-border bg-card p-8 text-center">
            <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                <x-ui.icon name="sparkles" size="lg" />
            </span>
            <h1 class="mt-5 text-xl font-bold">You have seen everyone for now</h1>
            <p class="mt-2 text-sm text-muted-foreground">New people join every day. Widening your age range or turning on global mode will show you more.</p>
            <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
                <x-ui.button :href="route('member.profile').'#preferences'" wire:navigate>Adjust preferences</x-ui.button>
                <x-ui.button variant="outline" :href="route('member.matches')" wire:navigate>See your matches</x-ui.button>
            </div>
        </div>
    @endif

    {{-- ---- it's a match --------------------------------------------------- --}}
    <x-member.modal :show="$matched !== null" close="closeMatch" size="sm">
        @if ($matched)
            <div class="relative overflow-hidden bg-gradient-to-br from-primary to-accent px-6 pb-7 pt-10 text-center text-primary-foreground">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] opacity-80">It's a</p>
                <p class="text-5xl font-black tracking-tight">Match!</p>

                <div class="mt-6 flex items-center justify-center">
                    <x-ui.avatar :src="$me->primaryPhoto?->thumb_url" :name="$me->display_name" size="2xl" class="-rotate-6 ring-4 ring-white" />
                    <x-ui.avatar :src="$matched->primaryPhoto?->thumb_url" :name="$matched->display_name" size="2xl" class="-ml-5 rotate-6 ring-4 ring-white" />
                </div>

                <p class="mt-5 text-primary-foreground/90">You and {{ $matched->display_name }} like each other.</p>
            </div>
            <div class="space-y-2 p-5">
                @if ($matchConversation)
                    <x-ui.button size="lg" class="h-12 w-full" :href="route('member.messages', $matchConversation)" wire:navigate>
                        <x-ui.icon name="chat" size="sm" /> Say hello
                    </x-ui.button>
                @endif
                <x-ui.button variant="ghost" size="lg" class="w-full" wire:click="closeMatch">Keep discovering</x-ui.button>
            </div>
        @endif
    </x-member.modal>

    <x-member.report-dialog :reporting-uuid="$reportingUuid" :categories="$categories" :name="$current?->display_name ?? 'this person'" />
</div>
