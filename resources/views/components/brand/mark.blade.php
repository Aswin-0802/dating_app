@props([
    'variant' => 'web',
    'size' => 'md',
    'tone' => 'primary',
])

@php
    use App\Support\Branding;

    $logo = Branding::logoUrl($variant);

    $box = match ($size) {
        'sm' => 'size-8',
        'lg' => 'size-11',
        default => 'size-9',
    };

    $imageHeight = match ($size) {
        'sm' => 'h-8',
        'lg' => 'h-11',
        default => 'h-9',
    };

    $fill = $tone === 'sidebar'
        ? 'bg-sidebar-primary text-sidebar-primary-foreground'
        : 'bg-primary text-primary-foreground';
@endphp

{{--
    An uploaded logo is shown at its own aspect ratio, capped in height, because
    buyers upload wordmarks as often as square icons. Without one, a tile in the
    brand colour keeps a fresh install looking finished.
--}}
@if ($logo)
    <img src="{{ $logo }}" alt="{{ Branding::name() }}" {{ $attributes->class([$imageHeight, 'w-auto max-w-40 shrink-0 object-contain']) }}>
@else
    <span {{ $attributes->class([$box, $fill, 'flex shrink-0 items-center justify-center rounded-lg']) }} aria-hidden="true">
        <svg viewBox="0 0 24 24" class="size-1/2" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 8l7 11 7-11" />
        </svg>
    </span>
@endif
