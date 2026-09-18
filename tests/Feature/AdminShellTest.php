<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Navigation;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['status' => 'active'])->syncRoles([$role]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_dashboard_renders_for_signed_in_staff(): void
    {
        $this->actingAs($this->staff(Role::MODERATOR))
            ->get('/admin')
            ->assertOk()
            ->assertSee('Overview');
    }

    public function test_component_gallery_renders_every_primitive(): void
    {
        $this->actingAs($this->staff(Role::SUPER_ADMIN))
            ->get('/admin/_kitchen-sink')
            ->assertOk()
            ->assertSee('Component gallery')
            // A representative primitive from each family, so a fatal in any one
            // of them fails here rather than in a browser later.
            ->assertSee('Shadow banned')
            ->assertSee('Critical')
            ->assertSee('Open drawer')
            ->assertSee('Queue is clear');
    }

    public function test_sidebar_hides_areas_the_user_cannot_reach(): void
    {
        // A moderator has no Roles, Staff or Settings permission, so those nav
        // entries must be absent entirely — not merely disabled.
        $response = $this->actingAs($this->staff(Role::MODERATOR))->get('/admin');

        $response->assertOk();

        // Asserted against Navigation rather than rendered HTML, so this keeps
        // testing the permission filter as modules are added and the exact set
        // of rendered links changes.
        $moderatorLabels = $this->navigationLabels();

        // Dashboard is the one area every role reaches.
        $this->assertContains('Dashboard', $moderatorLabels);

        // A moderator administers nothing. These must be absent entirely rather
        // than rendered-and-disabled: a restricted area a user can see is an
        // invitation to ask why they cannot use it.
        $this->assertNotContains('Staff', $moderatorLabels);
        $this->assertNotContains('Roles', $moderatorLabels);
        $this->assertNotContains('Settings', $moderatorLabels);

        // A moderator DOES see Appeals — they can read one, they just cannot
        // decide it. Viewing and deciding are separate permissions on purpose,
        // so that the "never the original decider" rule always has somebody
        // else to route to.
        $this->assertContains('Appeals', $moderatorLabels);

        // An analyst is read-only over aggregates and reaches neither.
        $this->actingAs($this->staff(Role::ANALYST));
        $analystLabels = $this->navigationLabels();

        $this->assertNotContains('Appeals', $analystLabels);
        $this->assertNotContains('Conversations', $analystLabels);
    }

    public function test_super_admin_sees_at_least_as_much_as_any_other_role(): void
    {
        $this->actingAs($this->staff(Role::MODERATOR));
        $moderatorLabels = $this->navigationLabels();

        $this->actingAs($this->staff(Role::SUPER_ADMIN));
        $superAdminLabels = $this->navigationLabels();

        $this->assertEmpty(
            array_diff($moderatorLabels, $superAdminLabels),
            'Super Admin must see every area a moderator can see.',
        );
    }

    /** @return array<int, string> */
    private function navigationLabels(): array
    {
        return collect(Navigation::sections())
            ->flatMap(fn (array $section): array => array_column($section['items'], 'label'))
            ->all();
    }

    public function test_suspended_staff_are_signed_out_mid_session(): void
    {
        $user = $this->staff(Role::MODERATOR);

        $this->actingAs($user)->get('/admin')->assertOk();

        $user->update(['status' => 'suspended']);

        $this->actingAs($user)->get('/admin')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_theme_cookie_is_applied_server_side(): void
    {
        // The dark class must be on the server-rendered html element; resolving
        // it client-side is what causes the flash on hard refresh.
        $this->actingAs($this->staff(Role::MODERATOR))
            ->withUnencryptedCookie('veyra_theme', 'dark')
            ->get('/admin')
            ->assertOk()
            ->assertSee('<html lang="en" class="dark"', false);
    }

    public function test_sidebar_collapse_state_is_applied_server_side(): void
    {
        $this->actingAs($this->staff(Role::MODERATOR))
            ->withUnencryptedCookie('veyra_sidebar', 'collapsed')
            ->get('/admin')
            ->assertOk()
            ->assertSee("veyraShell('collapsed')", false);
    }
}
