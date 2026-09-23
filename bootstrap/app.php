<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAppUserIsNotBanned;
use App\Http\Middleware\EnsureMemberCanUseApp;
use App\Http\Middleware\EnsureStaffIsActive;
use App\Http\Middleware\RespectMaintenanceMode;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
         * Payment gateways post from their own servers, so there is no session
         * and no CSRF token to send. Each webhook is verified by signature in
         * its driver instead, which is stronger than a session token would be.
         */
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        /*
         * These two are written by JavaScript (document.cookie) so the browser
         * can persist a preference the moment it changes, and read by Blade on
         * the next request so the first painted byte is already correct.
         *
         * They therefore cannot be encrypted: EncryptCookies would fail to
         * decrypt them, null them out, and the theme would flash on every hard
         * refresh. Neither carries anything sensitive.
         */
        // Settings -> General -> Maintenance mode; the console stays reachable.
        $middleware->web(append: [RespectMaintenanceMode::class]);
        $middleware->api(append: [RespectMaintenanceMode::class]);

        // Clickjacking, MIME sniffing, referrer leakage. On both groups: the
        // API answers browsers too, through the member website's fetches.
        $middleware->web(append: [SecurityHeaders::class]);
        $middleware->api(append: [SecurityHeaders::class]);

        $middleware->encryptCookies(except: [
            'platform_theme',
            'platform_sidebar',
        ]);

        $middleware->alias([
            // spatie
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,

            // platform
            'staff.active' => EnsureStaffIsActive::class,
            'appuser.active' => EnsureAppUserIsNotBanned::class,
            'member.active' => EnsureMemberCanUseApp::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        /*
         * Two populations, two sign-in pages. Staff land on /admin/login and
         * members on /login, whichever area they were trying to reach.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request): string => $request->is('admin', 'admin/*')
                ? route('login')
                : route('member.login'),
        );

        $middleware->redirectUsersTo(
            fn (Request $request): string => $request->is('admin', 'admin/*')
                ? route('admin.dashboard')
                : route('member.discover'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * One error shape for the whole API.
         *
         * Without this a validation failure on a stateful route returns an HTML
         * 419 and a mobile client reports "something went wrong" with nothing to
         * act on. Every failure here carries a machine-readable `code` so the
         * client can branch on it.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            [$status, $code, $message] = match (true) {
                $e instanceof ValidationException => [422, 'validation_failed', $e->getMessage()],
                $e instanceof AuthenticationException => [401, 'unauthenticated', 'Sign in to continue.'],
                $e instanceof AuthorizationException => [403, 'forbidden', $e->getMessage() ?: 'Not permitted.'],
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'not_found', 'Not found.'],
                $e instanceof ThrottleRequestsException => [429, 'rate_limited', 'Too many requests. Slow down.'],

                /*
                 * Every abort() in a controller arrives here as an HttpException.
                 * Without this arm they all fall through to the default and a
                 * deliberate 403 is reported to the client as a server error —
                 * which reads as "the app is broken" rather than "you may not
                 * do that".
                 */
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(),
                    match ($e->getStatusCode()) {
                        401 => 'unauthenticated',
                        403 => 'forbidden',
                        404 => 'not_found',
                        429 => 'rate_limited',
                        default => 'request_failed',
                    },
                    $e->getMessage() ?: 'Request failed.',
                ],

                default => [500, 'server_error', 'Something went wrong.'],
            };

            $payload = ['message' => $message, 'code' => $code];

            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            }

            // Debug detail only where it cannot reach a real client.
            if ($status === 500 && config('app.debug')) {
                $payload['debug'] = [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                ];
            }

            $response = response()->json($payload, $status);

            if ($e instanceof ThrottleRequestsException) {
                $response->headers->add($e->getHeaders());
            }

            return $response;
        });
    })->create();
