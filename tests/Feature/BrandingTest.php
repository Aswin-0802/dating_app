<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Settings\Branding;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingSeeder::class]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['status' => 'active'])->syncRoles([$role]);
    }

    /**
     * Regression: every setting key is dotted, and binding `values.brand.name`
     * made Livewire write to a nested array that save() never read — so no
     * setting in any group could be changed.
     */
    public function test_a_dotted_setting_key_saves_from_the_settings_screen(): void
    {
        $setting = Setting::query()->where('key', 'general.min_age')->firstOrFail();

        Livewire::actingAs($this->staff(Role::SUPER_ADMIN))
            ->test(SettingsIndex::class, ['group' => 'general'])
            ->set("values.{$setting->id}", 21)
            ->call('save');

        $this->assertSame('21', (string) $setting->fresh()->value);
        $this->assertSame(21, veyra_setting('general.min_age'));
    }

    public function test_the_product_name_and_colour_apply_across_the_admin(): void
    {
        $admin = $this->staff(Role::SUPER_ADMIN);

        Livewire::actingAs($admin)
            ->test(Branding::class)
            ->set('form.brand__name', 'Amora')
            ->set('form.brand__primary_color', '#2563eb')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.settings.branding'));

        $this->assertSame('Amora', veyra_setting('brand.name'));

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Amora')
            ->assertSee('--primary:#2563eb', escape: false);
    }

    public function test_the_default_colour_emits_no_override(): void
    {
        // The default palette is hand-tuned oklch; regenerating it from its own
        // hex would make it slightly worse.
        $this->actingAs($this->staff(Role::SUPER_ADMIN))
            ->get('/admin')
            ->assertDontSee('id="brand-tokens"', escape: false);
    }

    public function test_an_uploaded_logo_is_stored_and_shown(): void
    {
        Storage::fake('public');
        $admin = $this->staff(Role::SUPER_ADMIN);

        Livewire::actingAs($admin)
            ->test(Branding::class)
            ->set('uploads.admin_logo', UploadedFile::fake()->image('logo.png', 200, 200))
            ->call('save')
            ->assertHasNoErrors();

        $path = (string) veyra_setting('brand.admin_logo');

        $this->assertStringStartsWith('branding/', $path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)->get('/admin')->assertSee('storage/'.$path, escape: false);
    }

    public function test_an_svg_logo_is_refused(): void
    {
        // An SVG served from our own origin is a document that can run script.
        Storage::fake('public');

        Livewire::actingAs($this->staff(Role::SUPER_ADMIN))
            ->test(Branding::class)
            ->set('uploads.logo', UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'))
            ->call('save')
            ->assertHasErrors('uploads.logo');

        $this->assertSame('', (string) veyra_setting('brand.logo'));
    }

    public function test_an_invalid_colour_is_refused(): void
    {
        Livewire::actingAs($this->staff(Role::SUPER_ADMIN))
            ->test(Branding::class)
            ->set('form.brand__primary_color', 'red; } body { display:none')
            ->call('save')
            ->assertHasErrors('form.brand__primary_color');
    }

    public function test_staff_without_the_permission_cannot_change_branding(): void
    {
        Livewire::actingAs($this->staff(Role::ANALYST))
            ->test(Branding::class)
            ->set('form.brand__name', 'Hijacked')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Veyra', veyra_setting('brand.name'));
    }
}
