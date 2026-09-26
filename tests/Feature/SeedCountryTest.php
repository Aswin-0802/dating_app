<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Database\Seeders\India\IndiaGeographySeeder;
use Database\Seeders\India\IndiaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The country set-up command and the India profile the demo seeders follow.
 *
 * The full India seed (photos and all) is exercised by hand and by the
 * committed backup; these tests cover the guards that keep the command from
 * running into a live database and the profile that keeps every demo member
 * inside Tamil Nadu.
 */
class SeedCountryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_country_is_refused_and_names_the_known_ones(): void
    {
        $this->artisan('platform:seed-country', ['country' => 'atlantis'])
            ->expectsOutputToContain("No seeders for 'atlantis'. Known: india.")
            ->assertFailed();
    }

    public function test_a_database_with_members_is_not_reseeded_without_fresh(): void
    {
        AppUser::factory()->create();

        $this->artisan('platform:seed-country', ['country' => 'india'])
            ->expectsOutputToContain('This database already has members. Run with --fresh')
            ->assertFailed();

        $this->assertSame(1, AppUser::query()->count());
    }

    public function test_the_india_profile_draws_only_from_selectable_tamil_nadu_cities(): void
    {
        $india = Country::query()->create(['iso2' => 'IN', 'name' => 'India', 'dial_code' => '+91', 'is_active' => true]);
        $tamilNadu = State::query()->create(['country_id' => $india->id, 'code' => 'TN', 'name' => 'Tamil Nadu', 'is_active' => true, 'sort_order' => 1]);
        $kerala = State::query()->create(['country_id' => $india->id, 'code' => 'KL', 'name' => 'Kerala', 'is_active' => false, 'sort_order' => 2]);

        $city = fn (State $state, string $name, bool $active) => City::query()->create([
            'country_id' => $india->id, 'state_id' => $state->id, 'name' => $name,
            'latitude' => 13.08, 'longitude' => 80.27, 'timezone' => 'Asia/Kolkata', 'is_focus' => false, 'is_active' => $active,
        ]);
        $chennai = $city($tamilNadu, 'Chennai', true);
        $city($tamilNadu, 'Hidden town', false);
        $city($kerala, 'Kochi', true);

        $this->assertSame([$chennai->id], IndiaProfile::cities()->pluck('id')->all());
        $this->assertMatchesRegularExpression('/^\+91[6-9]\d{9}$/', IndiaProfile::phone(fake()));
        $this->assertSame('Chennai', array_key_first(IndiaProfile::FOCUS));
    }

    public function test_the_india_geography_seeder_sets_the_footprint_once(): void
    {
        $this->seed(IndiaGeographySeeder::class);

        $india = Country::query()->where('iso2', 'IN')->firstOrFail();
        $this->assertTrue($india->is_active);
        $this->assertSame(1, State::query()->where('country_id', $india->id)->where('is_active', true)->count());
        $this->assertSame('TN', State::query()->where('country_id', $india->id)->where('is_active', true)->value('code'));
        $this->assertTrue(City::query()->selectable()->where('name', 'Chennai')->exists());

        // An operator shows Kerala; a re-run must leave it shown.
        State::query()->where('code', 'KL')->update(['is_active' => true]);
        $this->seed(IndiaGeographySeeder::class);
        $this->assertTrue(State::query()->where('code', 'KL')->value('is_active'));
    }
}
