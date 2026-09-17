@props([
    'items' => [],
])

{{-- `items` is a list of ['label' => ..., 'href' => ?]. The last entry is current. --}}

<nav aria-label="Breadcrumb" {{ $attributes->class('min-w-0') }}>
    <ol class="flex items-center gap-1.5 text-sm">
        @foreach ($items as $index => $item)
            @php $isLast = $index === array_key_last($items); @endphp

            <li class="flex min-w-0 items-center gap-1.5">
                @if (! $isLast && isset($item['href']))
                    <a
                        href="{{ $item['href'] }}"
                        wire:navigate
                        class="truncate text-muted-foreground transition-colors hover:text-foreground"
                    >{{ $item['label'] }}</a>
                @else
                    <span
                        @class(['truncate', 'font-medium text-foreground' => $isLast, 'text-muted-foreground' => ! $isLast])
                        @if ($isLast) aria-current="page" @endif
                    >{{ $item['label'] }}</span>
                @endif

                @unless ($isLast)
                    <x-ui.icon name="chevron-right" size="xs" class="text-muted-foreground/60" />
                @endunless
            </li>
        @endforeach
    </ol>
</nav>
