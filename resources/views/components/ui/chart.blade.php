@props([
    'id' => null,
    'type' => 'line',
    'series' => [],
    'categories' => [],
    'height' => 260,
    'colors' => null,
    'options' => [],
    'stacked' => false,
    'horizontal' => false,
])

{{--
    ApexCharts wrapper.

    Colours come from the CSS variables at render time and are re-read on
    `platform:theme-changed`, so charts recolour with the rest of the interface
    instead of keeping their own light-mode palette in dark mode.
--}}

@php
    $id ??= 'chart-'.str()->random(6);

    $config = array_replace_recursive([
        'id' => $id,
        'chart' => ['type' => $type, 'height' => $height, 'stacked' => $stacked],
        'series' => $series,
        'xaxis' => ['categories' => $categories],
        'plotOptions' => [
            'bar' => [
                'horizontal' => $horizontal,
                'borderRadius' => 4,
                'borderRadiusApplication' => 'end',
                'columnWidth' => '60%',
            ],
        ],
    ], $options);
@endphp

<div
    x-data="platformChart({{ Js::from($config) }})"
    {{ $attributes->class('w-full') }}
    wire:ignore
>
    <div x-ref="canvas"></div>
</div>
