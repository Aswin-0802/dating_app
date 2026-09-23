<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The go-live check has to fail for the right reasons, and pass once they are
 * fixed. A check that cannot fail is worse than no check, because it is
 * believed.
 */
class PreflightTest extends TestCase
{
    use RefreshDatabase;

    private function makeItReady(): void
    {
        config([
            'app.debug' => false,
            'app.env' => 'production',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://example.com',
            'mail.default' => 'smtp',
        ]);
    }

    public function test_it_passes_once_everything_is_in_order(): void
    {
        $this->makeItReady();

        $this->artisan('platform:preflight')->assertSuccessful();
    }

    public function test_debug_mode_blocks_going_live(): void
    {
        $this->makeItReady();
        config(['app.debug' => true]);

        // Debug pages print stack traces, environment variables and database
        // credentials to whoever triggered the error.
        $this->artisan('platform:preflight')
            ->expectsOutputToContain('APP_DEBUG is true')
            ->assertFailed();
    }

    public function test_a_demo_account_that_still_takes_the_published_password_blocks_going_live(): void
    {
        $this->makeItReady();

        User::factory()->create([
            'email' => 'admin@demo.test',
            'password' => 'password',
        ]);

        $this->artisan('platform:preflight')
            ->expectsOutputToContain('demo staff account')
            ->assertFailed();
    }

    public function test_a_demo_account_with_a_changed_password_is_only_a_warning(): void
    {
        $this->makeItReady();

        User::factory()->create([
            'email' => 'admin@demo.test',
            'password' => 'something-else-entirely',
        ]);

        $this->artisan('platform:preflight')->assertSuccessful();
    }

    public function test_a_localhost_url_and_a_swallowed_mailer_block_going_live(): void
    {
        $this->makeItReady();
        config(['app.url' => 'http://localhost:8000', 'mail.default' => 'log']);

        $this->artisan('platform:preflight')
            ->expectsOutputToContain('APP_URL')
            ->expectsOutputToContain('no email leaves this instance')
            ->assertFailed();
    }
}
