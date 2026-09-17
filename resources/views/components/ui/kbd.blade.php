@props([
    'keys' => null,
])

{{-- `keys` may be "mod+K"; `mod` renders as the platform-correct modifier. --}}

@php
    $parts = $keys ? explode('+', $keys) : [];
@endphp

<span {{ $attributes->class('inline-flex items-center gap-0.5') }}>
    @if ($parts)
        @foreach ($parts as $part)
            <kbd class="inline-flex h-5 min-w-5 items-center justify-center rounded-xs border border-border bg-muted px-1 font-sans text-[11px] font-medium text-muted-foreground">
                @if (strtolower($part) === 'mod')
                    {{-- Resolved client-side so one server render serves both platforms. --}}
                    <span x-data x-text="navigator.platform.toLowerCase().includes('mac') ? '⌘' : 'Ctrl'">Ctrl</span>
                @else
                    {{ strtoupper($part) }}
                @endif
            </kbd>
        @endforeach
    @else
        <kbd class="inline-flex h-5 min-w-5 items-center justify-center rounded-xs border border-border bg-muted px-1 font-sans text-[11px] font-medium text-muted-foreground">
            {{ $slot }}
        </kbd>
    @endif
</span>
