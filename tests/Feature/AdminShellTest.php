<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Users\Index as UsersIndex;
use App\Models\AppUser;
use App\Models\Role;
use App\Models\User;
use App\Support\Navigation;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    /**
     * The console is Livewire end to end, and Livewire update requests only
     * re-run route middleware that is registered as persistent. Covering the
     * full page load alone left a suspended moderator working through an open
     * tab, because nothing in the console forces a reload.
     */
    public function test_suspended_staff_cannot_keep_acting_through_livewire(): void
    {
        $user = $this->staff(Role::MODERATOR);

        $this->actingAs($user);

        /*
         * Driven over real HTTP rather than through Livewire::test(). The test
         * harness never resolves the original route, so it does not re-run
         * persistent middleware at all — it would pass against the broken code.
         */
        $html = $this->get('/admin/users')->assertOk()->content();

        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $matches));
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES);

        $user->update(['status' => 'suspended']);

        $response = $this->postJson('/livewire/update', [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]],
        ]);

        $this->assertContains(
            $response->getStatusCode(),
            [302, 401, 403, 419],
            'EXPECTED a suspended moderator to be refused on a Livewire update, got '.$response->getStatusCode().'.',
        );

        $this->assertGuest();
    }

    public function test_theme_cookie_is_applied_server_side(): void
    {
        // The dark class must be on the server-rendered html element; resolving
        // it client-side is what causes the flash on hard refresh.
        $this->actingAs($this->staff(Role::MODERATOR))
            ->withUnencryptedCookie('platform_theme', 'dark')
            ->get('/admin')
            ->assertOk()
            ->assertSee('<html lang="en" class="dark"', false);
    }

    public function test_sidebar_collapse_state_is_applied_server_side(): void
    {
        $this->actingAs($this->staff(Role::MODERATOR))
            ->withUnencryptedCookie('platform_sidebar', 'collapsed')
            ->get('/admin')
            ->assertOk()
            ->assertSee("platformShell('collapsed')", false);
    }

    /**
     * The console is framable without these, which is what makes clickjacking
     * worth doing here: its buttons suspend and ban people, and a moderator
     * cannot see an invisible iframe they are clicking through.
     */
    public function test_responses_carry_security_headers(): void
    {
        // Guest requests first: signing in makes /admin/login redirect.
        $responses = [
            $this->get('/admin/login'),
            $this->getJson('/api/v1/config'),
            $this->actingAs($this->staff(Role::MODERATOR))->get('/admin'),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            $response->assertHeader('X-Frame-Options', 'DENY');
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

            $this->assertStringContainsString(
                "frame-ancestors 'none'",
                (string) $response->headers->get('Content-Security-Policy'),
            );
        }

        // HSTS is withheld over plain HTTP: sending it from a development
        // server teaches the browser to refuse http://localhost afterwards.
        $this->assertFalse($responses[0]->headers->has('Strict-Transport-Security'));
    }

    /**
     * Every admin table composes WithDataTable, so a stale bookmark or a
     * hand-edited query string must degrade to a sane page rather than a
     * stack trace — the trait exists to make these URLs shareable.
     */
    public function test_malformed_table_urls_degrade_instead_of_failing(): void
    {
        $this->actingAs($this->staff(Role::ADMIN));

        foreach ([
            '/admin/users?page=999999',
            '/admin/users?perPage=abc',
            '/admin/users?perPage=100000',
            '/admin/users?perPage=-5',
            '/admin/users?sortField=created_at&sortDirection=DROP',
            '/admin/audit?page=999999',
        ] as $url) {
            $this->get($url)->assertOk();
        }

        // Not merely non-fatal: the values are replaced with safe ones.
        Livewire::actingAs($this->staff(Role::ADMIN))
            ->withQueryParams(['perPage' => 100000, 'sortDirection' => 'DROP', 'density' => 'huge'])
            ->test(UsersIndex::class)
            ->assertSet('perPage', 25)
            ->assertSet('sortDirection', 'desc')
            ->assertSet('density', 'comfortable');
    }

    /**
     * Analyst and Moderator both hold 'users' and neither holds
     * 'view_user_pii'. The member list used to print every email address
     * regardless, while the detail page and the CSV export gated the same
     * field correctly.
     */
    public function test_the_member_list_hides_contact_details_from_roles_denied_pii(): void
    {
        AppUser::factory()->create(['email' => 'private.member@example.test']);

        foreach ([Role::ANALYST, Role::MODERATOR] as $role) {
            $this->actingAs($this->staff($role))
                ->get('/admin/users')
                ->assertOk()
                ->assertDontSee('private.member@example.test');
        }

        // Support answers real members' emails, so they must still see it.
        $this->actingAs($this->staff(Role::SUPPORT))
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('private.member@example.test');
    }

    public function test_searching_by_email_is_refused_to_roles_denied_pii(): void
    {
        $member = AppUser::factory()->create(['email' => 'findme@example.test']);

        // Typing an address and reading the result is itself a disclosure:
        // a hit proves that person is a member here.
        Livewire::actingAs($this->staff(Role::ANALYST))
            ->test(UsersIndex::class)
            ->set('search', 'findme@example.test')
            ->assertDontSee($member->display_name);

        Livewire::actingAs($this->staff(Role::SUPPORT))
            ->test(UsersIndex::class)
            ->set('search', 'findme@example.test')
            ->assertSee($member->display_name);
    }
}
