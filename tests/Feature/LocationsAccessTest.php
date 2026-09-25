<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Settings\Locations;
use App\Models\City;
use App\Models\Country;
use App\Models\Role;
use App\Models\State;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who may change the geographic footprint, and that every change is audited.
 *
 * Settings -> Locations needs edit_general_settings on top of `settings`,
 * matching Branding and Mail: Super Admin and Admin, and nobody else — in
 * particular not the T&S Lead, who holds `settings` for the safety screens.
 */
class LocationsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingSeeder::class]);
    }

    /** @return array<string, array{0: string, 1: int}> every seeded role and the status it must get */
    public static function roles(): array
    {
        return [
            'Super Admin' => [Role::SUPER_ADMIN, 200],
            'Admin' => [Role::ADMIN, 200],
            'T&S Lead' => [Role::TS_LEAD, 403],
            'Senior Moderator' => [Role::SENIOR_MODERATOR, 403],
            'Moderator' => [Role::MODERATOR, 403],
            'Support' => [Role::SUPPORT, 403],
            'Analyst' => [Role::ANALYST, 403],
        ];
    }

    #[DataProvider('roles')]
    public function test_only_super_admin_and_admin_may_open_locations(string $role, int $expected): void
    {
        $this->actingAs($this->staff($role));

        $this->assertSame($expected, $this->get('/admin/masters/locations')->status(), "EXPECTED {$role} to get {$expected} on Locations.");
    }

    public function test_the_locations_link_is_shown_only_to_those_who_may_open_it(): void
    {
        $this->actingAs($this->staff(Role::TS_LEAD));
        $this->get('/admin/masters/interests')->assertOk()->assertDontSee('/admin/masters/locations');

        $this->actingAs($this->staff(Role::ADMIN));
        $this->get('/admin/masters/interests')->assertOk()->assertSee('/admin/masters/locations');
    }

    public function test_every_geography_change_writes_an_audit_row(): void
    {
        $admin = $this->staff(Role::ADMIN);

        // Country: created, renamed, hidden.
        $component = Livewire::actingAs($admin)->test(Locations::class)
            ->call('newCountry')->set('countryName', 'Testland')->set('countryIso2', 'tl')->set('countryDialCode', '+999')->call('saveCountry')->assertHasNoErrors();
        $country = Country::query()->where('iso2', 'TL')->firstOrFail();
        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'country_created', 'subject_id' => $country->id]);

        $component->call('editCountry', $country->id)->set('countryName', 'Testlandia')->set('countryActive', false)->call('saveCountry')->assertHasNoErrors();
        $this->assertSame('Testlandia', $country->fresh()->name);
        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'country_updated', 'subject_id' => $country->id]);

        // State: created, renamed, toggled.
        $component->call('selectCountry', $country->id)->call('newState')->set('stateName', 'Region One')->set('stateCode', 'R1')->call('saveState')->assertHasNoErrors();
        $state = State::query()->where('name', 'Region One')->firstOrFail();
        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'state_created', 'subject_id' => $state->id]);

        $component->call('editState', $state->id)->set('stateName', 'Region Uno')->call('saveState')->assertHasNoErrors();
        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'state_updated', 'subject_id' => $state->id]);

        $component->call('toggleStateActive', $state->id);
        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'state_hidden', 'subject_id' => $state->id]);

        // City: created, renamed, toggled.
        $component->call('newCity')->set('cityName', 'Townsville')->set('cityStateId', $state->id)->set('cityLatitude', '10')->set('cityLongitude', '20')->call('saveCity')->assertHasNoErrors();
        $city = City::query()->where('name', 'Townsville')->firstOrFail();
        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'city_created', 'subject_id' => $city->id]);

        $component->call('editCity', $city->id)->set('cityName', 'Townsvale')->call('saveCity')->assertHasNoErrors();
        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'city_updated', 'subject_id' => $city->id]);

        $component->call('toggleCityActive', $city->id);
        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'city_hidden', 'subject_id' => $city->id]);
        $component->call('toggleCityActive', $city->id);
        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'city_shown', 'subject_id' => $city->id]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['status' => 'active'])->syncRoles([$role]);
    }
}
