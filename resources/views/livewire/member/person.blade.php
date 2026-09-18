<div class="mx-auto max-w-md">
    <button type="button" onclick="history.length > 1 ? history.back() : window.location.assign('{{ route('member.discover') }}')" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
        <x-ui.icon name="arrow-left" size="sm" /> Back
    </button>

    <x-member.profile-card :person="$person" :me="$me">
        <div class="flex items-center justify-end gap-4 border-t border-border pt-4 text-sm">
            <button type="button" wire:click="openReport('{{ $person->uuid }}')" class="inline-flex items-center gap-1.5 text-muted-foreground hover:text-foreground">
                <x-ui.icon name="flag" size="sm" /> Report
            </button>
            <button type="button" wire:click="blockMember('{{ $person->uuid }}')" wire:confirm="Block {{ $person->display_name }}? You will not see each other again." class="inline-flex items-center gap-1.5 text-muted-foreground hover:text-destructive">
                <x-ui.icon name="ban" size="sm" /> Block
            </button>
        </div>
    </x-member.profile-card>

    <div class="sticky bottom-20 z-10 mt-5 md:bottom-6">
        @if ($match)
            <x-ui.button size="lg" class="h-12 w-full shadow-lg" :href="$match->conversation ? route('member.messages', $match->conversation) : route('member.matches')" wire:navigate>
                <x-ui.icon name="chat" size="sm" /> Message {{ $person->display_name }}
            </x-ui.button>
        @elseif ($swiped === 'like' || $swiped === 'superlike')
            <p class="rounded-2xl bg-card py-3 text-center text-sm text-muted-foreground shadow">You liked {{ $person->display_name }}. If they like you back, you will match.</p>
        @else
            <div class="mx-auto flex w-fit items-center justify-center gap-5 rounded-full border border-border/60 bg-background/80 px-5 py-2.5 shadow-xl backdrop-blur-md">
                <button type="button" wire:click="swipe('pass')" class="flex size-14 items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-lg transition hover:scale-105">
                    <x-ui.icon name="x-mark" size="lg" /><span class="sr-only">Pass</span>
                </button>
                <button type="button" wire:click="swipe('superlike')" class="flex size-12 items-center justify-center rounded-full border border-border bg-card text-sky-500 shadow-lg transition hover:scale-105">
                    <x-ui.icon name="star" size="md" /><span class="sr-only">Superlike</span>
                </button>
                <button type="button" wire:click="swipe('like')" class="flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg shadow-primary/30 transition hover:scale-105">
                    <x-ui.icon name="heart" size="lg" /><span class="sr-only">Like</span>
                </button>
            </div>
        @endif

        @if ($notice)
            <p class="mt-3 rounded-xl bg-warning-subtle px-3 py-2 text-center text-sm text-warning-subtle-foreground">
                {{ $notice }} <a href="{{ route('member.premium') }}" wire:navigate class="font-semibold underline">Go unlimited</a>
            </p>
        @endif
    </div>

    <x-member.report-dialog :reporting-uuid="$reportingUuid" :categories="$categories" :name="$person->display_name" />
</div>
