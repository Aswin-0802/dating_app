<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Appeal;
use App\Models\Ban;
use App\Models\ReportCase;
use App\Models\Role;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class VeyraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerSuperAdmin();
        $this->shareNavigationCounts();
        $this->registerRateLimiters();
    }

    /**
     * API rate limits.
     *
     * Limits are read from settings so trust & safety can tighten them during
     * an incident without waiting for a deploy — which is exactly when you most
     * want to tighten them.
     *
     * Accounts under 24 hours old get half of the swipe and message allowances.
     * It is cheap anti-spam, and hitting the ceiling also feeds the velocity
     * risk factors, so a bot throttling itself still leaves a trail.
     */
    private function registerRateLimiters(): void
    {
        $limiter = function (string $key, int $default, int $minutes = 1, bool $halveForNewAccounts = false) {
            RateLimiter::for($key, function (Request $request) use ($key, $default, $minutes, $halveForNewAccounts) {
                $attempts = (int) veyra_setting("api.rate_limit_{$key}", $default);
                $member = $request->user();

                if ($halveForNewAccounts
                    && veyra_setting('api.new_account_throttle', true)
                    && $member?->created_at?->gt(now()->subHours((int) config('veyra.api.new_account_hours', 24)))) {
                    $attempts = max(1, intdiv($attempts, 2));
                }

                return Limit::perMinutes($minutes, $attempts)
                    ->by($member?->id ?? $request->ip());
            });
        };

        $limiter('api', 90);
        $limiter('deck', 60);
        $limiter('swipe', 120, 1, halveForNewAccounts: true);
        $limiter('message', 30, 1, halveForNewAccounts: true);
        $limiter('report', 10, 60);
        $limiter('verification', 3, 1440);

        // Keyed on IP AND email together: credential stuffing across many
        // accounts from one address would otherwise get a fresh budget each time.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(8)->by($request->ip()),
            Limit::perMinute(5)->by($request->ip().'|'.sha1((string) $request->input('email'))),
        ]);
    }

    /**
     * Super Admin bypasses every gate.
     *
     * Belt and braces: the role is also granted every permission row by the
     * seeder. Either mechanism alone would work; together they mean a permission
     * added later can never lock the only account that could fix it.
     */
    private function registerSuperAdmin(): void
    {
        Gate::before(fn (User $user): ?bool => $user->hasRole(Role::SUPER_ADMIN) ? true : null);
    }

    /**
     * Queue counts for the sidebar badges.
     *
     * Bound lazily, so a page that never renders the sidebar pays nothing, and
     * guarded against missing tables so early migrations do not break boot.
     */
    private function shareNavigationCounts(): void
    {
        $this->app->bind('veyra.nav-counts', function (): array {
            if (! Schema::hasTable('report_cases')) {
                return [];
            }

            return cache()->remember('veyra.nav-counts', now()->addSeconds(30), function (): array {
                return [
                    'cases_open' => ReportCase::query()->open()->count(),
                    'verifications_pending' => Verification::query()->inQueue('standard')->open()->count(),
                    'appeals_open' => Appeal::query()->open()->count(),
                    'shadow_reviews_due' => Ban::query()->reviewDue()->count(),
                ];
            });
        });
    }
}
