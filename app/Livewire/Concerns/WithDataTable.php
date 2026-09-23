<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Search, sort, paginate and density for every admin table.
 *
 * Every module's list screen composes this, so it is worth over-investing in:
 * a change here lands everywhere at once, and so does a mistake.
 *
 * State is bound to the URL rather than only to the component, which is what
 * makes a filtered queue shareable — "look at this" between moderators has to be
 * a link, not a list of instructions.
 */
trait WithDataTable
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sortField = '';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';

    /*
     * Deliberately not typed `int`. Livewire binds the query string straight
     * onto the property, so ?perPage=abc threw a TypeError during hydration —
     * before any code of ours could reject it. It is coerced and clamped in
     * bootedWithDataTable() instead, and is an int everywhere after that.
     */
    #[Url(except: 25)]
    public int|string $perPage = 25;

    #[Url(except: 'comfortable')]
    public string $density = 'comfortable';

    /** Columns a subclass allows sorting on. Anything else is ignored. */
    protected function sortableFields(): array
    {
        return [];
    }

    protected function defaultSortField(): string
    {
        return 'created_at';
    }

    protected function defaultSortDirection(): string
    {
        return 'desc';
    }

    public function mountWithDataTable(): void
    {
        $this->perPage = (int) config('platform.tables.per_page', 25);
        $this->density = (string) config('platform.tables.density', 'comfortable');
    }

    /**
     * Normalise everything that arrives from the URL.
     *
     * Runs after hydration on every request, so it covers a shared link, a
     * stale bookmark and a hand-edited query string alike. Each value falls
     * back to its default rather than raising: a bad URL should show the
     * table, not a stack trace.
     */
    public function bootedWithDataTable(): void
    {
        $options = $this->perPageOptions();
        $perPage = (int) $this->perPage;

        // Clamped to the offered sizes. Unbounded, ?perPage=100000 is a way to
        // ask one admin request to build a hundred thousand rows.
        $this->perPage = in_array($perPage, $options, true)
            ? $perPage
            : (int) config('platform.tables.per_page', 25);

        // sortField is whitelisted in applySort(); direction never was, and
        // went to orderBy() verbatim.
        if (! in_array($this->sortDirection, ['asc', 'desc'], true)) {
            $this->sortDirection = $this->defaultSortDirection();
        }

        if (! in_array($this->density, ['comfortable', 'compact'], true)) {
            $this->density = (string) config('platform.tables.density', 'comfortable');
        }

        // Page zero and negative pages produce a negative OFFSET.
        if ($this->getPage() < 1) {
            $this->setPage(1);
        }
    }

    public function sort(string $field): void
    {
        if (! in_array($field, $this->sortableFields(), true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    /** The arrow state for a header cell: 'asc', 'desc' or null. */
    public function sortDirectionFor(string $field): ?string
    {
        return $this->currentSortField() === $field ? $this->sortDirection : null;
    }

    public function toggleDensity(): void
    {
        $this->density = $this->density === 'comfortable' ? 'compact' : 'comfortable';
    }

    // Any change to the result set must return to page one, otherwise the user
    // lands on an empty page 7 of a 2-page result and assumes it broke.
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    protected function currentSortField(): string
    {
        return $this->sortField !== '' ? $this->sortField : $this->defaultSortField();
    }

    protected function applySort(Builder $query): Builder
    {
        $field = $this->currentSortField();

        if (! in_array($field, $this->sortableFields(), true)) {
            $field = $this->defaultSortField();
        }

        $direction = $this->sortField !== '' ? $this->sortDirection : $this->defaultSortDirection();

        $query->orderBy($field, $direction);

        // A stable tiebreaker keeps pagination deterministic. Without it, rows
        // with equal sort values can reappear on two pages or be skipped.
        if ($field !== 'id') {
            $query->orderBy('id', 'desc');
        }

        return $query;
    }

    /** @return array<int, int> */
    public function perPageOptions(): array
    {
        return config('platform.tables.per_page_options', [10, 25, 50, 100]);
    }
}
