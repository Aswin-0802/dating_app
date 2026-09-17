<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces out a staff member whose account is suspended mid-session.
 *
 * Without this, revoking access only takes effect at their next login — which
 * is exactly the wrong behaviour when you are revoking it because of something
 * they just did.
 */
class EnsureStaffIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user !== null && ! $user->isActive()) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with('error', 'Your account is no longer active. Contact an administrator.');
        }

        return $next($request);
    }
}
