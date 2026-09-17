@props([
    'paginator',
    'perPage' => 25,
    'perPageOptions' => [10, 25, 50, 100],
    'simple' => false,
])

{{--
    Server-side pagination.

    `simple` drops the numbered links in favour of prev/next. Use it for live
    queues: numbered pages over a list that reorders as moderators work is
    actively misleading — page 3 is not the same page 3 ten seconds later.
--}}

@php
    $hasPages = $paginator->hasPages();
    $from = $paginator->firstItem() ?? 0;
    $to = $paginator->lastItem() ?? 0;
    $total = $paginator->total();
@endphp

<div class="flex flex-col items-center justify-between gap-3 sm:flex-row">
    <div class="flex items-center gap-3">
        <p class="text-sm text-muted-foreground">
            Showing <span class="tabular font-medium text-foreground">{{ veyra_number($from) }}</span>–<span
                class="tabular font-medium text-foreground">{{ veyra_number($to) }}</span>
            of <span class="tabular font-medium text-foreground">{{ veyra_number($total) }}</span>
        </p>

        @if ($perPageOptions)
            <select
                wire:model.live="perPage"
                aria-label="Rows per page"
                class="h-8 rounded-md border border-input bg-card px-2 text-xs text-foreground"
            >
                @foreach ($perPageOptions as $option)
                    <option value="{{ $option }}">{{ $option }} / page</option>
                @endforeach
            </select>
        @endif
    </div>

    @if ($hasPages)
        <nav class="flex items-center gap-1" aria-label="Pagination">
            <button
                type="button"
                wire:click="previousPage"
                @disabled($paginator->onFirstPage())
                class="inline-flex h-8 items-center gap-1 rounded-md border border-border px-2.5 text-xs font-medium text-foreground transition-colors hover:bg-muted disabled:pointer-events-none disabled:opacity-40"
            >
                <x-ui.icon name="chevron-left" size="xs" />
                <span class="hidden sm:inline">Previous</span>
            </button>

            @unless ($simple)
                <div class="hidden items-center gap-1 md:flex">
                    @foreach ($paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)) as $page => $url)
                        <button
                            type="button"
                            wire:click="gotoPage({{ $page }})"
                            @if ($page === $paginator->currentPage()) aria-current="page" @endif
                            @class([
                                'tabular inline-flex size-8 items-center justify-center rounded-md border text-xs font-medium transition-colors',
                                'border-primary bg-primary-subtle text-primary-subtle-foreground' => $page === $paginator->currentPage(),
                                'border-border text-muted-foreground hover:bg-muted hover:text-foreground' => $page !== $paginator->currentPage(),
                            ])
                        >{{ $page }}</button>
                    @endforeach
                </div>

                <span class="tabular px-2 text-xs text-muted-foreground md:hidden">
                    {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
                </span>
            @endunless

            <button
                type="button"
                wire:click="nextPage"
                @disabled(! $paginator->hasMorePages())
                class="inline-flex h-8 items-center gap-1 rounded-md border border-border px-2.5 text-xs font-medium text-foreground transition-colors hover:bg-muted disabled:pointer-events-none disabled:opacity-40"
            >
                <span class="hidden sm:inline">Next</span>
                <x-ui.icon name="chevron-right" size="xs" />
            </button>
        </nav>
    @endif
</div>
