@props([
    'title' => null,
    'description' => null,
    'icon' => null,
    'padding' => true,
    'flush' => false,
])

{{--
    `flush` drops the inner padding so a table or list can sit edge to edge
    inside the card without fighting it.
--}}

<div {{ $attributes->class([
    'rounded-xl border border-border bg-card text-card-foreground shadow-sm',
    'dark:bg-white/[0.03]',
]) }}>
    @if ($title || $description || isset($header) || isset($action))
        <div @class([
            'flex items-start justify-between gap-4',
            'px-5 md:px-6 pt-5 md:pt-6' => $padding,
            'pb-4 border-b border-border' => $flush,
        ])>
            <div class="min-w-0 space-y-1">
                @if ($title)
                    <h3 class="flex items-center gap-2 text-base font-semibold leading-none">
                        @if ($icon)
                            <x-ui.icon :name="$icon" size="sm" class="text-muted-foreground" />
                        @endif
                        {{ $title }}
                    </h3>
                @endif

                @if ($description)
                    <p class="text-sm text-muted-foreground">{{ $description }}</p>
                @endif

                {{ $header ?? '' }}
            </div>

            @isset($action)
                <div class="shrink-0">{{ $action }}</div>
            @endisset
        </div>
    @endif

    <div @class([
        'p-5 md:p-6' => $padding && ! $flush,
        'pt-4' => $padding && ! $flush && ($title || $description || isset($header)),
    ])>
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="flex items-center gap-3 border-t border-border px-5 py-4 md:px-6">
            {{ $footer }}
        </div>
    @endisset
</div>
