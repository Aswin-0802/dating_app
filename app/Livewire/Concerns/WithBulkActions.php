<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Row selection and bulk actions.
 *
 * The important distinction is between selecting the visible page and selecting
 * everything that matches the current filters. Moderators reach for the second
 * constantly ("ban all 400 of these bot accounts"), and conflating the two is
 * how you either under-action or, much worse, over-action.
 */
trait WithBulkActions
{
    /** @var array<int, string> */
    public array $selected = [];

    public bool $selectPage = false;

    /** True when the user has escalated from "this page" to "all matching". */
    public bool $selectAllMatching = false;

    public function updatedSelectPage(bool $value): void
    {
        $this->selected = $value
            ? $this->pageIds()
            : [];

        if (! $value) {
            $this->selectAllMatching = false;
        }
    }

    public function updatedSelected(): void
    {
        // Touching an individual checkbox always drops out of "all matching":
        // the two can no longer be describing the same set.
        $this->selectAllMatching = false;
        $this->selectPage = count($this->selected) === count($this->pageIds());
    }

    public function selectAll(): void
    {
        $this->selectAllMatching = true;
        $this->selectPage = true;
        $this->selected = $this->pageIds();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectPage = false;
        $this->selectAllMatching = false;
    }

    public function isSelected(int|string $id): bool
    {
        return $this->selectAllMatching || in_array((string) $id, $this->selected, true);
    }

    /** How many records a bulk action would actually touch. */
    public function selectedCount(): int
    {
        return $this->selectAllMatching
            ? $this->bulkQuery()->toBase()->getCountForPagination()
            : count($this->selected);
    }

    public function hasSelection(): bool
    {
        return $this->selectAllMatching || $this->selected !== [];
    }

    /**
     * The records a bulk action applies to.
     *
     * When "all matching" is active this is the full filtered query, not the
     * selected ids — which is the whole point of the distinction.
     */
    protected function bulkQuery(): Builder
    {
        $query = $this->baseQuery();

        return $this->selectAllMatching
            ? $query
            : $query->whereIn($query->getModel()->getQualifiedKeyName(), $this->selected);
    }

    /**
     * Bulk work above this threshold is queued rather than run inline, so a
     * moderator selecting 12,000 rows does not hold a web request open.
     */
    protected function shouldQueueBulkAction(): bool
    {
        return $this->selectedCount() > (int) config('platform.tables.bulk_inline_limit', 500);
    }

    /** @return array<int, string> */
    abstract protected function pageIds(): array;

    abstract protected function baseQuery(): Builder;
}
