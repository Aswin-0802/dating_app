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

    #[Url(except: 25)]
    public int $perPage = 25;

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
        $this->perPage = (int) config('veyra.tables.per_page', 25);
        $this->density = (string) config('veyra.tables.density', 'comfortable');
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
        return config('veyra.tables.per_page_options', [10, 25, 50, 100]);
    }
}
