<?php

declare(strict_types=1);

namespace App\Livewire\Member\Concerns;

use App\Http\Controllers\Api\V1\GeographyController;
use App\Models\City;
use Livewire\Attributes\Computed;

/**
 * The website's city type-ahead, shared by Register and Profile.
 *
 * A grouped dropdown of every city stopped being an option the moment the
 * list could grow past a few hundred rows: the picker would truncate and a
 * member in an unlisted city could never join. This searches by the start
 * of the name, two characters or more, and shows a bounded set of matches
 * — the same rule and the same cap as GET /api/v1/cities.
 *
 * Expects the component to declare `public ?int $city_id`.
 */
trait PicksCity
{
    public string $citySearch = '';

    /** What the chosen city is called on screen; null until one is chosen. */
    public ?string $cityLabel = null;

    /** @return array<int, array{id: int, label: string}> */
    #[Computed]
    public function cityResults(): array
    {
        $search = trim($this->citySearch);

        if (mb_strlen($search) < 2) {
            return [];
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);

        return City::query()
            ->selectable()
            ->where('name', 'like', $escaped.'%')
            ->with(['country:id,name', 'state:id,name'])
            ->orderBy('name')
            ->limit(GeographyController::CITY_LIMIT)
            ->get(['id', 'name', 'country_id', 'state_id'])
            ->map(fn (City $city): array => ['id' => $city->id, 'label' => $this->cityLabelFor($city)])
            ->all();
    }

    public function chooseCity(int $id): void
    {
        $city = City::query()->selectable()->with(['country:id,name', 'state:id,name'])->find($id);

        if ($city === null) {
            $this->addError('city_id', 'That city is not available right now. Choose another.');

            return;
        }

        $this->city_id = $city->id;
        $this->cityLabel = $this->cityLabelFor($city);
        $this->citySearch = '';
        $this->resetErrorBag('city_id');
    }

    public function clearCity(): void
    {
        $this->city_id = null;
        $this->cityLabel = null;
        $this->citySearch = '';
    }

    /** "Chennai, Tamil Nadu" or "Singapore, Singapore" — the state where there is one, the country otherwise. */
    protected function cityLabelFor(?City $city): ?string
    {
        if ($city === null) {
            return null;
        }

        return $city->name.', '.($city->state?->name ?? $city->country?->name ?? '');
    }
}
