<div>
    <span class="flex size-14 items-center justify-center rounded-2xl bg-destructive-subtle text-destructive-subtle-foreground">
        <x-ui.icon name="lock" size="lg" />
    </span>

    <h1 class="mt-5 text-3xl font-bold tracking-tight">
        {{ $ban?->expires_at ? 'Your account is suspended' : 'Your account has been closed' }}
    </h1>

    <div class="mt-6 space-y-4 rounded-2xl border border-border bg-card p-5 text-sm">
        <p class="text-base">{{ $ban?->user_facing_message ?? 'Your account is currently restricted following a review.' }}</p>

        <dl class="grid gap-3 border-t border-border pt-4 sm:grid-cols-2">
            @if ($ban?->reason_code)
                <div>
                    <dt class="text-xs text-muted-foreground">Reason</dt>
                    <dd class="font-medium">{{ $ban->reason_code->label() }}</dd>
                </div>
            @endif
            @if ($action?->policy_clause)
                <div>
                    <dt class="text-xs text-muted-foreground">Rule</dt>
                    <dd class="font-medium">{{ $action->policy_clause }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-xs text-muted-foreground">Ends</dt>
                <dd class="font-medium">{{ $ban?->expires_at ? $ban->expires_at->format('j F Y, H:i') : 'Does not expire' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Decided by</dt>
                <dd class="font-medium">{{ $action?->actor_type === 'automation' ? 'An automated system, reviewed on appeal by a person' : 'Our safety team' }}</dd>
            </div>
        </dl>
    </div>

    <div class="mt-6">
        @if ($appeal)
            <div class="rounded-2xl border border-info/30 bg-info-subtle p-5 text-sm text-info-subtle-foreground">
                <p class="font-semibold">Appeal received {{ $appeal->created_at?->diffForHumans() }}</p>
                <p class="mt-1">
                    @switch($appeal->status->value ?? $appeal->status)
                        @case('upheld') We reviewed your appeal and the decision stands. @break
                        @case('overturned') Your appeal was successful. Sign in again in a moment. @break
                        @case('partially_overturned') Your appeal was partly successful and the restriction has been reduced. @break
                        @default A different member of our team from the one who made the decision is reviewing it. We aim to reply within three days.
                    @endswitch
                </p>
            </div>
        @elseif ($ban)
            <form wire:submit="appeal" class="space-y-3">
                <h2 class="text-lg font-semibold">Think we got this wrong?</h2>
                <p class="text-sm text-muted-foreground">Appeals are reviewed by someone other than the person who made the decision.</p>
                <x-ui.textarea rows="4" wire:model="statement" placeholder="Explain what happened and why you think the decision should change." :error="$errors->first('statement')" />
                <x-ui.button type="submit" size="lg" class="w-full" wire:loading.attr="disabled" wire:target="appeal">Send appeal</x-ui.button>
            </form>
        @endif
    </div>

    <button type="button" wire:click="signOut" class="mt-6 w-full text-center text-sm text-muted-foreground hover:text-foreground">Sign out</button>
</div>
