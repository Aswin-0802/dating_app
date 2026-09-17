@props([
    'icon' => 'inbox',
    'heading' => 'Nothing here',
    'description' => null,
])

{{--
    An empty state should say why it is empty and what to do next. "No results"
    with no follow-up is where a moderator gets stuck wondering if the filter
    broke or the queue is genuinely clear.
--}}

<div {{ $attributes->class('flex flex-col items-center justify-center gap-3 px-6 py-16 text-center') }}>
    <div class="flex size-12 items-center justify-center rounded-xl bg-muted text-muted-foreground">
        <x-ui.icon :name="$icon" size="lg" />
    </div>

    <div class="space-y-1">
        <p class="text-sm font-medium text-foreground">{{ $heading }}</p>

        @if ($description)
            <p class="mx-auto max-w-sm text-sm text-muted-foreground">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="mt-2 flex flex-wrap items-center justify-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
