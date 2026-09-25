{{--
    The city type-ahead. Needs the PicksCity trait on the component and
    `$error` (the city_id validation message, or null) from the caller.
--}}
<div class="space-y-1.5">
    <label for="city-search" class="block text-sm font-medium text-foreground">City</label>

    @if ($cityLabel !== null)
        <div class="flex h-9 items-center justify-between gap-2 rounded-md border border-input bg-card px-3 text-sm">
            <span class="min-w-0 truncate">{{ $cityLabel }}</span>
            <button type="button" wire:click="clearCity" class="shrink-0 text-xs text-muted-foreground hover:text-foreground">Change</button>
        </div>
    @else
        <input
            id="city-search"
            type="search"
            wire:model.live.debounce.300ms="citySearch"
            placeholder="Start typing your city"
            autocomplete="off"
            aria-autocomplete="list"
            aria-controls="city-results"
            @class([
                'h-9 w-full rounded-md border bg-card px-3 text-sm placeholder:text-muted-foreground',
                'border-destructive' => filled($error),
                'border-input' => blank($error),
            ])
        >

        @if (mb_strlen(trim($citySearch)) >= 2)
            <ul id="city-results" role="listbox" class="max-h-56 overflow-y-auto rounded-md border border-border bg-card text-sm shadow-sm">
                @forelse ($this->cityResults as $result)
                    <li role="option" wire:key="city-result-{{ $result['id'] }}">
                        <button type="button" wire:click="chooseCity({{ $result['id'] }})" class="w-full px-3 py-2 text-left hover:bg-muted">{{ $result['label'] }}</button>
                    </li>
                @empty
                    <li class="px-3 py-2 text-muted-foreground">No city starts with “{{ trim($citySearch) }}”. Check the spelling; if your city is missing, tell support.</li>
                @endforelse
                @if (count($this->cityResults) >= App\Http\Controllers\Api\V1\GeographyController::CITY_LIMIT)
                    <li class="px-3 py-2 text-xs text-muted-foreground">Showing the first {{ App\Http\Controllers\Api\V1\GeographyController::CITY_LIMIT }} — keep typing to narrow it down.</li>
                @endif
            </ul>
        @elseif (trim($citySearch) !== '')
            <p class="text-xs text-muted-foreground">Type at least two letters.</p>
        @endif
    @endif

    @if (filled($error))
        <p class="text-xs text-destructive">{{ $error }}</p>
    @endif
</div>
