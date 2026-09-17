@php
    $match = $conversation->match;
    $one = $match?->userOne;
    $two = $match?->userTwo;
@endphp

<div class="space-y-4 md:space-y-6">

    {{-- The lock state is the first thing on the page, because "am I looking at
         real message content right now?" must never be ambiguous. --}}
    <div @class([
        'flex flex-wrap items-center gap-3 rounded-xl border px-4 py-3',
        'border-warning/40 bg-warning-subtle' => $isRevealed,
        'border-border bg-muted/40' => ! $isRevealed,
    ])>
        <x-ui.icon
            :name="$isRevealed ? 'unlock' : 'lock'"
            size="sm"
            class="{{ $isRevealed ? 'text-warning-subtle-foreground' : 'text-muted-foreground' }} shrink-0"
        />

        <div class="min-w-0 flex-1">
            <p @class([
                'text-sm font-medium',
                'text-warning-subtle-foreground' => $isRevealed,
            ])>
                {{ $isRevealed ? 'Message content is visible' : 'Message content is hidden' }}
            </p>
            <p class="text-xs {{ $isRevealed ? 'text-warning-subtle-foreground/85' : 'text-muted-foreground' }}">
                @if ($isRevealed)
                    This reveal is recorded against your name. Navigating away re-locks the thread.
                @elseif ($canReveal)
                    Revealing requires a reason and a written justification, both logged.
                @else
                    You do not have permission to read message content.
                @endif
            </p>
        </div>

        @if ($isRevealed)
            <x-ui.button size="sm" variant="outline" wire:click="relock">Re-lock</x-ui.button>
        @elseif ($canReveal)
            <x-ui.button size="sm" variant="outline" icon="eye" wire:click="$toggle('revealOpen')">
                Reveal content
            </x-ui.button>
        @endif
    </div>

    @error('reveal')
        <p class="text-sm text-destructive">{{ $message }}</p>
    @enderror

    @if ($revealOpen)
        <x-ui.card title="Justify reading this conversation"
            description="Both fields are stored permanently and cannot be edited or deleted.">
            <div class="space-y-3">
                <x-ui.select
                    label="Reason"
                    required
                    placeholder="Choose a reason…"
                    wire:model="reasonCode"
                    :options="$reasons"
                />

                <x-ui.textarea
                    label="Justification"
                    required
                    rows="2"
                    wire:model="justification"
                    placeholder="What are you looking for, and why does this conversation need to be read?"
                    :error="$errors->first('justification')"
                />

                <div class="flex items-center gap-2">
                    <x-ui.button size="sm" wire:click="reveal">Reveal and log</x-ui.button>
                    <x-ui.button size="sm" variant="ghost" wire:click="$set('revealOpen', false)">Cancel</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endif

    <div class="grid gap-4 md:gap-6 xl:grid-cols-[1fr_320px]">
        <x-ui.card :title="$conversation->messages_count.' '.str('message')->plural($conversation->messages_count)">
            <x-slot:action>
                @if ($conversation->is_flagged)
                    <x-ui.badge variant="destructive" icon="flag">Flagged</x-ui.badge>
                @endif
            </x-slot:action>

            <div class="max-h-[32rem] space-y-2 overflow-y-auto pr-1">
                @foreach ($messages as $message)
                    @php $fromOne = $message->sender_app_user_id === $one?->id; @endphp

                    <div @class(['flex gap-2', 'justify-end' => ! $fromOne])>
                        <div @class([
                            'max-w-[75%] rounded-lg px-3 py-2 text-sm',
                            'bg-muted' => $fromOne,
                            'bg-primary-subtle' => ! $fromOne,
                            'ring-1 ring-destructive/40' => $message->is_flagged,
                        ])>
                            <p class="mb-0.5 text-[11px] font-medium text-muted-foreground">
                                {{ $message->sender?->display_name ?? 'Unknown' }}
                            </p>

                            {{-- Redacted by default. The preview shows the shape
                                 of the message — type and length — which is
                                 usually enough to decide whether reading it is
                                 warranted at all. --}}
                            <p @class(['text-foreground', 'italic text-muted-foreground' => ! isset($revealed[$message->id])])>
                                {{ $revealed[$message->id] ?? $message->redactedPreview() }}
                            </p>

                            <div class="mt-1 flex flex-wrap items-center gap-1">
                                @if ($message->contains_contact_info)
                                    <x-ui.badge variant="warning" size="sm">Contact details</x-ui.badge>
                                @endif
                                @if ($message->contains_link)
                                    <x-ui.badge variant="warning" size="sm">Link</x-ui.badge>
                                @endif
                                @if ($message->moderation_status === 'removed')
                                    <x-ui.badge variant="destructive" size="sm">Removed</x-ui.badge>
                                @endif
                                <span class="text-[10px] text-muted-foreground">
                                    {{ veyra_datetime($message->created_at) }}
                                </span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <div class="space-y-4 md:space-y-6">
            <x-ui.card title="Participants">
                <div class="space-y-3">
                    @foreach ([$one, $two] as $participant)
                        @continue($participant === null)

                        <div class="space-y-1.5">
                            <x-veyra.user-cell
                                :name="$participant->display_name"
                                :age="$participant->age"
                                :photo="$participant->primaryPhoto?->thumb_url"
                                :href="route('admin.users.show', $participant)"
                                size="md"
                            />
                            <div class="flex flex-wrap gap-1">
                                <x-veyra.status-badge :status="$participant->account_status" size="sm" />
                                <x-veyra.risk-badge :score="$participant->risk_score" :band="$participant->risk_band" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card title="Thread">
                <dl class="divide-y divide-border text-sm">
                    @foreach ([
                        'Started' => veyra_datetime($conversation->started_at),
                        'Last message' => veyra_datetime($conversation->last_message_at),
                        'Messages' => veyra_number($conversation->messages_count),
                        'Matched' => veyra_date($match?->matched_at),
                        'Status' => ucfirst($conversation->status),
                    ] as $label => $value)
                        <div class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                            <dt class="text-muted-foreground">{{ $label }}</dt>
                            <dd class="font-medium">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>
        </div>
    </div>
</div>
