@props([
    'variant' => 'modal',
    'name' => null,
    'title' => null,
    'description' => null,
    'size' => 'md',
    'backdrop' => 'dynamic',
    'dismissible' => true,
    'openOn' => null,
])

{{--
    The single overlay primitive.

    Centre modal, right drawer and mobile sheet are the same component — they
    differ only in positioning and transition classes. Stacking, focus trapping,
    Escape handling and the body scroll lock all live in resources/js/overlay.js,
    which keeps "open a confirm modal from inside a drawer" working by
    construction rather than by luck.

    `backdrop="static"` disables outside-click dismissal. Use it for every
    irreversible moderation action, so a stray click can never ban somebody.
--}}

@php
    $modalSizes = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-lg',
        'lg' => 'sm:max-w-2xl',
        'xl' => 'sm:max-w-4xl',
        'full' => 'sm:max-w-[calc(100vw-4rem)]',
    ];

    // Review drawers need to be wider than a stock sheet — evidence panes and
    // conversation excerpts are unreadable at 384px.
    $drawerSizes = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-xl',
        'lg' => 'sm:max-w-2xl',
        'xl' => 'sm:max-w-4xl',
    ];

    $config = json_encode([
        'variant' => $variant,
        'backdrop' => $backdrop,
        'dismissible' => $dismissible,
        'openOn' => $openOn,
    ]);
@endphp

<div
    x-data="veyraOverlay({{ $config }})"
    @if ($name) x-id="['{{ $name }}']" @endif
    x-cloak
>
    {{-- Trigger is optional: the overlay can also be opened by event via `openOn`. --}}
    @isset($trigger)
        <div @click="show()">{{ $trigger }}</div>
    @endisset

    <template x-teleport="body">
        <div x-show="open" class="fixed inset-0" :style="`z-index: ${zIndex}`">
            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="onBackdropClick()"
                class="absolute inset-0 bg-black/50 backdrop-blur-[1px]"
            ></div>

            @if ($variant === 'modal')
                <div class="absolute inset-0 flex items-end justify-center p-4 sm:items-center">
                    <div
                        x-ref="panel"
                        x-show="open"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 scale-95 translate-y-4 sm:translate-y-0"
                        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        role="dialog"
                        aria-modal="true"
                        tabindex="-1"
                        {{ $attributes->class([
                            'relative flex max-h-[90vh] w-full flex-col overflow-hidden rounded-xl border border-border bg-card shadow-2xl outline-none',
                            $modalSizes[$size] ?? $modalSizes['md'],
                        ]) }}
                    >
                        @include('components.ui.partials.overlay-body', [
                            'title' => $title,
                            'description' => $description,
                            'dismissible' => $dismissible,
                            'slot' => $slot,
                            'footer' => $footer ?? null,
                        ])
                    </div>
                </div>
            @else
                <div
                    x-ref="panel"
                    x-show="open"
                    x-transition:enter="transition ease-in-out duration-300"
                    x-transition:enter-start="translate-x-full"
                    x-transition:enter-end="translate-x-0"
                    x-transition:leave="transition ease-in-out duration-200"
                    x-transition:leave-start="translate-x-0"
                    x-transition:leave-end="translate-x-full"
                    role="dialog"
                    aria-modal="true"
                    tabindex="-1"
                    {{ $attributes->class([
                        'absolute inset-y-0 right-0 flex w-full flex-col border-l border-border bg-card shadow-2xl outline-none',
                        $drawerSizes[$size] ?? $drawerSizes['md'],
                    ]) }}
                >
                    @include('components.ui.partials.overlay-body', [
                        'title' => $title,
                        'description' => $description,
                        'dismissible' => $dismissible,
                        'slot' => $slot,
                        'footer' => $footer ?? null,
                    ])
                </div>
            @endif
        </div>
    </template>
</div>
