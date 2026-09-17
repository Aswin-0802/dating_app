@props([
    'sortable' => false,
    'field' => null,
    'direction' => null,
    'align' => 'left',
    'width' => null,
])

{{--
    A sortable header emits `sort` with its field; the Livewire component owns
    the ordering. The icon reflects three states — unsorted, asc, desc — because
    a header that looks sortable but shows no state is worse than a plain one.
--}}

@php
    $aligns = ['left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right'];
    $active = $sortable && $direction !== null;
@endphp

<th
    scope="col"
    @if ($width) style="width: {{ $width }}" @endif
    @if ($active) aria-sort="{{ $direction === 'asc' ? 'ascending' : 'descending' }}" @endif
    {{ $attributes->class([
        'h-10 whitespace-nowrap px-3 align-middle text-xs font-medium text-muted-foreground',
        '[&:has([role=checkbox])]:pr-0 [&:has([role=checkbox])]:w-10',
        $aligns[$align] ?? $aligns['left'],
    ]) }}
>
    @if ($sortable && $field)
        <button
            type="button"
            wire:click="sort('{{ $field }}')"
            @class([
                'group inline-flex items-center gap-1 transition-colors hover:text-foreground',
                'text-foreground' => $active,
                'ml-auto' => $align === 'right',
                'mx-auto' => $align === 'center',
            ])
        >
            {{ $slot }}

            @if ($direction === 'asc')
                <x-ui.icon name="chevron-up" size="xs" />
            @elseif ($direction === 'desc')
                <x-ui.icon name="chevron-down" size="xs" />
            @else
                <x-ui.icon name="chevron-up-down" size="xs" class="opacity-40 group-hover:opacity-100" />
            @endif
        </button>
    @else
        {{ $slot }}
    @endif
</th>
