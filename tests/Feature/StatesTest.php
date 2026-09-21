<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Member\Auth\Register;
use App\Livewire\Settings\Locations;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\AppUser;
use App\Models\City;
use App\Models\Country;
use App\Models\Role;
use App\Models\State;
use App\Models\User;
use Database\Seeders\GeographySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\StateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionSeeder::class, RoleSeeder::class, SettingSeeder::class,
            GeographySeeder::class, StateSeeder::class,
        ]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['status' => 'active'])->syncRoles([$role]);
    }

    private function india(): Country
    {
        return Country::query()->where('iso2', 'IN')->firstOrFail();
    }

    public function test_seeded_cities_are_placed_in_their_state(): void
    {
        $mumbai = City::query()->where('name', 'Mumbai')->firstOrFail();

        $this->assertSame('Maharashtra', $mumbai->state?->name);
        $this->assertSame('MH', $mumbai->state?->code);
        $this->assertSame(36, State::query()->where('country_id', $this->india()->id)->count());
    }

    public function test_a_country_without_states_still_works(): void
    {
        $singapore = City::query()->where('name', 'Singapore')->firstOrFail();

        $this->assertNull($singapore->state_id);
    }

    public function test_staff_add_edit_and_hide_a_state(): void
    {
        $admin = $this->staff(Role::ADMIN);
        $india = $this->india();

        Livewire::actingAs($admin)
            ->test(Locations::class)
            ->call('selectCountry', $india->id)
            ->call('newState')
            ->set('stateName', 'Tamil Nadu')
            ->call('saveState')
            ->assertHasErrors(['stateName' => 'unique']);

        $component = Livewire::actingAs($admin)
            ->test(Locations::class)
            ->call('selectCountry', $india->id)
            ->call('newState')
            ->set('stateName', 'Test Region')
            ->set('stateCode', 'TR2')
            ->call('saveState')
            ->assertHasNoErrors();

        $state = State::query()->where('name', 'Test Region')->firstOrFail();
        $this->assertTrue($state->is_active);

        $component->call('toggleStateActive', $state->id);
        $this->assertFalse($state->fresh()->is_active);

        $component->call('deleteState', $state->id);
        $this->assertModelMissing($state);
    }

    public function test_a_state_with_cities_cannot_be_deleted(): void
    {
        $maharashtra = State::query()->where('name', 'Maharashtra')->firstOrFail();

        Livewire::actingAs($this->staff(Role::ADMIN))
            ->test(Locations::class)
            ->call('selectCountry', $this->india()->id)
            ->call('deleteState', $maharashtra->id);

        $this->assertModelExists($maharashtra);
    }

    public function test_a_new_city_must_name_its_state_where_states_exist(): void
    {
        $admin = $this->staff(Role::ADMIN);
        $india = $this->india();

        Livewire::actingAs($admin)
            ->test(Locations::class)
            ->call('selectCountry', $india->id)
            ->call('newCity')
            ->set('cityName', 'Coimbatore')
            ->set('cityTimezone', 'Asia/Kolkata')
            ->set('cityStateId', null)
            ->call('saveCity')
            ->assertHasErrors('cityStateId');

        $tamilNadu = State::query()->where('name', 'Tamil Nadu')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(Locations::class)
            ->call('selectCountry', $india->id)
            ->call('newCity')
            ->set('cityName', 'Coimbatore')
            ->set('cityTimezone', 'Asia/Kolkata')
            ->set('cityStateId', $tamilNadu->id)
            ->call('saveCity')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('cities', ['name' => 'Coimbatore', 'state_id' => $tamilNadu->id]);
    }

    public function test_members_pick_a_city_grouped_by_state(): void
    {
        $groups = Livewire::test(Register::class)->instance()->cities();

        $this->assertArrayHasKey('India · Maharashtra', $groups);
        $this->assertContains('Mumbai', $groups['India · Maharashtra']);

        // A country with no states keeps its plain heading.
        $this->assertArrayHasKey('Singapore', $groups);
    }

    public function test_a_city_in_a_hidden_state_is_not_offered_at_sign_up(): void
    {
        State::query()->where('name', 'Maharashtra')->update(['is_active' => false]);

        $groups = Livewire::test(Register::class)->instance()->cities();

        $this->assertArrayNotHasKey('India · Maharashtra', $groups);
        $this->assertNotContains('Mumbai', collect($groups)->flatten()->all());
    }

    public function test_the_admin_can_filter_members_by_state(): void
    {
        $mumbai = City::query()->where('name', 'Mumbai')->firstOrFail();
        $london = City::query()->where('name', 'London')->firstOrFail();

        $inMumbai = AppUser::factory()->create(['city_id' => $mumbai->id, 'display_name' => 'Asha Reddy']);
        AppUser::factory()->create(['city_id' => $london->id, 'display_name' => 'Tom Baker']);

        Livewire::actingAs($this->staff(Role::ADMIN))
            ->test(UsersIndex::class)
            ->set('state', (string) $mumbai->state_id)
            ->assertSee($inMumbai->display_name)
            ->assertDontSee('Tom Baker');
    }

    public function test_the_api_lists_states_and_cities_within_them(): void
    {
        $this->getJson(route('api.v1.states', ['country' => 'IN']))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Tamil Nadu', 'code' => 'TN']);

        $maharashtra = State::query()->where('name', 'Maharashtra')->firstOrFail();

        $this->getJson(route('api.v1.cities', ['state' => $maharashtra->id]))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Mumbai', 'state' => 'Maharashtra']);
    }
}
