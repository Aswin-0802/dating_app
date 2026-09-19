<div class="space-y-4 md:space-y-6">
    <div class="flex flex-wrap gap-1">
        <a href="{{ route('admin.settings.branding') }}" wire:navigate class="rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">Branding</a>
        <a href="{{ route('admin.settings.general') }}" wire:navigate class="rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">Product &amp; safety settings</a>
        <a href="{{ route('admin.settings.locations') }}" wire:navigate class="rounded-md bg-primary-subtle px-3 py-1.5 text-sm font-medium text-primary-subtle-foreground">Locations</a>
    </div>

    <div class="grid gap-4 md:gap-6 lg:grid-cols-[300px_1fr]">
        {{-- ---- countries -------------------------------------------------- --}}
        <x-ui.card title="Countries" :description="$countries->count().' in the list'">
            @if ($canEdit)
                <x-ui.button size="sm" variant="outline" icon="plus" class="mb-3 w-full" wire:click="newCountry">Add country</x-ui.button>
            @endif

            <ul class="-mx-2 max-h-[32rem] space-y-0.5 overflow-y-auto">
                @foreach ($countries as $c)
                    <li wire:key="country-{{ $c->id }}">
                        <button
                            type="button"
                            wire:click="selectCountry({{ $c->id }})"
                            @class([
                                'flex w-full items-center justify-between gap-2 rounded-md px-2 py-2 text-left text-sm transition-colors',
                                'bg-primary-subtle font-medium text-primary-subtle-foreground' => $selected?->id === $c->id,
                                'hover:bg-muted' => $selected?->id !== $c->id,
                            ])
                        >
                            <span class="min-w-0 truncate">
                                {{ $c->name }}
                                <span class="text-xs text-muted-foreground">{{ $c->iso2 }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-1.5">
                                @unless ($c->is_active)
                                    <x-ui.badge size="sm" variant="muted">Hidden</x-ui.badge>
                                @endunless
                                <span class="tabular text-xs text-muted-foreground">{{ $c->cities_count }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        {{-- ---- cities ------------------------------------------------------ --}}
        <x-ui.card :title="$selected ? 'Cities in '.$selected->name : 'Cities'">
            @if ($selected)
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <div class="relative min-w-0 flex-1 sm:max-w-xs">
                        <x-ui.icon name="search" size="sm" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                        <input type="search" wire:model.live.debounce.300ms="citySearch" placeholder="Search cities" class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground">
                    </div>
                    @if ($canEdit)
                        <div class="ml-auto flex gap-2">
                            <x-ui.button size="sm" variant="outline" icon="pencil" wire:click="editCountry({{ $selected->id }})">Edit country</x-ui.button>
                            <x-ui.button size="sm" variant="ghost" class="text-destructive" wire:click="deleteCountry({{ $selected->id }})" wire:confirm="Delete {{ $selected->name }} and its cities?">Delete</x-ui.button>
                            <x-ui.button size="sm" icon="plus" wire:click="newCity">Add city</x-ui.button>
                        </div>
                    @endif
                </div>

                @if ($cities->isEmpty())
                    <x-ui.empty-state icon="map-pin" heading="No cities yet" :description="$citySearch !== '' ? 'Nothing matches your search.' : 'Add the cities members can choose from.'" />
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border text-left text-xs text-muted-foreground">
                                    <th class="py-2 pr-3 font-medium">City</th>
                                    <th class="py-2 pr-3 font-medium">Time zone</th>
                                    <th class="py-2 pr-3 text-right font-medium">Members</th>
                                    <th class="py-2"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($cities as $city)
                                    <tr wire:key="city-{{ $city->id }}">
                                        <td class="py-2 pr-3">
                                            {{ $city->name }}
                                            @if ($city->is_focus)
                                                <x-ui.badge size="sm" variant="primary" class="ml-1">Featured</x-ui.badge>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-3 text-muted-foreground">{{ $city->timezone }}</td>
                                        <td class="tabular py-2 pr-3 text-right">{{ veyra_number($memberCounts[$city->id] ?? 0) }}</td>
                                        <td class="py-2 text-right">
                                            @if ($canEdit)
                                                <x-ui.button size="xs" variant="ghost" wire:click="editCity({{ $city->id }})">Edit</x-ui.button>
                                                <x-ui.button size="xs" variant="ghost" class="text-destructive" wire:click="deleteCity({{ $city->id }})" wire:confirm="Delete {{ $city->name }}?">Delete</x-ui.button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @else
                <x-ui.empty-state icon="globe" heading="No countries yet" description="Add a country to start listing cities." />
            @endif
        </x-ui.card>
    </div>

    {{-- ---- country dialog -------------------------------------------------- --}}
    <x-ui.dialog :show="$countryFormOpen" close="closeForms">
        <form wire:submit="saveCountry" class="space-y-4 p-6" novalidate>
            <h2 class="text-lg font-semibold">{{ $editingCountryId ? 'Edit country' : 'Add country' }}</h2>
            <x-ui.input label="Name" wire:model="countryName" :error="$errors->first('countryName')" required autofocus />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Country code" placeholder="US" maxlength="2" wire:model="countryIso2" :error="$errors->first('countryIso2')" required />
                <x-ui.input label="Dialling code" placeholder="+1" wire:model="countryDialCode" :error="$errors->first('countryDialCode')" />
            </div>
            <x-ui.toggle label="Show at sign-up" description="Hidden countries stay on existing profiles but cannot be chosen by new members." wire:model="countryActive" :checked="$countryActive" />
            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="ghost" wire:click="closeForms">Cancel</x-ui.button>
                <x-ui.button type="submit">Save country</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>

    {{-- ---- city dialog ----------------------------------------------------- --}}
    <x-ui.dialog :show="$cityFormOpen" close="closeForms">
        <form wire:submit="saveCity" class="space-y-4 p-6" novalidate>
            <h2 class="text-lg font-semibold">{{ $editingCityId ? 'Edit city' : 'Add city' }}</h2>
            <x-ui.input label="Name" wire:model="cityName" :error="$errors->first('cityName')" required autofocus />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Latitude" placeholder="40.7128" wire:model="cityLatitude" :error="$errors->first('cityLatitude')" />
                <x-ui.input label="Longitude" placeholder="-74.0060" wire:model="cityLongitude" :error="$errors->first('cityLongitude')" />
            </div>
            <x-ui.select label="Time zone" wire:model="cityTimezone" :selected="$cityTimezone" :options="array_combine($timezones, $timezones)" :error="$errors->first('cityTimezone')" />
            <x-ui.toggle label="Featured city" description="Featured cities are listed first and shown on dashboards." wire:model="cityFocus" :checked="$cityFocus" />
            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="ghost" wire:click="closeForms">Cancel</x-ui.button>
                <x-ui.button type="submit">Save city</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
