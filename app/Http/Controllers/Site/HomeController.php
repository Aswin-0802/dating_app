<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Enums\AccountStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\MatchRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

class HomeController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        // A buyer running app-only can switch the marketing site off; the
        // root then goes straight to member sign-in.
        if (! platform_setting('website.enabled', true)) {
            return redirect()->route('member.login');
        }

        return view('site.home', [
            'stats' => $this->stats(),
        ]);
    }

    /**
     * Live numbers for the proof strip, cached for ten minutes.
     *
     * Only shown once they are big enough to be persuasive: "12 members" on a
     * fresh install undersells the product more than showing nothing.
     *
     * @return array<string, string>|null
     */
    private function stats(): ?array
    {
        return Cache::remember('site.home.stats', now()->addMinutes(10), function (): ?array {
            $members = AppUser::query()->where('account_status', AccountStatus::Active->value)->count();

            if ($members < 500) {
                return null;
            }

            $verified = AppUser::query()
                ->where('account_status', AccountStatus::Active->value)
                ->where('verification_status', VerificationStatus::Approved->value)
                ->count();

            return [
                'Members' => platform_compact_number($members),
                'Photo-verified' => platform_percent($verified / $members * 100, 0),
                'Matches made' => platform_compact_number(MatchRecord::query()->count()),
            ];
        });
    }
}
