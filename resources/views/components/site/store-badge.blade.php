@props(['store', 'href'])

{{-- App store link styled as a badge. Drawn, not an official image, so it
     follows the theme and needs no asset. --}}

<a href="{{ $href }}" target="_blank" rel="noopener noreferrer" {{ $attributes->class('inline-flex h-11 items-center gap-2.5 rounded-lg bg-foreground px-3.5 text-background transition-opacity hover:opacity-90') }}>
    @if ($store === 'ios')
        <svg viewBox="0 0 24 24" class="size-6" fill="currentColor" aria-hidden="true"><path d="M16.4 12.6c0-2.6 2.1-3.8 2.2-3.9-1.2-1.8-3.1-2-3.7-2-1.6-.2-3.1.9-3.9.9-.8 0-2-.9-3.4-.9-1.7 0-3.3 1-4.2 2.6-1.8 3.1-.5 7.7 1.3 10.2.8 1.2 1.8 2.6 3.1 2.5 1.3-.1 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 3-2.4 1-1.4 1.3-2.7 1.4-2.8-.1 0-2.6-1-2.6-3.8ZM13.9 4.9c.7-.8 1.2-2 1-3.1-1 0-2.2.7-2.9 1.5-.6.7-1.2 1.9-1 3 1.1.1 2.2-.6 2.9-1.4Z"/></svg>
        <span class="leading-tight"><span class="block text-[10px] opacity-80">Download on the</span><span class="block text-sm font-semibold">App Store</span></span>
    @else
        <svg viewBox="0 0 24 24" class="size-6" fill="currentColor" aria-hidden="true"><path d="M3.6 2.3c-.3.3-.4.7-.4 1.2v17c0 .5.1.9.4 1.2l9.5-9.7-9.5-9.7Zm10.6 10.8 2.6 2.6-11.2 6.4 8.6-9Zm0-2.2L5.6 1.9l11.2 6.4-2.6 2.6Zm3.8.2 3 1.7c.8.5.8 1.3 0 1.8l-3 1.7-2.8-2.6 2.8-2.6Z"/></svg>
        <span class="leading-tight"><span class="block text-[10px] opacity-80">Get it on</span><span class="block text-sm font-semibold">Google Play</span></span>
    @endif
</a>
