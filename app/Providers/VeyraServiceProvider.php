<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
                    'cases_open' => \App\Models\ReportCase::query()->open()->count(),
                    'verifications_pending' => \App\Models\Verification::query()->inQueue('standard')->open()->count(),
                    'appeals_open' => \App\Models\Appeal::query()->open()->count(),
                    'shadow_reviews_due' => \App\Models\Ban::query()->reviewDue()->count(),
                ];
            });
        });
    }
}
