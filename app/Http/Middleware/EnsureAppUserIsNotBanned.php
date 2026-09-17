<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks restricted accounts from the API.
 *
 * The important case is the one this middleware does NOT block: a shadow-banned
 * member passes through completely untouched. They keep their token, their deck
 * and their matches; the restriction lives in how their profile is ranked, not
 * in whether the API answers them. An API that behaves differently for a
 * shadow-banned account makes the shadow ban detectable, at which point it is
 * just a ban with extra steps.
 */
class EnsureAppUserIsNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        $member = $request->user();

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

        $status = $member->account_status;

        if ($status === AccountStatus::Banned || $status === AccountStatus::Suspended) {
            $ban = $member->activeBan;

            return response()->json([
                'message' => $ban?->user_facing_message
                    ?? 'Your account is currently restricted.',
                'code' => 'account_restricted',
                'restriction' => [
                    'type' => $ban?->type?->value,
                    'expires_at' => $ban?->expires_at?->toIso8601String(),
                    // DSA Art. 17: the member is told the specific clause, not
                    // a vague category.
                    'policy_clause' => $ban?->moderationAction?->policy_clause,
                    'appealable' => true,
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        if ($status === AccountStatus::Deactivated) {
            return response()->json([
                'message' => 'This account has been deactivated.',
                'code' => 'account_deactivated',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
