<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\State;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * Turn the GeoNames "cities15000" dump into the CSV platform:import-geography
 * reads, for the countries this installation knows about.
 *
 * GeoNames (https://www.geonames.org) publishes every place with a population
 * of 15,000 or more under CC BY 4.0. The converted file is committed to the
 * repository at database/data/geonames-cities.csv so a fresh installation
 * has real cities with coordinates without internet access or a dependency on
 * geonames.org still serving the same files; --fetch re-downloads the three
 * source files and rebuilds it as the refresh path. The attribution line sits
 * at the top of the CSV itself, so it cannot be separated from the data.
 *
 * States are matched by name to the rows already in `states` (GeoNames'
 * admin1 names, with the few spellings that differ mapped in ALIASES). A city
 * whose admin1 has no matching state in a country that HAS states is reported
 * and still written — with the GeoNames name — so the importer creates that
 * state visibly rather than attaching the city to none.
 */
class ConvertGeonames extends Command
{
    protected $signature = 'platform:convert-geonames
        {--fetch : Download the three GeoNames files first (about 4 MB)}
        {--source= : Directory holding cities15000.txt, admin1CodesASCII.txt and countryInfo.txt (default storage/app/geonames)}
        {--out=database/data/geonames-cities.csv : Where to write the CSV}
        {--country=* : ISO2 codes to include (default: every country in the countries table)}';

    protected $description = 'Convert the GeoNames cities15000 dump into database/data/geonames-cities.csv for platform:import-geography';

    public const ATTRIBUTION = 'Data from GeoNames.org (https://www.geonames.org), licensed CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/). Places with population 15,000 or more; converted by php artisan platform:convert-geonames.';

    private const FILES = [
        'cities15000.zip' => 'https://download.geonames.org/export/dump/cities15000.zip',
        'admin1CodesASCII.txt' => 'https://download.geonames.org/export/dump/admin1CodesASCII.txt',
        'countryInfo.txt' => 'https://download.geonames.org/export/dump/countryInfo.txt',
    ];

    /** GeoNames admin1 names that differ from the names seeded in `states`: "ISO2|geonames name" => our name. */
    private const ALIASES = [
        'IN|Andaman and Nicobar' => 'Andaman and Nicobar Islands',
    ];

    public function handle(): int
    {
        $source = rtrim((string) ($this->option('source') ?: storage_path('app/geonames')), '/\\');

        if ($this->option('fetch') && ! $this->fetch($source)) {
            return self::FAILURE;
        }

        foreach (['cities15000.txt', 'admin1CodesASCII.txt', 'countryInfo.txt'] as $file) {
            if (! is_file("{$source}/{$file}")) {
                $this->error("{$source}/{$file} is missing. Run with --fetch, or put the GeoNames files there.");

                return self::FAILURE;
            }
        }

        $wanted = $this->option('country') ?: Country::query()->orderBy('iso2')->pluck('iso2')->all();
        $wanted = array_map('strtoupper', $wanted);

        $countries = $this->countryInfo("{$source}/countryInfo.txt");
        $admin1 = $this->admin1("{$source}/admin1CodesASCII.txt");
        $states = $this->knownStates();

        $rows = [];
        $counts = [];
        $unmatched = [];

        $handle = fopen("{$source}/cities15000.txt", 'rb');

        while (($line = fgets($handle)) !== false) {
            $f = explode("\t", rtrim($line, "\r\n"));

            if (count($f) < 18) {
                continue;
            }

            [$id, $name, $ascii, , $lat, $lng, , , $iso2, , $admin1Code] = $f;
            $timezone = $f[17];

            if (! in_array($iso2, $wanted, true)) {
                continue;
            }

            $country = $countries[$iso2] ?? null;

            if ($country === null) {
                continue;
            }

            $stateName = $admin1["{$iso2}.{$admin1Code}"] ?? '';
            $stateName = self::ALIASES["{$iso2}|{$stateName}"] ?? $stateName;

            // Countries with states in this installation: every city must land
            // on one of them, or say so. Countries without states get none.
            if (isset($states[$iso2])) {
                if ($stateName === '' || ! isset($states[$iso2][mb_strtolower($stateName)])) {
                    $unmatched["{$iso2}|{$stateName}"][] = $name;
                }
            } else {
                $stateName = '';
            }

            $stateCode = $stateName !== '' ? ($states[$iso2][mb_strtolower($stateName)] ?? '') : '';

            $rows[] = [$iso2, $country['name'], $stateName, $stateCode, $name, $lat, $lng, $timezone, $country['dial']];
            $counts[$iso2] = ($counts[$iso2] ?? 0) + 1;
        }

        fclose($handle);

        usort($rows, fn (array $a, array $b): int => [$a[0], $a[2], $a[4]] <=> [$b[0], $b[2], $b[4]]);

        $out = base_path((string) $this->option('out'));
        @mkdir(dirname($out), 0777, true);
        $csv = fopen($out, 'wb');
        fwrite($csv, '# '.self::ATTRIBUTION."\n");
        fwrite($csv, '# Countries: '.implode(', ', array_keys($counts)).' · rows: '.count($rows).' · generated '.now()->toDateString()."\n");
        fputcsv($csv, ['country_iso2', 'country_name', 'state_name', 'state_code', 'city_name', 'latitude', 'longitude', 'timezone', 'dial_code']);

        foreach ($rows as $row) {
            fputcsv($csv, $row);
        }

        fclose($csv);

        ksort($counts);
        $this->table(['Country', 'Cities'], collect($counts)->map(fn (int $n, string $iso): array => [$iso, $n])->values()->all());
        $this->info(count($rows).' cities written to '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $out));

        foreach ($unmatched as $key => $cities) {
            [$iso2, $stateName] = explode('|', $key, 2);
            $this->warn("  ! {$iso2}: GeoNames region '".($stateName ?: '(none)')."' matches no state in the database — ".count($cities).' cities ('.implode(', ', array_slice($cities, 0, 4)).(count($cities) > 4 ? ', …' : '').'). The importer will create it; add an alias if it is a spelling.');
        }

        return self::SUCCESS;
    }

    private function fetch(string $dir): bool
    {
        @mkdir($dir, 0777, true);

        foreach (self::FILES as $file => $url) {
            $this->line("Downloading {$file} …");
            $response = Http::timeout(120)->get($url);

            if (! $response->successful()) {
                $this->error("Could not download {$url} ({$response->status()}).");

                return false;
            }

            file_put_contents("{$dir}/{$file}", $response->body());
        }

        $zip = new ZipArchive;

        if ($zip->open("{$dir}/cities15000.zip") !== true || ! $zip->extractTo($dir)) {
            $this->error('Could not unzip cities15000.zip.');

            return false;
        }

        $zip->close();

        return true;
    }

    /** @return array<string, array{name: string, dial: string}> iso2 => name and dialling code */
    private function countryInfo(string $path): array
    {
        $out = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $f = explode("\t", $line);
            $dial = trim((string) ($f[12] ?? ''));
            // "1-268" style codes are trimmed to the country code; "+" is ours.
            $dial = $dial === '' ? '' : '+'.preg_replace('/[^0-9].*$/', '', $dial);

            $out[$f[0]] = ['name' => $f[4], 'dial' => $dial];
        }

        return $out;
    }

    /** @return array<string, string> "IN.25" => "Tamil Nadu" */
    private function admin1(string $path): array
    {
        $out = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $f = explode("\t", $line);

            if (count($f) >= 2) {
                $out[$f[0]] = $f[1];
            }
        }

        return $out;
    }

    /** @return array<string, array<string, string>> iso2 => [lowercased state name => code] for countries that have states */
    private function knownStates(): array
    {
        $out = [];

        foreach (State::query()->with('country:id,iso2')->get() as $state) {
            $out[$state->country->iso2][mb_strtolower($state->name)] = (string) ($state->code ?? '');
        }

        return $out;
    }
}
