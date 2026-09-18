<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Models\AppUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The website counterpart of EnsureAppUserIsNotBanned.
 *
 * A suspended or banned member is sent to a page that states the restriction,
 * the policy clause and the end date, and lets them appeal (DSA Art. 17 and 20)
 * — rather than being bounced to a sign-in page with no explanation.
 *
 * A shadow-banned member passes straight through, exactly as on the API. A
 * website that behaves differently for a shadow-banned account makes the
 * restriction detectable, at which point it is just a ban with extra steps.
 */
class EnsureMemberCanUseApp
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var AppUser|null $member */
        $member = Auth::guard('member')->user();

        if ($member === null) {
            return $next($request);
        }

        // Expired restrictions clear themselves rather than waiting for the
        // scheduler, so a member is never locked out past their end date.
        if ($member->suspended_until !== null && $member->suspended_until->isPast()) {
            $member->forceFill([
                'account_status' => AccountStatus::Active,
                'suspended_until' => null,
                'active_ban_id' => null,
            ])->save();
        }

        if (in_array($member->account_status, [AccountStatus::Banned, AccountStatus::Suspended], true)) {
            return $request->routeIs('member.restricted')
                ? $next($request)
                : redirect()->route('member.restricted');
        }

        if ($member->account_status === AccountStatus::Deactivated) {
            Auth::guard('member')->logout();

            return redirect()->route('member.login')
                ->with('status', 'That account has been deactivated. Contact support to reopen it.');
        }

        // A restricted page left open after the restriction lifts goes home.
        if ($request->routeIs('member.restricted')) {
            return redirect()->route('member.discover');
        }

        // Five-minute resolution is plenty for "active now" and saves a write on
        // every page view.
        if ($member->last_active_at === null || $member->last_active_at->lt(now()->subMinutes(5))) {
            $member->forceFill(['last_active_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
