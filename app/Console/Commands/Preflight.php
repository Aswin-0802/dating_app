<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\User;
use App\Services\Store\StoreDrivers;
use App\Support\PushSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * What must be true before this instance faces the public.
 *
 * A checklist in a README is a checklist somebody skips at 2am. This fails
 * loudly instead, so it can sit in a deploy pipeline and stop the release.
 *
 * Warnings are things to think about; failures are things that would expose
 * real people. Only failures change the exit code.
 */
class Preflight extends Command
{
    protected $signature = 'platform:preflight';

    protected $description = 'Check this instance is safe to expose to the public';

    /** @var array<int, string> */
    private array $failures = [];

    /** @var array<int, string> */
    private array $warnings = [];

    public function handle(): int
    {
        $this->checkDebugAndEnvironment();
        $this->checkDemoAccounts();
        $this->checkAppKeyAndUrl();
        $this->checkQueueWorkerIsNeeded();
        $this->checkPushAndMail();
        $this->checkStores();

        foreach ($this->warnings as $warning) {
            $this->warn('  ! '.$warning);
        }

        foreach ($this->failures as $failure) {
            $this->error('  x '.$failure);
        }

        if ($this->failures !== []) {
            $this->newLine();
            $this->error(count($this->failures).' blocking problem(s). This instance is not ready to go live.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($this->warnings === []
            ? 'Ready to go live.'
            : 'No blocking problems. Read the warnings above before you launch.');

        return self::SUCCESS;
    }

    private function checkDebugAndEnvironment(): void
    {
        if (config('app.debug')) {
            // Debug pages print stack traces, environment variables and
            // database credentials to whoever triggered the error.
            $this->failures[] = 'APP_DEBUG is true. Set it to false.';
        }

        if (config('app.env') !== 'production') {
            $this->warnings[] = 'APP_ENV is "'.config('app.env').'", not "production".';
        }
    }

    /**
     * The seeded staff accounts all share one published password, and they are
     * the fastest way into a live console.
     */
    private function checkDemoAccounts(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $demo = User::query()
            ->where('email', 'like', '%@demo.test')
            ->get(['id', 'email', 'password']);

        if ($demo->isEmpty()) {
            return;
        }

        $usable = $demo->filter(fn (User $user): bool => Hash::check('password', $user->password));

        if ($usable->isNotEmpty()) {
            $this->failures[] = $usable->count().' demo staff account(s) still accept the password "password", including '
                .$usable->first()->email.'. Delete them or change their passwords.';

            return;
        }

        $this->warnings[] = $demo->count().' demo staff account(s) remain (their passwords have been changed).';
    }

    private function checkAppKeyAndUrl(): void
    {
        if (blank(config('app.key'))) {
            $this->failures[] = 'APP_KEY is empty. Run php artisan key:generate.';
        }

        $url = (string) config('app.url');

        if ($url === '' || str_contains($url, 'localhost') || str_contains($url, '127.0.0.1')) {
            $this->failures[] = 'APP_URL is "'.$url.'". Links in emails and push notifications use it.';

            return;
        }

        if (! str_starts_with($url, 'https://')) {
            $this->warnings[] = 'APP_URL is not https. Session cookies and the HSTS header depend on it.';
        }
    }

    /**
     * Queued work is invisible when it fails: nothing appears in the UI, the
     * member simply never hears from us.
     */
    private function checkQueueWorkerIsNeeded(): void
    {
        if (config('queue.default') === 'sync') {
            $this->warnings[] = 'QUEUE_CONNECTION is "sync", so email and push are sent inside web requests.';

            return;
        }

        $this->warnings[] = 'QUEUE_CONNECTION is "'.config('queue.default')
            .'". Email and push will not be delivered at all unless a worker is running '
            .'(php artisan queue:work), alongside the scheduler cron.';
    }

    /**
     * In-app purchases: a store switched on with credentials the store
     * refuses means every purchase in the app fails at the receipt step,
     * after the member has been charged. Found here, not by a member.
     */
    private function checkStores(): void
    {
        $stores = PaymentGateway::query()->where('kind', 'store')->where('is_active', true)->get();

        if ($stores->isEmpty()) {
            $this->warnings[] = 'No app store is switched on, so Premium cannot be bought in the mobile app.';

            return;
        }

        $drivers = app(StoreDrivers::class);

        foreach ($stores as $gateway) {
            $result = $drivers->for($gateway->slug)?->check() ?? ['ok' => false, 'error' => 'no driver'];

            if (! ($result['ok'] ?? false)) {
                $this->failures[] = "{$gateway->name} in-app purchases are on but the store refused the credentials: ".($result['error'] ?? 'unknown error');
            }

            $mapped = Plan::query()->where('is_active', true)
                ->where(fn ($q) => $q->whereNotNull(Plan::storeColumn($gateway->slug, 'monthly'))->orWhereNotNull(Plan::storeColumn($gateway->slug, 'yearly')))
                ->exists();

            if (! $mapped) {
                $this->warnings[] = "{$gateway->name} is on but no plan on sale has a {$gateway->name} product id, so nothing can be bought in the app.";
            }
        }
    }

    private function checkPushAndMail(): void
    {
        if (config('mail.default') === 'log' || config('mail.default') === 'array') {
            $this->failures[] = 'Mail is set to "'.config('mail.default').'", so no email leaves this instance.';
        }

        if (! PushSettings::enabled()) {
            $this->warnings[] = 'Push notifications are off, so new matches and messages will not be announced.';
        }
    }
}
