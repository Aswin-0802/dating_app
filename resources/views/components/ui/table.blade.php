@props([
    'density' => 'comfortable',
])

{{--
    The table shell.

    Toolbar, filters, the bulk-action bar, the empty state and the footer all sit
    OUTSIDE the <table> element as named slots, so a module can rearrange its
    chrome without touching row markup.

    Density is a data attribute read by CSS (see components.css) rather than a
    class on every cell, so the toggle restyles the whole table without a
    re-render.
--}}

<div {{ $attributes->class('overflow-hidden rounded-xl border border-border bg-card dark:bg-white/[0.03]') }}>
    @isset($toolbar)
        <div class="flex flex-col gap-3 border-b border-border p-3 md:flex-row md:items-center md:justify-between md:p-4">
            {{ $toolbar }}
        </div>
    @endisset

    @isset($filters)
        <div class="border-b border-border bg-muted/30 p-3 md:p-4">
            {{ $filters }}
        </div>
    @endisset

    @isset($bulkBar)
        {{ $bulkBar }}
    @endisset

    <div class="relative w-full overflow-x-auto">
        <table
            class="veyra-table w-full caption-bottom border-collapse text-sm"
            data-density="{{ $density }}"
        >
            {{ $slot }}
        </table>
    </div>

    @isset($empty)
        {{ $empty }}
    @endisset

    @isset($footer)
        <div class="border-t border-border px-3 py-3 md:px-4">
            {{ $footer }}
        </div>
    @endisset
</div>
