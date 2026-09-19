<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings -> General -> Maintenance mode.
 *
 * Takes the website, the member app and the mobile API offline with a 503,
 * while the staff console and its sign-in keep working, so the team can keep
 * moderating and switch the setting back off.
 */
class RespectMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! veyra_setting('general.maintenance_mode', false)) {
            return $next($request);
        }

        // The console, its sign-in and staff sessions are never locked out.
        if ($request->is('admin', 'admin/*', 'up') || Auth::guard('web')->check()) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => 'We are carrying out scheduled maintenance. Please try again shortly.',
                'code' => 'maintenance',
            ], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '600']);
        }

        return response()->view('errors.503', [], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '600']);
    }
}
