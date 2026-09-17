<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use Illuminate\Database\Seeder;

/**
 * Countries and cities, from a static table — no network access required.
 *
 * The 12 focus cities carry most of the seeded member base, so the per-city
 * gender balance screen has enough volume per row to say something real.
 */
class GeographySeeder extends Seeder
{
    /** @return array<string, array{0: string, 1: string}> iso2 => [name, dial] */
    private const COUNTRIES = [
        'GB' => ['United Kingdom', '+44'],
        'US' => ['United States', '+1'],
        'CA' => ['Canada', '+1'],
        'IE' => ['Ireland', '+353'],
        'FR' => ['France', '+33'],
        'DE' => ['Germany', '+49'],
        'ES' => ['Spain', '+34'],
        'IT' => ['Italy', '+39'],
        'NL' => ['Netherlands', '+31'],
        'SE' => ['Sweden', '+46'],
        'PL' => ['Poland', '+48'],
        'PT' => ['Portugal', '+351'],
        'BR' => ['Brazil', '+55'],
        'MX' => ['Mexico', '+52'],
        'AR' => ['Argentina', '+54'],
        'ZA' => ['South Africa', '+27'],
        'NG' => ['Nigeria', '+234'],
        'KE' => ['Kenya', '+254'],
        'IN' => ['India', '+91'],
        'SG' => ['Singapore', '+65'],
        'AU' => ['Australia', '+61'],
        'NZ' => ['New Zealand', '+64'],
        'JP' => ['Japan', '+81'],
        'AE' => ['United Arab Emirates', '+971'],
    ];

    /** @return array<int, array{0: string, 1: string, 2: float, 3: float, 4: string, 5: bool}> */
    private const CITIES = [
        // iso2, name, lat, lng, timezone, is_focus
        ['GB', 'London', 51.5072, -0.1276, 'Europe/London', true],
        ['GB', 'Manchester', 53.4808, -2.2426, 'Europe/London', true],
        ['GB', 'Birmingham', 52.4862, -1.8904, 'Europe/London', false],
        ['GB', 'Bristol', 51.4545, -2.5879, 'Europe/London', false],
        ['GB', 'Edinburgh', 55.9533, -3.1883, 'Europe/London', false],
        ['GB', 'Leeds', 53.8008, -1.5491, 'Europe/London', false],
        ['GB', 'Glasgow', 55.8642, -4.2518, 'Europe/London', false],
        ['GB', 'Brighton', 50.8225, -0.1372, 'Europe/London', false],
        ['US', 'New York', 40.7128, -74.0060, 'America/New_York', true],
        ['US', 'Los Angeles', 34.0522, -118.2437, 'America/Los_Angeles', true],
        ['US', 'Chicago', 41.8781, -87.6298, 'America/Chicago', true],
        ['US', 'Austin', 30.2672, -97.7431, 'America/Chicago', true],
        ['US', 'Seattle', 47.6062, -122.3321, 'America/Los_Angeles', false],
        ['US', 'Miami', 25.7617, -80.1918, 'America/New_York', false],
        ['US', 'Denver', 39.7392, -104.9903, 'America/Denver', false],
        ['US', 'Boston', 42.3601, -71.0589, 'America/New_York', false],
        ['US', 'San Francisco', 37.7749, -122.4194, 'America/Los_Angeles', false],
        ['US', 'Atlanta', 33.7490, -84.3880, 'America/New_York', false],
        ['CA', 'Toronto', 43.6532, -79.3832, 'America/Toronto', true],
        ['CA', 'Vancouver', 49.2827, -123.1207, 'America/Vancouver', false],
        ['CA', 'Montreal', 45.5019, -73.5674, 'America/Toronto', false],
        ['IE', 'Dublin', 53.3498, -6.2603, 'Europe/Dublin', false],
        ['FR', 'Paris', 48.8566, 2.3522, 'Europe/Paris', true],
        ['FR', 'Lyon', 45.7640, 4.8357, 'Europe/Paris', false],
        ['FR', 'Marseille', 43.2965, 5.3698, 'Europe/Paris', false],
        ['DE', 'Berlin', 52.5200, 13.4050, 'Europe/Berlin', true],
        ['DE', 'Munich', 48.1351, 11.5820, 'Europe/Berlin', false],
        ['DE', 'Hamburg', 53.5511, 9.9937, 'Europe/Berlin', false],
        ['DE', 'Cologne', 50.9375, 6.9603, 'Europe/Berlin', false],
        ['ES', 'Madrid', 40.4168, -3.7038, 'Europe/Madrid', true],
        ['ES', 'Barcelona', 41.3851, 2.1734, 'Europe/Madrid', true],
        ['ES', 'Valencia', 39.4699, -0.3763, 'Europe/Madrid', false],
        ['IT', 'Rome', 41.9028, 12.4964, 'Europe/Rome', false],
        ['IT', 'Milan', 45.4642, 9.1900, 'Europe/Rome', false],
        ['NL', 'Amsterdam', 52.3676, 4.9041, 'Europe/Amsterdam', true],
        ['NL', 'Rotterdam', 51.9244, 4.4777, 'Europe/Amsterdam', false],
        ['SE', 'Stockholm', 59.3293, 18.0686, 'Europe/Stockholm', false],
        ['PL', 'Warsaw', 52.2297, 21.0122, 'Europe/Warsaw', false],
        ['PL', 'Krakow', 50.0647, 19.9450, 'Europe/Warsaw', false],
        ['PT', 'Lisbon', 38.7223, -9.1393, 'Europe/Lisbon', false],
        ['BR', 'Sao Paulo', -23.5505, -46.6333, 'America/Sao_Paulo', true],
        ['BR', 'Rio de Janeiro', -22.9068, -43.1729, 'America/Sao_Paulo', false],
        ['MX', 'Mexico City', 19.4326, -99.1332, 'America/Mexico_City', false],
        ['MX', 'Guadalajara', 20.6597, -103.3496, 'America/Mexico_City', false],
        ['AR', 'Buenos Aires', -34.6037, -58.3816, 'America/Argentina/Buenos_Aires', false],
        ['ZA', 'Cape Town', -33.9249, 18.4241, 'Africa/Johannesburg', false],
        ['ZA', 'Johannesburg', -26.2041, 28.0473, 'Africa/Johannesburg', false],
        ['NG', 'Lagos', 6.5244, 3.3792, 'Africa/Lagos', true],
        ['NG', 'Abuja', 9.0765, 7.3986, 'Africa/Lagos', false],
        ['KE', 'Nairobi', -1.2921, 36.8219, 'Africa/Nairobi', false],
        ['IN', 'Mumbai', 19.0760, 72.8777, 'Asia/Kolkata', false],
        ['IN', 'Bengaluru', 12.9716, 77.5946, 'Asia/Kolkata', false],
        ['IN', 'Delhi', 28.6139, 77.2090, 'Asia/Kolkata', false],
        ['SG', 'Singapore', 1.3521, 103.8198, 'Asia/Singapore', false],
        ['AU', 'Sydney', -33.8688, 151.2093, 'Australia/Sydney', true],
        ['AU', 'Melbourne', -37.8136, 144.9631, 'Australia/Melbourne', false],
        ['AU', 'Brisbane', -27.4698, 153.0251, 'Australia/Brisbane', false],
        ['NZ', 'Auckland', -36.8485, 174.7633, 'Pacific/Auckland', false],
        ['JP', 'Tokyo', 35.6762, 139.6503, 'Asia/Tokyo', false],
        ['JP', 'Osaka', 34.6937, 135.5023, 'Asia/Tokyo', false],
        ['AE', 'Dubai', 25.2048, 55.2708, 'Asia/Dubai', false],
    ];

    public function run(): void
    {
        $countryIds = [];

        foreach (self::COUNTRIES as $iso2 => [$name, $dial]) {
            $countryIds[$iso2] = Country::query()->updateOrCreate(
                ['iso2' => $iso2],
                ['name' => $name, 'dial_code' => $dial, 'is_active' => true],
            )->id;
        }

        foreach (self::CITIES as [$iso2, $name, $lat, $lng, $timezone, $isFocus]) {
            City::query()->updateOrCreate(
                ['country_id' => $countryIds[$iso2], 'name' => $name],
                [
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'timezone' => $timezone,
                    'is_focus' => $isFocus,
                ],
            );
        }

        $this->command?->info(
            'Seeded '.count(self::COUNTRIES).' countries and '.count(self::CITIES).' cities.'
        );
    }
}
