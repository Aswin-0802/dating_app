<div class="-mx-4 -mt-5 sm:mx-0 md:-mt-2">
    <div class="grid h-[calc(100dvh-8.75rem)] overflow-hidden border-border bg-card sm:rounded-3xl sm:border md:h-[calc(100dvh-8.5rem)] lg:grid-cols-[320px_1fr]">

        {{-- ---- conversation list ------------------------------------------- --}}
        <aside @class([
            'flex min-h-0 flex-col border-border lg:border-r',
            'hidden lg:flex' => $thread !== null,
        ])>
            <div class="border-b border-border px-5 py-4">
                <h1 class="text-xl font-bold tracking-tight">Messages</h1>
            </div>

            <ul class="min-h-0 flex-1 overflow-y-auto">
                @forelse ($conversations as $row)
                    @php
                        $c = $row['conversation'];
                        $isActive = $thread?->id === $c->id;
                        $mine = $row['latest']?->sender_app_user_id === $me->id;
                    @endphp
                    <li wire:key="conv-{{ $c->uuid }}">
                        <a href="{{ route('member.messages', $c) }}" wire:navigate @class([
                            'flex items-center gap-3 px-4 py-3 transition-colors',
                            'bg-primary-subtle' => $isActive,
                            'hover:bg-muted/60' => ! $isActive,
                        ])>
                            <x-ui.avatar :src="$row['other']?->primaryPhoto?->thumb_url" :name="$row['other']?->display_name" size="lg" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p @class(['truncate', 'font-bold' => $row['unread'] > 0, 'font-semibold' => $row['unread'] === 0])>{{ $row['other']?->display_name }}</p>
                                    <span class="shrink-0 text-[11px] text-muted-foreground">{{ $c->last_message_at?->shortAbsoluteDiffForHumans() }}</span>
                                </div>
                                <p @class(['truncate text-sm', 'font-medium text-foreground' => $row['unread'] > 0, 'text-muted-foreground' => $row['unread'] === 0])>
                                    @if ($mine) You: @endif{{ filled($row['latest']?->body) ? $row['latest']->body : match ($row['latest']?->type) {
                                        'image' => 'Sent a photo',
                                        'gif' => 'Sent a GIF',
                                        'voice' => 'Sent a voice note',
                                        default => '',
                                    } }}
                                </p>
                            </div>
                            @if ($row['unread'] > 0)
                                <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-semibold text-primary-foreground">{{ $row['unread'] }}</span>
                            @endif
                        </a>
                    </li>
                @empty
                    <li class="px-6 py-14 text-center">
                        <x-ui.icon name="chat" size="xl" class="mx-auto text-muted-foreground" />
                        <p class="mt-3 font-semibold">No conversations yet</p>
                        <p class="mt-1 text-sm text-muted-foreground">Say hello to one of your matches.</p>
                        <x-ui.button class="mt-4" size="sm" :href="route('member.matches')" wire:navigate>See matches</x-ui.button>
                    </li>
                @endforelse
            </ul>
        </aside>

        {{-- ---- thread ------------------------------------------------------ --}}
        @if ($thread)
            <section class="flex min-h-0 flex-col" wire:poll.6s="poll">
                <header class="flex items-center gap-3 border-b border-border px-3 py-2.5 sm:px-4">
                    <a href="{{ route('member.messages') }}" wire:navigate class="inline-flex size-9 items-center justify-center rounded-full hover:bg-muted lg:hidden">
                        <x-ui.icon name="arrow-left" size="sm" /><span class="sr-only">All messages</span>
                    </a>
                    @if ($other)
                        <a href="{{ route('member.person', $other) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3">
                            <x-ui.avatar :src="$other->primaryPhoto?->thumb_url" :name="$other->display_name" />
                            <span class="min-w-0">
                                <span class="flex items-center gap-1 truncate font-semibold">
                                    {{ $other->display_name }}
                                    @if ($other->verification_status === App\Enums\VerificationStatus::Approved)
                                        <x-ui.icon name="check-badge" size="sm" class="text-sky-500" />
                                    @endif
                                </span>
                                <span class="block text-xs text-muted-foreground">
                                    {{ $other->last_active_at && $other->last_active_at->gt(now()->subMinutes(15)) ? 'Active now' : 'Matched '.$thread->match?->matched_at?->diffForHumans() }}
                                </span>
                            </span>
                        </a>

                        <x-ui.dropdown align="end">
                            <x-slot:trigger>
                                <button type="button" class="inline-flex size-9 items-center justify-center rounded-full text-muted-foreground hover:bg-muted">
                                    <x-ui.icon name="dots-horizontal" size="sm" /><span class="sr-only">Options</span>
                                </button>
                            </x-slot:trigger>
                            <x-ui.dropdown.item :href="route('member.person', $other)" icon="user-circle">View profile</x-ui.dropdown.item>
                            <x-ui.dropdown.item icon="flag" wire:click="openReport('{{ $other->uuid }}')">Report</x-ui.dropdown.item>
                            <x-ui.dropdown.item icon="ban" variant="destructive" wire:click="blockMember('{{ $other->uuid }}')" wire:confirm="Block {{ $other->display_name }}? You will not see each other again and this chat will close.">Block</x-ui.dropdown.item>
                        </x-ui.dropdown>
                    @endif
                </header>

                <div
                    class="min-h-0 flex-1 space-y-1.5 overflow-y-auto px-3 py-4 sm:px-5"
                    x-data
                    x-init="$el.scrollTop = $el.scrollHeight"
                    @message-sent.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                >
                    @if ($messages->isEmpty())
                        <div class="flex h-full flex-col items-center justify-center text-center">
                            <x-ui.avatar :src="$other?->primaryPhoto?->thumb_url" :name="$other?->display_name" size="2xl" />
                            <p class="mt-4 text-lg font-semibold">You matched with {{ $other?->display_name }}</p>
                            <p class="mt-1 max-w-xs text-sm text-muted-foreground">Ask about something on their profile — it beats "hey" every time.</p>
                        </div>
                    @endif

                    @php $warned = false; @endphp
                    @foreach ($messages as $message)
                        @php $mine = $message->sender_app_user_id === $me->id; @endphp

                        <div wire:key="msg-{{ $message->uuid }}" @class(['group flex items-end gap-2', 'justify-end' => $mine])>
                            @unless ($mine)
                                <button type="button" wire:click="openReport('{{ $other?->uuid }}', '{{ $message->uuid }}')" class="order-last hidden self-center text-muted-foreground opacity-0 transition group-hover:opacity-100 sm:block" title="Report this message">
                                    <x-ui.icon name="flag" size="xs" /><span class="sr-only">Report this message</span>
                                </button>
                            @endunless
                            <div @class([
                                'max-w-[78%] whitespace-pre-line break-words rounded-3xl px-4 py-2.5 text-[15px] leading-snug',
                                'rounded-br-lg bg-primary text-primary-foreground' => $mine,
                                'rounded-bl-lg bg-muted' => ! $mine,
                            ])>@if (filled($message->body)){{ $message->body }}@else<span class="italic opacity-80">{{ match ($message->type) { 'image' => 'Photo', 'gif' => 'GIF', 'voice' => 'Voice note', default => 'Message' } }} — open the app to view</span>@endif</div>
                        </div>

                        {{-- One gentle nudge the first time the other person asks
                             to move off the platform — the classic scam opener. --}}
                        @if (! $mine && ! $warned && ($message->contains_contact_info || $message->contains_link))
                            @php $warned = true; @endphp
                            <div class="mx-auto my-3 flex max-w-md items-start gap-2.5 rounded-2xl bg-warning-subtle px-4 py-3 text-xs text-warning-subtle-foreground">
                                <x-ui.icon name="shield-check" size="sm" class="mt-px shrink-0" />
                                <p>Moving to another app early, or any request for money, are common signs of a scam. Take your time — and you can report this message if something feels off.</p>
                            </div>
                        @endif
                    @endforeach

                    @if ($messages->isNotEmpty() && $messages->last()->sender_app_user_id === $me->id && $messages->last()->read_at)
                        <p class="pr-1 text-right text-[11px] text-muted-foreground">Seen</p>
                    @endif
                </div>

                @if ($thread->status === 'open')
                    <form wire:submit="send" class="flex items-end gap-2 border-t border-border p-3">
                        <textarea
                            wire:model="body"
                            rows="1"
                            placeholder="Message {{ $other?->display_name }}"
                            maxlength="2000"
                            x-data
                            x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 140) + 'px'"
                            x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit(); }"
                            x-on:message-sent.window="$el.style.height = 'auto'"
                            class="max-h-36 min-h-11 flex-1 resize-none rounded-3xl border border-input bg-background px-4 py-2.5 text-[15px] outline-none focus:border-primary"
                        ></textarea>
                        <button type="submit" class="inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition hover:bg-primary/90 disabled:opacity-60" wire:loading.attr="disabled" wire:target="send">
                            <x-ui.icon name="paper-airplane" size="md" /><span class="sr-only">Send</span>
                        </button>
                    </form>
                    @error('body') <p class="px-4 pb-2 text-xs text-destructive">{{ $message }}</p> @enderror
                @else
                    <p class="border-t border-border px-4 py-4 text-center text-sm text-muted-foreground">This conversation has ended.</p>
                @endif
            </section>
        @else
            <section class="hidden flex-col items-center justify-center p-10 text-center lg:flex">
                <span class="flex size-16 items-center justify-center rounded-2xl bg-primary-subtle text-primary-subtle-foreground"><x-ui.icon name="chat" size="xl" /></span>
                <p class="mt-4 text-lg font-semibold">Pick a conversation</p>
                <p class="mt-1 text-sm text-muted-foreground">Or start one from your matches.</p>
            </section>
        @endif
    </div>

    <x-member.report-dialog :reporting-uuid="$reportingUuid" :categories="$categories" :name="$other?->display_name ?? 'this person'" />
</div>
