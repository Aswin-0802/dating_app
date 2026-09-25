<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Livewire\Member\Auth\Register;
use App\Livewire\Member\Profile as ProfileScreen;
use App\Livewire\Settings\Locations;
use App\Models\AppUser;
use App\Models\City;
use App\Models\Country;
use App\Models\Preference;
use App\Models\Profile;
use App\Models\Role;
use App\Models\State;
use App\Models\User;
use App\Support\ProfileOptions;
use Database\Seeders\InterestSeeder;
use Database\Seeders\MasterSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Geographic restriction is a rule, not a dropdown filter.
 *
 * Every switch (country, state, city) is tried against every write path that
 * accepts a city_id, as a matrix. A fifth write path added later fails
 * test_every_city_write_path_is_in_the_matrix loudly, because it scans the
 * code for city_id validation sites and compares them to this list.
 */
class GeographicRestrictionTest extends TestCase
{
    use RefreshDatabase;

    /** Every place in the code that accepts a city_id from a member. */
    private const WRITE_PATHS = ['api_save', 'api_register', 'web_register', 'web_profile'];

    private const WRITE_PATH_FILES = [
        'Http/Controllers/Api/V1/AuthController.php',      // api_register
        'Http/Controllers/Api/V1/ProfileController.php',   // api_save
        'Livewire/Member/Auth/Register.php',               // web_register
        'Livewire/Member/Profile.php',                     // web_profile
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingSeeder::class, InterestSeeder::class, MasterSeeder::class]);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ---- the matrix ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function matrix(): array
    {
        $cases = [];

        foreach (['country', 'state', 'city'] as $switch) {
            foreach (self::WRITE_PATHS as $path) {
                $cases["{$switch} off x {$path}"] = [$switch, $path];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_a_hidden_place_is_refused_on_every_write_path(string $switch, string $path): void
    {
        [$country, $state, $city] = $this->place();

        match ($switch) {
            'country' => $country->update(['is_active' => false]),
            'state' => $state->update(['is_active' => false]),
            'city' => $city->update(['is_active' => false]),
        };

        $this->assertFalse($this->attempt($path, $city), "EXPECTED {$path} to refuse a city whose {$switch} is hidden.");
    }

    public function test_a_selectable_city_is_accepted_on_every_write_path(): void
    {
        [, , $city] = $this->place();

        foreach (self::WRITE_PATHS as $path) {
            $this->assertTrue($this->attempt($path, $city), "EXPECTED {$path} to accept a selectable city.");
        }
    }

    public function test_a_member_whose_city_was_hidden_since_can_still_save_without_moving(): void
    {
        [, , $city] = $this->place();
        [, , $elsewhere] = $this->place('Otherland', 'OL', 'Elsewhere');
        $member = $this->member(['city_id' => $city->id, 'country_id' => $city->country_id]);

        $city->update(['is_active' => false]);
        $elsewhere->update(['is_active' => false]);

        // Keeping the (now hidden) city: fine on both clients.
        Sanctum::actingAs($member, ['profile:read', 'profile:write']);
        $this->patchJson('/api/v1/me', ['city_id' => $city->id, 'display_name' => 'Still here'])->assertOk();

        $this->actingAs($member, 'member');
        $this->profileSave($member, $city)->assertHasNoErrors();
        $this->assertSame($city->id, $member->fresh()->city_id);

        // Moving to another hidden city: still refused.
        $this->patchJson('/api/v1/me', ['city_id' => $elsewhere->id])->assertStatus(422)->assertJsonValidationErrors('city_id');
        $this->profileSave($member, $elsewhere)->assertHasErrors('city_id');
    }

    public function test_hidden_places_leave_every_listing(): void
    {
        [$country, $state, $city] = $this->place();
        [, , $visible] = $this->place('Otherland', 'OL', 'Elsewhere');

        $city->update(['is_active' => false]);

        $this->getJson('/api/v1/cities')->assertOk()->assertJsonMissing(['name' => $city->name])->assertJsonFragment(['name' => $visible->name]);
        $this->assertNotContains($city->name, collect(Livewire::test(Register::class)->instance()->cities())->flatten()->all());

        $city->update(['is_active' => true]);
        $state->update(['is_active' => false]);
        $this->getJson('/api/v1/cities')->assertOk()->assertJsonMissing(['name' => $city->name]);

        $state->update(['is_active' => true]);
        $country->update(['is_active' => false]);
        $this->getJson('/api/v1/cities')->assertOk()->assertJsonMissing(['name' => $city->name]);
    }

    public function test_every_city_write_path_is_in_the_matrix(): void
    {
        $sites = collect(File::allFiles(app_path()))
            ->filter(fn ($file): bool => (bool) preg_match("/'city_id'\s*=>\s*\[/", $file->getContents()))
            ->map(fn ($file): string => str_replace('\\', '/', $file->getRelativePathname()))
            ->sort()->values()->all();

        $this->assertSame(
            self::WRITE_PATH_FILES,
            $sites,
            'A file validates city_id that this matrix does not cover. Add it to WRITE_PATHS / WRITE_PATH_FILES and make it use App\Rules\SelectableCity.',
        );

        foreach ($sites as $site) {
            $this->assertStringContainsString('SelectableCity', File::get(app_path($site)), "{$site} validates city_id without App\\Rules\\SelectableCity.");
        }
    }

    // ---- the console side (1d) -------------------------------------------------------

    public function test_staff_can_hide_one_city_and_are_warned_when_nothing_is_left(): void
    {
        [$country, , $city] = $this->place();
        $admin = User::factory()->create(['status' => 'active'])->syncRoles([Role::ADMIN]);

        Livewire::actingAs($admin)
            ->test(Locations::class)
            ->call('selectCountry', $country->id)
            ->call('toggleCityActive', $city->id)
            ->assertSee('No city can be chosen right now.');   // the last selectable city just went

        $this->assertFalse($city->fresh()->is_active);
        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'city_hidden']);

        Livewire::actingAs($admin)
            ->test(Locations::class)
            ->call('selectCountry', $country->id)
            ->call('toggleCityActive', $city->id)
            ->assertDontSee('No city can be chosen right now.');

        $this->assertTrue($city->fresh()->is_active);
        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'city_shown']);
    }

    // ---- helpers ----------------------------------------------------------------------

    /** @return array{0: Country, 1: State, 2: City} */
    private function place(string $countryName = 'Testland', string $iso2 = 'TL', string $cityName = 'Townsville'): array
    {
        $country = Country::query()->firstOrCreate(['iso2' => $iso2], ['name' => $countryName, 'dial_code' => '+999', 'is_active' => true]);
        $state = State::query()->firstOrCreate(['country_id' => $country->id, 'name' => "{$countryName} Region"], ['code' => 'RG', 'is_active' => true]);
        $city = City::query()->create([
            'country_id' => $country->id,
            'state_id' => $state->id,
            'name' => $cityName,
            'latitude' => 10.0,
            'longitude' => 20.0,
            'timezone' => 'UTC',
            'is_focus' => false,
            'is_active' => true,
        ]);

        return [$country, $state, $city];
    }

    private function member(array $attributes = []): AppUser
    {
        $member = AppUser::factory()->create($attributes + ['account_status' => AccountStatus::Active, 'city_id' => null]);

        Profile::query()->create(['app_user_id' => $member->id]);
        Preference::query()->create(['app_user_id' => $member->id, 'interested_in' => ['woman', 'man'], 'age_min' => 18, 'age_max' => 99]);

        return $member->fresh();
    }

    /** Try to write the city through one path. True when accepted, false when refused on city_id. */
    private function attempt(string $path, City $city): bool
    {
        return match ($path) {
            'api_save' => (function () use ($city): bool {
                $this->app['auth']->forgetGuards();
                Sanctum::actingAs($this->member(), ['profile:read', 'profile:write']);
                $response = $this->patchJson('/api/v1/me', ['city_id' => $city->id]);

                return $response->status() === 200 && ! ($response->status() === 422 && isset($response->json('errors')['city_id']));
            })(),
            'api_register' => (function () use ($city): bool {
                $this->app['auth']->forgetGuards();
                $response = $this->postJson('/api/v1/auth/register', [
                    'display_name' => 'Nia', 'email' => uniqid('nia').'@example.test', 'password' => 'secret123',
                    'birthdate' => now()->subYears(25)->toDateString(), 'gender' => 'woman', 'interested_in' => ['man'],
                    'city_id' => $city->id,
                ]);

                if ($response->status() === 201) {
                    return true;
                }

                $this->assertSame(422, $response->status());
                $this->assertArrayHasKey('city_id', $response->json('errors') ?? [], 'EXPECTED the refusal to be about city_id.');

                return false;
            })(),
            'web_register' => (function () use ($city): bool {
                $this->app['auth']->forgetGuards();
                $component = Livewire::test(Register::class)
                    ->set('display_name', 'Maya')
                    ->set('email', uniqid('maya').'@example.test')
                    ->set('password', 'secret123')
                    ->set('birthdate', now()->subYears(29)->toDateString())
                    ->set('gender', 'woman')
                    ->set('interested_in', ['man'])
                    ->set('city_id', $city->id)
                    ->set('terms', true)
                    ->call('register');

                $errors = $component->errors();

                return ! $errors->has('city_id') && $errors->isEmpty();
            })(),
            'web_profile' => (function () use ($city): bool {
                $this->app['auth']->forgetGuards();
                $member = $this->member();
                $this->actingAs($member, 'member');

                return $this->profileSave($member, $city)->errors()->isEmpty();
            })(),
        };
    }

    private function profileSave(AppUser $member, City $city)
    {
        $first = fn (string $group): string => (string) array_key_first(ProfileOptions::options($group));

        return Livewire::actingAs($member, 'member')
            ->test(ProfileScreen::class)
            ->set('display_name', $member->display_name)
            ->set('relationship_goal', $first('relationship_goal'))
            ->set('drinking', $first('drinking'))
            ->set('smoking', $first('smoking'))
            ->set('children', $first('children'))
            ->set('city_id', $city->id)
            ->call('saveAbout');
    }
}
