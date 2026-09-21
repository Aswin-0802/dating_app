<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Seeder;

/**
 * States, provinces and regions for the countries that ship with the product.
 *
 * India is covered completely because it is where the product is most likely
 * to launch, and because "which state" is how people there describe where
 * they live. Elsewhere only the regions the seeded cities sit in are
 * included — an operator adds the rest from Settings → Locations as they open
 * those markets, which is the point of the screen.
 */
class StateSeeder extends Seeder
{
    /** @var array<string, array<int, array{0: string, 1: ?string}>> iso2 => [[name, code]] */
    private const STATES = [
        'IN' => [
            ['Andhra Pradesh', 'AP'], ['Arunachal Pradesh', 'AR'], ['Assam', 'AS'], ['Bihar', 'BR'],
            ['Chhattisgarh', 'CG'], ['Goa', 'GA'], ['Gujarat', 'GJ'], ['Haryana', 'HR'],
            ['Himachal Pradesh', 'HP'], ['Jharkhand', 'JH'], ['Karnataka', 'KA'], ['Kerala', 'KL'],
            ['Madhya Pradesh', 'MP'], ['Maharashtra', 'MH'], ['Manipur', 'MN'], ['Meghalaya', 'ML'],
            ['Mizoram', 'MZ'], ['Nagaland', 'NL'], ['Odisha', 'OD'], ['Punjab', 'PB'],
            ['Rajasthan', 'RJ'], ['Sikkim', 'SK'], ['Tamil Nadu', 'TN'], ['Telangana', 'TG'],
            ['Tripura', 'TR'], ['Uttar Pradesh', 'UP'], ['Uttarakhand', 'UK'], ['West Bengal', 'WB'],
            ['Andaman and Nicobar Islands', 'AN'], ['Chandigarh', 'CH'],
            ['Dadra and Nagar Haveli and Daman and Diu', 'DH'], ['Delhi', 'DL'],
            ['Jammu and Kashmir', 'JK'], ['Ladakh', 'LA'], ['Lakshadweep', 'LD'], ['Puducherry', 'PY'],
        ],
        'US' => [
            ['California', 'CA'], ['Colorado', 'CO'], ['Florida', 'FL'], ['Georgia', 'GA'],
            ['Illinois', 'IL'], ['Massachusetts', 'MA'], ['New York', 'NY'], ['Texas', 'TX'],
            ['Washington', 'WA'],
        ],
        'GB' => [['England', null], ['Scotland', null], ['Wales', null], ['Northern Ireland', null]],
        'CA' => [['Ontario', 'ON'], ['British Columbia', 'BC'], ['Quebec', 'QC']],
        'AU' => [['New South Wales', 'NSW'], ['Victoria', 'VIC'], ['Queensland', 'QLD']],
        'DE' => [['Berlin', null], ['Bavaria', null], ['Hamburg', null], ['North Rhine-Westphalia', null]],
        'FR' => [['Île-de-France', null], ['Auvergne-Rhône-Alpes', null], ['Provence-Alpes-Côte d’Azur', null]],
        'ES' => [['Community of Madrid', null], ['Catalonia', null], ['Valencian Community', null]],
        'IT' => [['Lazio', null], ['Lombardy', null]],
        'NL' => [['North Holland', null], ['South Holland', null]],
        'BR' => [['São Paulo', 'SP'], ['Rio de Janeiro', 'RJ']],
        'MX' => [['Mexico City', 'CDMX'], ['Jalisco', 'JAL']],
        'ZA' => [['Western Cape', 'WC'], ['Gauteng', 'GP']],
        'NG' => [['Lagos', 'LA'], ['Federal Capital Territory', 'FC']],
        'AE' => [['Dubai', null], ['Abu Dhabi', null]],
        'JP' => [['Tokyo', null], ['Osaka', null]],
        'IE' => [['Leinster', null], ['Munster', null]],
        'SE' => [['Stockholm County', null]],
        'PL' => [['Masovian', null], ['Lesser Poland', null]],
        'PT' => [['Lisbon', null]],
        'AR' => [['Buenos Aires', null]],
        'KE' => [['Nairobi County', null]],
        'NZ' => [['Auckland', null]],
    ];

    /** @var array<string, string> "iso2:city" => state name */
    private const CITY_STATES = [
        'IN:Mumbai' => 'Maharashtra', 'IN:Bengaluru' => 'Karnataka', 'IN:Delhi' => 'Delhi',
        'US:New York' => 'New York', 'US:Los Angeles' => 'California', 'US:Chicago' => 'Illinois',
        'US:Austin' => 'Texas', 'US:Seattle' => 'Washington', 'US:Miami' => 'Florida',
        'US:Denver' => 'Colorado', 'US:Boston' => 'Massachusetts', 'US:San Francisco' => 'California',
        'US:Atlanta' => 'Georgia',
        'GB:London' => 'England', 'GB:Manchester' => 'England', 'GB:Birmingham' => 'England',
        'GB:Bristol' => 'England', 'GB:Leeds' => 'England', 'GB:Brighton' => 'England',
        'GB:Edinburgh' => 'Scotland', 'GB:Glasgow' => 'Scotland',
        'CA:Toronto' => 'Ontario', 'CA:Vancouver' => 'British Columbia', 'CA:Montreal' => 'Quebec',
        'AU:Sydney' => 'New South Wales', 'AU:Melbourne' => 'Victoria', 'AU:Brisbane' => 'Queensland',
        'DE:Berlin' => 'Berlin', 'DE:Munich' => 'Bavaria', 'DE:Hamburg' => 'Hamburg',
        'DE:Cologne' => 'North Rhine-Westphalia',
        'FR:Paris' => 'Île-de-France', 'FR:Lyon' => 'Auvergne-Rhône-Alpes',
        'FR:Marseille' => 'Provence-Alpes-Côte d’Azur',
        'ES:Madrid' => 'Community of Madrid', 'ES:Barcelona' => 'Catalonia',
        'ES:Valencia' => 'Valencian Community',
        'IT:Rome' => 'Lazio', 'IT:Milan' => 'Lombardy',
        'NL:Amsterdam' => 'North Holland', 'NL:Rotterdam' => 'South Holland',
        'BR:Sao Paulo' => 'São Paulo', 'BR:Rio de Janeiro' => 'Rio de Janeiro',
        'MX:Mexico City' => 'Mexico City', 'MX:Guadalajara' => 'Jalisco',
        'ZA:Cape Town' => 'Western Cape', 'ZA:Johannesburg' => 'Gauteng',
        'NG:Lagos' => 'Lagos', 'NG:Abuja' => 'Federal Capital Territory',
        'AE:Dubai' => 'Dubai',
        'JP:Tokyo' => 'Tokyo', 'JP:Osaka' => 'Osaka',
        'IE:Dublin' => 'Leinster',
        'SE:Stockholm' => 'Stockholm County',
        'PL:Warsaw' => 'Masovian', 'PL:Krakow' => 'Lesser Poland',
        'PT:Lisbon' => 'Lisbon',
        'AR:Buenos Aires' => 'Buenos Aires',
        'KE:Nairobi' => 'Nairobi County',
        'NZ:Auckland' => 'Auckland',
    ];

    public function run(): void
    {
        $countries = Country::query()->pluck('id', 'iso2');
        $stateIds = [];

        foreach (self::STATES as $iso2 => $states) {
            if (! isset($countries[$iso2])) {
                continue;
            }

            foreach ($states as $index => [$name, $code]) {
                // firstOrCreate: a reseed must not undo renames or hides made
                // in Settings → Locations.
                $state = State::query()->firstOrCreate(
                    ['country_id' => $countries[$iso2], 'name' => $name],
                    ['code' => $code, 'is_active' => true, 'sort_order' => $index],
                );

                $stateIds["{$iso2}:{$name}"] = $state->id;
            }
        }

        $assigned = 0;

        foreach (self::CITY_STATES as $key => $stateName) {
            [$iso2, $cityName] = explode(':', $key, 2);

            if (! isset($countries[$iso2], $stateIds["{$iso2}:{$stateName}"])) {
                continue;
            }

            $assigned += City::query()
                ->where('country_id', $countries[$iso2])
                ->where('name', $cityName)
                ->whereNull('state_id')
                ->update(['state_id' => $stateIds["{$iso2}:{$stateName}"]]);
        }

        $this->command?->info('Seeded '.count($stateIds)." states and placed {$assigned} cities in one.");
    }
}
