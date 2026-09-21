<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Countries and cities members can choose from.
 *
 * Hiding a country removes it from sign-up without touching members already
 * there; deleting is only possible when nobody uses the place any more.
 */
class Locations extends Component
{
    #[Url(except: null)]
    public ?int $country = null;

    public string $citySearch = '';

    // country form
    public bool $countryFormOpen = false;

    #[Locked]
    public ?int $editingCountryId = null;

    public string $countryName = '';

    public string $countryIso2 = '';

    public string $countryDialCode = '';

    public bool $countryActive = true;

    // state form
    public bool $stateFormOpen = false;

    #[Locked]
    public ?int $editingStateId = null;

    public string $stateName = '';

    public string $stateCode = '';

    public bool $stateActive = true;

    /** Filters the city list; null shows every city in the country. */
    #[Url(except: null)]
    public ?int $state = null;

    // city form
    public bool $cityFormOpen = false;

    public ?int $cityStateId = null;

    #[Locked]
    public ?int $editingCityId = null;

    public string $cityName = '';

    public ?string $cityLatitude = null;

    public ?string $cityLongitude = null;

    public string $cityTimezone = 'UTC';

    public bool $cityFocus = false;

    public function mount(): void
    {
        $this->country ??= Country::query()->orderBy('name')->value('id');
    }

    public function render(): View
    {
        $countries = Country::query()->withCount('cities')->orderBy('name')->get();
        $selected = $countries->firstWhere('id', $this->country);

        $states = $selected === null ? collect() : State::query()
            ->where('country_id', $selected->id)
            ->withCount('cities')
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        // A state filter left over from another country would silently hide
        // every city, so it is dropped when it no longer belongs here.
        if ($this->state !== null && ! $states->contains('id', $this->state)) {
            $this->state = null;
        }

        $cities = $selected === null ? collect() : City::query()
            ->where('country_id', $selected->id)
            ->when($this->state !== null, fn ($q) => $q->where('state_id', $this->state))
            ->when($this->citySearch !== '', fn ($q) => $q->where('name', 'like', '%'.$this->citySearch.'%'))
            ->with('state')
            ->orderBy('name')
            ->get();

        $members = DB::table('app_users')->whereIn('city_id', $cities->pluck('id'))
            ->groupBy('city_id')->selectRaw('city_id, COUNT(*) c')->pluck('c', 'city_id');

        return view('livewire.settings.locations', [
            'countries' => $countries,
            'selected' => $selected,
            'states' => $states,
            'cities' => $cities,
            'memberCounts' => $members,
            'canEdit' => auth()->user()?->can('edit_general_settings') ?? false,
            'timezones' => \DateTimeZone::listIdentifiers(),
        ])->layout('components.layouts.admin', [
            'title' => 'Locations',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Settings', 'href' => route('admin.settings.general')],
                ['label' => 'Locations'],
            ],
        ]);
    }

    public function selectCountry(int $id): void
    {
        $this->country = $id;
        $this->citySearch = '';
        $this->state = null;
    }

    public function filterByState(?int $id): void
    {
        $this->state = $id;
    }

    // ---- countries ------------------------------------------------------------

    public function newCountry(): void
    {
        $this->authorize('edit_general_settings');
        $this->resetValidation();
        $this->editingCountryId = null;
        $this->countryName = $this->countryIso2 = $this->countryDialCode = '';
        $this->countryActive = true;
        $this->countryFormOpen = true;
    }

    public function editCountry(int $id): void
    {
        $this->authorize('edit_general_settings');
        $country = Country::query()->findOrFail($id);
        $this->resetValidation();
        $this->editingCountryId = $country->id;
        $this->countryName = $country->name;
        $this->countryIso2 = $country->iso2;
        $this->countryDialCode = (string) $country->dial_code;
        $this->countryActive = (bool) $country->is_active;
        $this->countryFormOpen = true;
    }

    public function saveCountry(ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');
        $this->countryIso2 = strtoupper(trim($this->countryIso2));

        $this->validate([
            'countryName' => ['required', 'string', 'min:2', 'max:80'],
            'countryIso2' => ['required', 'regex:/^[A-Z]{2}$/', Rule::unique('countries', 'iso2')->ignore($this->editingCountryId)],
            'countryDialCode' => ['nullable', 'regex:/^\+\d{1,4}$/'],
            'countryActive' => ['boolean'],
        ], [
            'countryIso2.regex' => 'Use the two-letter country code, such as US or IN.',
            'countryIso2.unique' => 'That country code is already in the list.',
            'countryDialCode.regex' => 'Use a dialling code such as +1 or +91.',
        ], ['countryName' => 'name', 'countryIso2' => 'country code', 'countryDialCode' => 'dialling code']);

        $country = Country::query()->updateOrCreate(['id' => $this->editingCountryId], [
            'name' => trim($this->countryName),
            'iso2' => $this->countryIso2,
            'dial_code' => $this->countryDialCode ?: null,
            'is_active' => $this->countryActive,
        ]);

        $logger->log(module: 'settings', action: $this->editingCountryId ? 'country_updated' : 'country_created', description: "Saved country {$country->name}");

        $this->countryFormOpen = false;
        $this->country = $country->id;
        session()->flash('status', "{$country->name} saved.");
    }

    public function deleteCountry(int $id, ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');
        $country = Country::query()->findOrFail($id);

        if (DB::table('app_users')->where('country_id', $country->id)->exists()) {
            session()->flash('error', "Members live in {$country->name}, so it cannot be deleted. Hide it from sign-up instead.");

            return;
        }

        $country->delete();
        $logger->log(module: 'settings', action: 'country_deleted', description: "Deleted country {$country->name}");
        $this->country = Country::query()->orderBy('name')->value('id');
        session()->flash('status', "{$country->name} deleted.");
    }

    // ---- cities ---------------------------------------------------------------

    public function newCity(): void
    {
        $this->authorize('edit_general_settings');
        abort_if($this->country === null, 404);
        $this->resetValidation();
        $this->editingCityId = null;
        $this->cityName = '';
        $this->cityLatitude = $this->cityLongitude = null;
        $this->cityTimezone = 'UTC';
        $this->cityFocus = false;
        $this->cityStateId = $this->state;
        $this->cityFormOpen = true;
    }

    public function editCity(int $id): void
    {
        $this->authorize('edit_general_settings');
        $city = City::query()->findOrFail($id);
        $this->resetValidation();
        $this->editingCityId = $city->id;
        $this->cityName = $city->name;
        $this->cityLatitude = $city->latitude !== null ? (string) $city->latitude : null;
        $this->cityLongitude = $city->longitude !== null ? (string) $city->longitude : null;
        $this->cityTimezone = (string) $city->timezone;
        $this->cityFocus = (bool) $city->is_focus;
        $this->cityStateId = $city->state_id;
        $this->cityFormOpen = true;
    }

    public function saveCity(ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');

        $this->validate([
            'cityName' => ['required', 'string', 'min:2', 'max:80', Rule::unique('cities', 'name')->where('country_id', $this->country)->ignore($this->editingCityId)],
            'cityLatitude' => ['nullable', 'numeric', 'between:-90,90'],
            'cityLongitude' => ['nullable', 'numeric', 'between:-180,180'],
            'cityTimezone' => ['required', 'timezone:all'],
            'cityFocus' => ['boolean'],
            // Required only where the country actually has states, so
            // Singapore does not need an imaginary one.
            'cityStateId' => [
                Rule::requiredIf(fn (): bool => State::query()->where('country_id', $this->country)->exists()),
                'nullable',
                Rule::exists('states', 'id')->where('country_id', $this->country),
            ],
        ], [
            'cityName.unique' => 'That city is already listed for this country.',
            'cityStateId.required' => 'Choose which state this city is in.',
        ], ['cityName' => 'name', 'cityLatitude' => 'latitude', 'cityLongitude' => 'longitude', 'cityTimezone' => 'time zone', 'cityStateId' => 'state']);

        $city = City::query()->updateOrCreate(['id' => $this->editingCityId], [
            'country_id' => $this->country,
            'state_id' => $this->cityStateId,
            'name' => trim($this->cityName),
            'latitude' => $this->cityLatitude !== null && $this->cityLatitude !== '' ? (float) $this->cityLatitude : null,
            'longitude' => $this->cityLongitude !== null && $this->cityLongitude !== '' ? (float) $this->cityLongitude : null,
            'timezone' => $this->cityTimezone,
            'is_focus' => $this->cityFocus,
        ]);

        $logger->log(module: 'settings', action: $this->editingCityId ? 'city_updated' : 'city_created', description: "Saved city {$city->name}");

        $this->cityFormOpen = false;
        session()->flash('status', "{$city->name} saved.");
    }

    public function deleteCity(int $id, ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');
        $city = City::query()->findOrFail($id);

        if (DB::table('app_users')->where('city_id', $city->id)->exists()) {
            session()->flash('error', "Members live in {$city->name}, so it cannot be deleted.");

            return;
        }

        $city->delete();
        $logger->log(module: 'settings', action: 'city_deleted', description: "Deleted city {$city->name}");
        session()->flash('status', "{$city->name} deleted.");
    }

    // ---- states ---------------------------------------------------------------

    public function newState(): void
    {
        $this->authorize('edit_general_settings');
        abort_if($this->country === null, 404);

        $this->resetValidation();
        $this->editingStateId = null;
        $this->stateName = $this->stateCode = '';
        $this->stateActive = true;
        $this->stateFormOpen = true;
    }

    public function editState(int $id): void
    {
        $this->authorize('edit_general_settings');
        $state = State::query()->findOrFail($id);

        $this->resetValidation();
        $this->editingStateId = $state->id;
        $this->stateName = $state->name;
        $this->stateCode = (string) $state->code;
        $this->stateActive = (bool) $state->is_active;
        $this->stateFormOpen = true;
    }

    public function saveState(ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');
        $this->stateCode = strtoupper(trim($this->stateCode));

        $this->validate([
            'stateName' => [
                'required', 'string', 'min:2', 'max:80',
                Rule::unique('states', 'name')->where('country_id', $this->country)->ignore($this->editingStateId),
            ],
            'stateCode' => ['nullable', 'regex:/^[A-Z0-9-]{1,10}$/'],
            'stateActive' => ['boolean'],
        ], [
            'stateName.unique' => 'That state is already listed for this country.',
            'stateCode.regex' => 'Use a short code such as TN or NSW.',
        ], ['stateName' => 'name', 'stateCode' => 'code']);

        $state = State::query()->updateOrCreate(['id' => $this->editingStateId], [
            'country_id' => $this->country,
            'name' => trim($this->stateName),
            'code' => $this->stateCode ?: null,
            'is_active' => $this->stateActive,
        ]);

        $logger->log(
            module: 'settings',
            action: $this->editingStateId ? 'state_updated' : 'state_created',
            subject: $state,
            description: "Saved state {$state->name}",
        );

        $this->stateFormOpen = false;
        session()->flash('status', "{$state->name} saved.");
    }

    public function deleteState(int $id, ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');
        $state = State::query()->withCount('cities')->findOrFail($id);

        if ($state->cities_count > 0) {
            session()->flash('error', "{$state->name} still has cities in it, so it cannot be deleted. Hide it instead.");

            return;
        }

        $state->delete();
        $logger->log(module: 'settings', action: 'state_deleted', description: "Deleted state {$state->name}");

        if ($this->state === $id) {
            $this->state = null;
        }

        session()->flash('status', "{$state->name} deleted.");
    }

    public function toggleStateActive(int $id, ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');
        $state = State::query()->findOrFail($id);
        $state->update(['is_active' => ! $state->is_active]);

        $logger->log(
            module: 'settings',
            action: $state->is_active ? 'state_shown' : 'state_hidden',
            subject: $state,
            description: ($state->is_active ? 'Showed' : 'Hid')." state {$state->name}",
        );

        session()->flash('status', $state->is_active
            ? "{$state->name} is back at sign-up."
            : "{$state->name} is hidden. Members already there keep it.");
    }

    public function closeForms(): void
    {
        $this->countryFormOpen = false;
        $this->cityFormOpen = false;
        $this->stateFormOpen = false;
    }
}
