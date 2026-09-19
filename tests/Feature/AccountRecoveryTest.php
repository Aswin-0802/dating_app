<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Currency;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingSeeder::class]);
    }

    public function test_a_member_can_reset_a_forgotten_password(): void
    {
        Notification::fake();
        $member = AppUser::factory()->create(['email' => 'robin@example.test']);

        $this->post(route('member.password.email'), ['email' => 'robin@example.test'])
            ->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($member, ResetPassword::class, function (ResetPassword $n) use (&$token, $member): bool {
            $token = $n->token;

            // The link goes to the member page, not the staff console.
            return str_contains($n->toMail($member)->actionUrl, '/reset-password/')
                && ! str_contains($n->toMail($member)->actionUrl, '/admin/');
        });

        $this->post(route('member.password.update'), [
            'token' => $token,
            'email' => 'robin@example.test',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertRedirect(route('member.login'));

        $this->assertTrue(Hash::check('newpass123', $member->fresh()->password));
    }

    public function test_the_reset_form_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();

        $this->post(route('member.password.email'), ['email' => 'nobody@example.test'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'If an account exists'));

        Notification::assertNothingSent();
    }

    public function test_staff_can_reset_their_password_through_the_console_link(): void
    {
        Notification::fake();
        $staff = User::factory()->create(['email' => 'lead@example.test', 'status' => 'active'])->syncRoles([Role::MODERATOR]);

        $this->post(route('password.email'), ['email' => 'lead@example.test'])->assertSessionHas('status');

        Notification::assertSentTo($staff, ResetPassword::class, fn (ResetPassword $n): bool => str_contains($n->toMail($staff)->actionUrl, '/admin/reset-password/'));
    }

    public function test_an_invalid_token_is_refused(): void
    {
        AppUser::factory()->create(['email' => 'robin@example.test']);

        $this->post(route('member.password.update'), [
            'token' => 'forged',
            'email' => 'robin@example.test',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertSessionHasErrors('email');
    }

    public function test_prices_follow_the_chosen_currency(): void
    {
        Setting::put('billing.currency', 'INR');
        Setting::put('website.plus_price', '999');

        $this->assertSame('INR', Currency::code());
        $this->assertSame('₹1,23,456.00', Currency::format(123456));
        $this->get('/')->assertSee('₹999.00');

        Setting::put('billing.currency', 'USD');
        $this->get('/')->assertSee('$999.00');
    }
}
