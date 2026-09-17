<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * These two are written by JavaScript (document.cookie) so the browser
         * can persist a preference the moment it changes, and read by Blade on
         * the next request so the first painted byte is already correct.
         *
         * They therefore cannot be encrypted: EncryptCookies would fail to
         * decrypt them, null them out, and the theme would flash on every hard
         * refresh. Neither carries anything sensitive.
         */
        $middleware->encryptCookies(except: [
            'veyra_theme',
            'veyra_sidebar',
        ]);

        $middleware->alias([
            // spatie
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,

            // veyra
            'staff.active' => \App\Http\Middleware\EnsureStaffIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
