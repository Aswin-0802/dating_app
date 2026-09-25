<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Load countries, states and cities from a CSV or JSON file.
 *
 * Idempotent: rows are matched by (country, state, name), case-insensitively,
 * so running the same file twice creates nothing the second time. It never
 * touches is_active on a row that already exists — an operator who hid a
 * city keeps it hidden through every re-import — and it never deletes.
 * Coordinates are required, because the distance filter needs them: a row
 * without a usable latitude and longitude is skipped and reported, not
 * inserted half-done.
 *
 * File shape (CSV header, or the same keys on JSON objects):
 *   country_iso2, country_name, state_name, state_code, city_name,
 *   latitude, longitude, timezone, dial_code
 * state_name may be blank for countries without states; state_code,
 * timezone (defaults to UTC) and dial_code are optional.
 */
class ImportGeography extends Command
{
    protected $signature = 'platform:import-geography
        {file : Path to a .csv or .json file}
        {--dry-run : Report what would change without writing}
        {--update-coordinates : Overwrite latitude/longitude/timezone on cities that already exist (default: fill only where missing)}';

    protected $description = 'Import countries, states and cities from a CSV or JSON file (idempotent; never changes is_active)';

    private const REQUIRED = ['country_iso2', 'country_name', 'city_name', 'latitude', 'longitude'];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<int, string> */
    private array $problems = [];

    /** @var array<string, Country> iso2 => row, so one country is looked up once */
    private array $countries = [];

    /** @var array<string, State> "iso2|state" => row */
    private array $states = [];

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        try {
            $rows = $this->rows($path);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        // Artisan keeps one command instance per process (tests run several
        // imports in a row), so nothing from a previous file may linger.
        $this->countries = [];
        $this->states = [];
        $this->problems = [];
        $this->counts = array_fill_keys([
            'rows', 'countries_created', 'states_created', 'cities_created', 'cities_updated', 'cities_unchanged', 'skipped',
        ], 0);

        // One transaction for the whole file: a dry run rolls it back, and a
        // failure part-way leaves nothing half-imported.
        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $this->counts['rows']++;
                $this->importRow($index + 2, $row); // +2: header line, 1-based
            }

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        foreach ($this->problems as $problem) {
            $this->warn('  ! '.$problem);
        }

        $this->newLine();
        $this->table(
            ['Rows read', 'Countries created', 'States created', 'Cities created', 'Cities updated', 'Unchanged', 'Skipped'],
            [[
                $this->counts['rows'], $this->counts['countries_created'], $this->counts['states_created'],
                $this->counts['cities_created'], $this->counts['cities_updated'], $this->counts['cities_unchanged'], $this->counts['skipped'],
            ]],
        );

        $this->info($dry ? 'Dry run: nothing was written.' : 'Done. is_active was not changed on any existing row.');

        // Only a file that yielded nothing usable is a failure; skipped rows
        // are reported above and re-running a file that is already in is fine.
        return $this->counts['rows'] > 0 && $this->counts['skipped'] === $this->counts['rows'] ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, mixed> $row */
    private function importRow(int $line, array $row): void
    {
        $row = array_change_key_case(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row), CASE_LOWER);

        foreach (self::REQUIRED as $field) {
            if (! isset($row[$field]) || $row[$field] === '') {
                $this->skip($line, "missing {$field}");

                return;
            }
        }

        $iso2 = strtoupper((string) $row['country_iso2']);
        $latitude = $row['latitude'];
        $longitude = $row['longitude'];

        if (strlen($iso2) !== 2 || ! ctype_alpha($iso2)) {
            $this->skip($line, "country_iso2 '{$row['country_iso2']}' is not a two-letter code");

            return;
        }

        if (! is_numeric($latitude) || ! is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            $this->skip($line, "'{$row['city_name']}' has no usable latitude/longitude");

            return;
        }

        $timezone = (string) ($row['timezone'] ?? '');

        if ($timezone !== '' && ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $this->skip($line, "'{$row['city_name']}' has unknown time zone '{$timezone}'");

            return;
        }

        $country = $this->country($iso2, (string) $row['country_name'], (string) ($row['dial_code'] ?? ''));
        $stateName = (string) ($row['state_name'] ?? '');
        $state = $stateName === '' ? null : $this->state($country, $stateName, (string) ($row['state_code'] ?? ''));

        $existing = City::query()
            ->where('country_id', $country->id)
            ->where('state_id', $state?->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower((string) $row['city_name'])])
            ->first();

        if ($existing === null) {
            City::query()->create([
                'country_id' => $country->id,
                'state_id' => $state?->id,
                'name' => (string) $row['city_name'],
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
                'timezone' => $timezone !== '' ? $timezone : 'UTC',
                'is_focus' => false,
                'is_active' => true,
            ]);
            $this->counts['cities_created']++;

            return;
        }

        // An existing city: coordinates are filled where missing, or replaced
        // only when asked. is_active and is_focus are the operator's and are
        // never touched.
        $changes = [];
        $overwrite = (bool) $this->option('update-coordinates');

        if ($overwrite || $existing->latitude === null) {
            $changes['latitude'] = (float) $latitude;
        }

        if ($overwrite || $existing->longitude === null) {
            $changes['longitude'] = (float) $longitude;
        }

        if ($timezone !== '' && ($overwrite || $existing->timezone === 'UTC')) {
            $changes['timezone'] = $timezone;
        }

        $changes = array_filter($changes, fn ($value, $key) => $existing->{$key} != $value, ARRAY_FILTER_USE_BOTH);

        if ($changes === []) {
            $this->counts['cities_unchanged']++;

            return;
        }

        $existing->update($changes);
        $this->counts['cities_updated']++;
    }

    private function country(string $iso2, string $name, string $dialCode): Country
    {
        if (isset($this->countries[$iso2])) {
            return $this->countries[$iso2];
        }

        $country = Country::query()->where('iso2', $iso2)->first();

        if ($country === null) {
            $country = Country::query()->create([
                'iso2' => $iso2,
                'name' => $name,
                'dial_code' => $dialCode !== '' ? $dialCode : null,
                'is_active' => true,
            ]);
            $this->counts['countries_created']++;
        } elseif ($country->dial_code === null && $dialCode !== '') {
            $country->update(['dial_code' => $dialCode]);
        }

        return $this->countries[$iso2] = $country;
    }

    private function state(Country $country, string $name, string $code): State
    {
        $key = $country->iso2.'|'.Str::lower($name);

        if (isset($this->states[$key])) {
            return $this->states[$key];
        }

        $state = State::query()->where('country_id', $country->id)->whereRaw('LOWER(name) = ?', [Str::lower($name)])->first();

        if ($state === null) {
            $state = State::query()->create([
                'country_id' => $country->id,
                'name' => $name,
                'code' => $code !== '' ? strtoupper($code) : null,
                'is_active' => true,
                'sort_order' => (int) State::query()->where('country_id', $country->id)->max('sort_order') + 1,
            ]);
            $this->counts['states_created']++;
        } elseif ($state->code === null && $code !== '') {
            $state->update(['code' => strtoupper($code)]);
        }

        return $this->states[$key] = $state;
    }

    private function skip(int $line, string $why): void
    {
        $this->counts['skipped']++;
        $this->problems[] = "Line {$line} skipped: {$why}.";
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    private function rows(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (! is_array($decoded)) {
                throw new \RuntimeException('The JSON file is not an array of rows.');
            }

            return array_values(array_filter($decoded, 'is_array'));
        }

        if ($extension !== 'csv') {
            throw new \RuntimeException('Give a .csv or .json file.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException("Could not open {$path}.");
        }

        $header = fgetcsv($handle);

        if (! is_array($header)) {
            throw new \RuntimeException('The CSV file has no header line.');
        }

        $header = array_map(fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === []) {
                continue; // blank line
            }

            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
        }

        fclose($handle);

        return $rows;
    }
}
