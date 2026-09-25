<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * platform:import-geography — idempotent, never touches is_active, refuses
 * rows without coordinates.
 */
class GeographyImportTest extends TestCase
{
    use RefreshDatabase;

    private const CSV = <<<'CSV'
country_iso2,country_name,state_name,state_code,city_name,latitude,longitude,timezone,dial_code
IN,India,Tamil Nadu,TN,Chennai,13.0827,80.2707,Asia/Kolkata,+91
IN,India,Tamil Nadu,TN,Coimbatore,11.0168,76.9558,Asia/Kolkata,+91
IN,India,Tamil Nadu,TN,Madurai,9.9252,78.1198,Asia/Kolkata,+91
IN,India,Karnataka,KA,Bengaluru,12.9716,77.5946,Asia/Kolkata,+91
SG,Singapore,,,Singapore,1.3521,103.8198,Asia/Singapore,+65
IN,India,Tamil Nadu,TN,Nowhere,,,Asia/Kolkata,+91
CSV;

    private function file(string $contents, string $extension = 'csv'): string
    {
        $path = sys_get_temp_dir().'/geo-'.uniqid().'.'.$extension;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_the_import_creates_places_once_and_re_running_it_creates_nothing(): void
    {
        $path = $this->file(self::CSV);

        $this->artisan('platform:import-geography', ['file' => $path])->assertSuccessful();

        $this->assertSame(2, Country::query()->count());
        $this->assertSame(2, State::query()->count());
        $this->assertSame(5, City::query()->count());
        $this->assertSame('TN', State::query()->where('name', 'Tamil Nadu')->value('code'));
        $this->assertSame('+91', Country::query()->where('iso2', 'IN')->value('dial_code'));
        $this->assertNull(City::query()->where('name', 'Singapore')->value('state_id'));

        // Again, with a different case: nothing new.
        $this->artisan('platform:import-geography', ['file' => $this->file(str_replace('Chennai', 'CHENNAI', self::CSV))])->assertSuccessful();

        $this->assertSame(2, Country::query()->count());
        $this->assertSame(2, State::query()->count());
        $this->assertSame(5, City::query()->count());
        $this->assertSame('Chennai', City::query()->where('name', 'Chennai')->value('name'), 'EXPECTED the existing spelling to be kept.');
    }

    public function test_a_row_without_coordinates_is_skipped_and_reported(): void
    {
        $this->artisan('platform:import-geography', ['file' => $this->file(self::CSV)])
            ->expectsOutputToContain('Line 7 skipped: missing latitude')
            ->assertSuccessful();

        $this->assertFalse(City::query()->where('name', 'Nowhere')->exists());
    }

    public function test_is_active_is_never_changed_on_an_existing_row(): void
    {
        $this->artisan('platform:import-geography', ['file' => $this->file(self::CSV)])->assertSuccessful();

        City::query()->where('name', 'Madurai')->update(['is_active' => false, 'is_focus' => true]);
        State::query()->where('name', 'Karnataka')->update(['is_active' => false]);
        Country::query()->where('iso2', 'SG')->update(['is_active' => false]);

        $this->artisan('platform:import-geography', ['file' => $this->file(self::CSV)])->assertSuccessful();

        $madurai = City::query()->where('name', 'Madurai')->firstOrFail();
        $this->assertFalse($madurai->is_active);
        $this->assertTrue($madurai->is_focus);
        $this->assertFalse((bool) State::query()->where('name', 'Karnataka')->value('is_active'));
        $this->assertFalse((bool) Country::query()->where('iso2', 'SG')->value('is_active'));
    }

    public function test_coordinates_are_filled_where_missing_and_replaced_only_when_asked(): void
    {
        $this->artisan('platform:import-geography', ['file' => $this->file(self::CSV)])->assertSuccessful();

        City::query()->where('name', 'Chennai')->update(['latitude' => null, 'longitude' => null]);
        City::query()->where('name', 'Madurai')->update(['latitude' => 1.0, 'longitude' => 1.0]);

        $this->artisan('platform:import-geography', ['file' => $this->file(self::CSV)])->assertSuccessful();

        $this->assertEqualsWithDelta(13.0827, City::query()->where('name', 'Chennai')->value('latitude'), 0.0001, 'EXPECTED a missing coordinate to be filled.');
        $this->assertEqualsWithDelta(1.0, City::query()->where('name', 'Madurai')->value('latitude'), 0.0001, 'EXPECTED an existing coordinate to be kept.');

        $this->artisan('platform:import-geography', ['file' => $this->file(self::CSV), '--update-coordinates' => true])->assertSuccessful();
        $this->assertEqualsWithDelta(9.9252, City::query()->where('name', 'Madurai')->value('latitude'), 0.0001);
    }

    public function test_json_is_accepted_and_a_dry_run_writes_nothing(): void
    {
        $json = json_encode([
            ['country_iso2' => 'in', 'country_name' => 'India', 'state_name' => 'Kerala', 'state_code' => 'KL', 'city_name' => 'Kochi', 'latitude' => 9.9312, 'longitude' => 76.2673, 'timezone' => 'Asia/Kolkata'],
        ]);

        $this->artisan('platform:import-geography', ['file' => $this->file((string) $json, 'json'), '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
        $this->assertSame(0, City::query()->count());

        $this->artisan('platform:import-geography', ['file' => $this->file((string) $json, 'json')])->assertSuccessful();
        $this->assertSame('Kochi', City::query()->where('name', 'Kochi')->value('name'));
        $this->assertSame('IN', Country::query()->firstOrFail()->iso2);
    }

    public function test_a_bad_file_fails_plainly(): void
    {
        $this->artisan('platform:import-geography', ['file' => '/nowhere/places.csv'])->assertFailed();
        $this->artisan('platform:import-geography', ['file' => $this->file('not json', 'json')])->assertFailed();
        $this->artisan('platform:import-geography', ['file' => $this->file('x', 'txt')])->assertFailed();
    }
}
