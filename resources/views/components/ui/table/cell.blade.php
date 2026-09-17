@props([
    'align' => 'left',
    'muted' => false,
    'numeric' => false,
    'wrap' => false,
])

@php
    $aligns = ['left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right'];
@endphp

<td {{ $attributes->class([
    'px-3 align-middle text-sm',
    'whitespace-nowrap' => ! $wrap,
    '[&:has([role=checkbox])]:pr-0',
    'text-muted-foreground' => $muted,
    // Tabular figures stop a column of numbers jittering as rows re-render.
    'tabular' => $numeric,
    $aligns[$align] ?? $aligns['left'],
]) }}>
    {{ $slot }}
</td>
