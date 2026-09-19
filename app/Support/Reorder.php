<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Moves one row up or down a list ordered by `sort_order`. */
final class Reorder
{
    /**
     * @param  Collection<int, Model>  $items  the whole list, in its current order
     */
    public static function move(Collection $items, int|string $id, int $direction): void
    {
        $items = $items->values();
        $from = $items->search(fn (Model $m): bool => $m->getKey() == $id);
        $to = $from === false ? false : $from + ($direction < 0 ? -1 : 1);

        if ($from === false || $to < 0 || $to >= $items->count()) {
            return;
        }

        $order = $items->all();
        [$order[$from], $order[$to]] = [$order[$to], $order[$from]];

        // Renumbered in full so rows that shared a position end up distinct.
        DB::transaction(function () use ($order): void {
            foreach ($order as $position => $model) {
                if ((int) $model->sort_order !== $position) {
                    $model->update(['sort_order' => $position]);
                }
            }
        });
    }
}
