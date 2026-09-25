<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\City;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The one rule for "may a member choose this city?"
 *
 * A city is selectable when its country is shown, its state (if it has one)
 * is shown, and the city itself is shown. Hiding any of the three is a
 * restriction, not a dropdown filter: every write path that accepts a
 * city_id — the API profile update, the API registration, the website's
 * Register and Profile — must use this rule, and
 * GeographicRestrictionTest fails loudly if one is added without it.
 *
 * Members already in a place that has since been hidden keep it: pass their
 * current city as `$keep` and saving the rest of the profile still works
 * without forcing them to move (the documented behaviour for countries).
 */
final class SelectableCity implements ValidationRule
{
    public function __construct(private readonly ?int $keep = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_numeric($value)) {
            $fail('Choose a city from the list.');

            return;
        }

        if ($this->keep !== null && (int) $value === $this->keep) {
            return;
        }

        if (! City::query()->whereKey((int) $value)->selectable()->exists()) {
            $fail('That city is not available right now. Choose another.');
        }
    }
}
