@props([
    'show' => false,
    'close' => null,
    'size' => 'md',
])

{{--
    A dialog whose visibility is Livewire state rather than Alpine state.

    Member dialogs (report, match celebration) open and close as the result of a
    server action, so the server owning `show` keeps the two from disagreeing.
    `close` is the Livewire method to call on backdrop click or Escape.
--}}

@if ($show)
    <div
        class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4"
        x-data
        x-init="document.body.setAttribute('data-overlay-open', ''); $nextTick(() => $el.querySelector('[autofocus], button, input, textarea, select')?.focus())"
        x-on:keydown.escape.window="@if ($close) $wire.{{ $close }}() @endif"
        x-on:remove="document.body.removeAttribute('data-overlay-open')"
        wire:key="ui-dialog"
    >
        <div class="absolute inset-0 bg-black/55 backdrop-blur-[2px]" @if ($close) wire:click="{{ $close }}" @endif></div>

        <div
            role="dialog"
            aria-modal="true"
            {{ $attributes->class([
                'relative max-h-[92vh] w-full overflow-y-auto rounded-t-3xl bg-card shadow-2xl sm:rounded-3xl',
                'sm:max-w-md' => $size === 'md',
                'sm:max-w-lg' => $size === 'lg',
                'sm:max-w-sm' => $size === 'sm',
            ]) }}
        >
            {{ $slot }}
        </div>
    </div>
@else
    <span x-data x-init="document.body.removeAttribute('data-overlay-open')" class="hidden"></span>
@endif
