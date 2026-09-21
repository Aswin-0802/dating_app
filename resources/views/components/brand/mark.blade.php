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

    $initialsSize = match ($size) {
        'sm' => 'text-[13px]',
        'lg' => 'text-base',
        default => 'text-sm',
    };

    $fill = $tone === 'sidebar'
        ? 'bg-sidebar-primary text-sidebar-primary-foreground'
        : 'bg-primary text-primary-foreground';
@endphp

{{--
    An uploaded logo is shown at its own aspect ratio, capped in height, because
    buyers upload wordmarks as often as square icons.

    Without one, the tile carries the initials of whatever the product is
    called. A fixed glyph would be somebody's logo — this belongs to whoever
    set the name in Settings → Branding, which is the point of a white-label
    product.
--}}
@if ($logo)
    <img src="{{ $logo }}" alt="{{ Branding::name() }}" {{ $attributes->class([$imageHeight, 'w-auto max-w-40 shrink-0 object-contain']) }}>
@else
    <span
        {{ $attributes->class([$box, $fill, $initialsSize, 'flex shrink-0 items-center justify-center rounded-lg font-bold tracking-tight']) }}
        aria-hidden="true"
    >{{ platform_initials(Branding::name()) }}</span>
@endif
