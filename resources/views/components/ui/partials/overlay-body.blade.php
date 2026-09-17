{{--
    Shared chrome for both overlay presentations: header, scrollable body, footer.
    Extracted so the modal and drawer branches cannot drift apart.
--}}

@if ($title || $description)
    <div class="flex shrink-0 items-start justify-between gap-4 border-b border-border px-5 py-4 md:px-6">
        <div class="min-w-0 space-y-1">
            @if ($title)
                <h2 class="text-base font-semibold leading-none">{{ $title }}</h2>
            @endif

            @if ($description)
                <p class="text-sm text-muted-foreground">{{ $description }}</p>
            @endif
        </div>

        @if ($dismissible)
            <button
                type="button"
                @click="close()"
                class="-mr-1 -mt-1 rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            >
                <x-ui.icon name="x-mark" size="sm" />
                <span class="sr-only">Close</span>
            </button>
        @endif
    </div>
@endif

<div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 md:px-6">
    {{ $slot }}
</div>

@if ($footer)
    {{-- flex-col-reverse on mobile puts the primary action on top when stacked. --}}
    <div class="flex shrink-0 flex-col-reverse gap-2 border-t border-border px-5 py-4 sm:flex-row sm:justify-end md:px-6">
        {{ $footer }}
    </div>
@endif
