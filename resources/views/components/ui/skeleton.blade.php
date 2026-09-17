@props([
    'rows' => null,
    'columns' => null,
])

{{--
    With `rows`/`columns` this renders a table skeleton; otherwise it is a single
    shimmer block whose shape you set with utility classes.
--}}

@if ($rows && $columns)
    @for ($row = 0; $row < $rows; $row++)
        <tr class="border-b border-border last:border-0">
            @for ($column = 0; $column < $columns; $column++)
                <td class="px-3 py-3">
                    {{-- Varying widths read as content rather than as a loading grid. --}}
                    <div
                        class="h-4 animate-pulse rounded-md bg-muted"
                        style="width: {{ [90, 60, 75, 45, 80, 55][$column % 6] }}%"
                    ></div>
                </td>
            @endfor
        </tr>
    @endfor
@else
    <div {{ $attributes->class('h-4 w-full animate-pulse rounded-md bg-muted') }}></div>
@endif
