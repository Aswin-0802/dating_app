<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Member\Auth\Register;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * GET /api/v1/cities must scale past 500 rows: a type-ahead, never a
 * truncated list. Seeds 3,000 cities and checks that a search returns the
 * whole match set even when every match sorts past the old cap.
 */
class CitySearchTest extends TestCase
{
    use RefreshDatabase;

    private Country $india;

    private State $tamilNadu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingSeeder::class]);

        $this->india = Country::query()->create(['name' => 'India', 'iso2' => 'IN', 'dial_code' => '+91', 'is_active' => true]);
        $other = Country::query()->create(['name' => 'Otherland', 'iso2' => 'OL', 'dial_code' => '+999', 'is_active' => true]);
        $this->tamilNadu = State::query()->create(['country_id' => $this->india->id, 'name' => 'Tamil Nadu', 'code' => 'TN', 'is_active' => true]);
        $kerala = State::query()->create(['country_id' => $this->india->id, 'name' => 'Kerala', 'code' => 'KL', 'is_active' => true]);

        // 3,000 cities whose names sort AFTER anything a member would search
        // for, so a match set beyond row 500 proves the point.
        $rows = [];
        for ($i = 1; $i <= 3000; $i++) {
            $rows[] = [
                'country_id' => $this->india->id,
                'state_id' => $i % 2 === 0 ? $this->tamilNadu->id : $kerala->id,
                'name' => sprintf('Town %04d', $i),
                'latitude' => 10 + $i / 10000,
                'longitude' => 76 + $i / 10000,
                'timezone' => 'Asia/Kolkata',
                'is_focus' => false,
                'is_active' => true,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('cities')->insert($chunk);
        }

        // The seven cities a member actually types for, all in Tamil Nadu,
        // plus a namesake abroad.
        foreach (['Coimbatore', 'Coimbatore North', 'Coimbatore South', 'Coimbatore East', 'Coimbatore West', 'Coimbatore Rural', 'Coimbatore Airport'] as $name) {
            City::query()->create(['country_id' => $this->india->id, 'state_id' => $this->tamilNadu->id, 'name' => $name, 'latitude' => 11.0, 'longitude' => 76.9, 'timezone' => 'Asia/Kolkata', 'is_active' => true]);
        }

        City::query()->create(['country_id' => $other->id, 'state_id' => null, 'name' => 'Coimbatore', 'latitude' => 0, 'longitude' => 0, 'timezone' => 'UTC', 'is_active' => true]);
    }

    public function test_a_search_returns_the_whole_match_set_beyond_the_old_cap(): void
    {
        // 'Town 299x' are rows 2990..2999: alphabetically far past 500.
        $names = $this->getJson('/api/v1/cities?search=Town%20299')->assertOk()
            ->assertJsonPath('meta.truncated', false)
            ->json('data.*.name');

        $this->assertSame(array_map(fn (int $i): string => sprintf('Town %04d', $i), range(2990, 2999)), $names);
    }

    public function test_a_search_finds_a_city_wherever_it_sorts_and_filters_by_country_and_state(): void
    {
        $response = $this->getJson('/api/v1/cities?search=Coimb')->assertOk();
        $this->assertCount(8, $response->json('data'), 'EXPECTED every Coimbatore in every country.');

        $names = $this->getJson('/api/v1/cities?search=Coimb&country=IN')->assertOk()->json('data.*.name');
        $this->assertCount(7, $names);
        $this->assertContains('Coimbatore Rural', $names);

        $this->assertCount(7, $this->getJson("/api/v1/cities?search=Coimb&state={$this->tamilNadu->id}")->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/cities?search=Coimb&country=OL&state='.$this->tamilNadu->id)->json('data'));
    }

    public function test_a_broad_search_is_capped_and_says_so(): void
    {
        $response = $this->getJson('/api/v1/cities?search=Town')->assertOk()->assertJsonPath('meta.truncated', true);

        $this->assertCount(100, $response->json('data'));
    }

    public function test_a_one_character_search_is_refused(): void
    {
        $this->getJson('/api/v1/cities?search=C')->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('search');
    }

    public function test_the_search_honours_hidden_places(): void
    {
        City::query()->where('name', 'Coimbatore North')->update(['is_active' => false]);
        $this->tamilNadu->update(['is_active' => false]);

        $names = $this->getJson('/api/v1/cities?search=Coimb')->assertOk()->json('data.*.name');

        $this->assertSame(['Coimbatore'], $names, 'EXPECTED only the namesake abroad once Tamil Nadu is hidden.');
    }

    public function test_like_wildcards_in_the_search_are_literal(): void
    {
        $this->assertCount(0, $this->getJson('/api/v1/cities?search=%25%25')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/cities?search=T_wn')->assertOk()->json('data'));
    }

    public function test_the_website_picker_searches_the_same_way(): void
    {
        $component = Livewire::test(Register::class)->set('citySearch', 'Coimbatore R');
        $results = $component->instance()->cityResults();

        $this->assertCount(1, $results);
        $this->assertSame('Coimbatore Rural, Tamil Nadu', $results[0]['label']);

        $component->call('chooseCity', $results[0]['id'])
            ->assertSet('city_id', $results[0]['id'])
            ->assertSet('cityLabel', 'Coimbatore Rural, Tamil Nadu');

        // One character is not a search on the website either.
        $this->assertSame([], Livewire::test(Register::class)->set('citySearch', 'C')->instance()->cityResults());
    }

    public function test_the_search_uses_an_index(): void
    {
        $plan = collect(DB::select("EXPLAIN SELECT id, name FROM cities WHERE is_active = 1 AND name LIKE 'Coimb%' ORDER BY name LIMIT 101"))->first();

        $this->assertNotSame('ALL', $plan->type, 'EXPECTED the city search not to scan the whole table.');
        $this->assertNotNull($plan->key);
    }
}
