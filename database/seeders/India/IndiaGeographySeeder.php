<?php

declare(strict_types=1);

namespace Database\Seeders\India;

use App\Models\Country;
use App\Models\Setting;
use App\Models\State;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * The India launch footprint: every Indian city with a population of 15,000
 * or more (GeoNames, CC BY 4.0 — see database/data/geonames-cities.csv),
 * all 36 states and union territories in the picker, and Tamil Nadu the only
 * state shown at sign-up.
 *
 *   php artisan db:seed --class="Database\Seeders\India\IndiaGeographySeeder"
 *
 * Safe to run again: the import matches what is already there, and the
 * hide/show switches are set only the first time — after that they belong to
 * whoever runs Masters → Locations, so a state an operator has since shown
 * is not hidden again by a re-run.
 */
class IndiaGeographySeeder extends Seeder
{
    public const CSV = 'database/data/geonames-cities.csv';

    private const FOOTPRINT_KEY = 'geography.india_footprint_set';

    public function run(): void
    {
        Artisan::call('platform:import-geography', [
            'file' => base_path(self::CSV),
            '--country' => ['IN'],
        ], $this->command?->getOutput());

        if (Setting::query()->where('key', self::FOOTPRINT_KEY)->exists()) {
            $this->command?->info('India footprint already set; switches left as the console has them.');

            return;
        }

        $india = Country::query()->where('iso2', 'IN')->firstOrFail();
        $india->update(['is_active' => true]);

        // Tamil Nadu is the launch state. The others stay in the list, hidden,
        // so switching one on later is one click in Masters → Locations.
        State::query()->where('country_id', $india->id)->where('code', '!=', 'TN')->update(['is_active' => false]);
        State::query()->where('country_id', $india->id)->where('code', 'TN')->update(['is_active' => true]);

        // Any other country in the table is not part of this launch. Hidden,
        // not deleted: a demo member may live there, and hiding keeps history.
        Country::query()->where('iso2', '!=', 'IN')->update(['is_active' => false]);

        Setting::query()->updateOrCreate(['key' => self::FOOTPRINT_KEY], [
            'value' => now()->toDateString(),
            'type' => 'text',
            'group' => 'geography',
            'label' => 'India footprint set on',
            'description' => 'Written by IndiaGeographySeeder the first time it ran. Delete this row to let the seeder reset the country and state switches.',
            'is_public' => false,
            'sort_order' => 99,
        ]);

        $this->command?->info('India footprint set: Tamil Nadu shown at sign-up, other states and countries hidden.');
    }
}
