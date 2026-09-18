<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Enums\AppealStatus;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Moderator scorecard.
 *
 * Volume is the least interesting column here. The one that matters is the
 * overturn rate: a moderator deciding fifty cases a day with a third of them
 * reversed on appeal is producing work, not outcomes. Reading volume without it
 * rewards exactly the wrong behaviour.
 */
class Performance extends Component
{
    #[Url(except: '30')]
    public string $days = '30';

    public function render(): View
    {
        return view('livewire.staff.performance', [
            'rows' => $this->scorecard(),
            'days' => (int) $this->days,
        ])->layout('components.layouts.admin', [
            'title' => 'Moderator performance',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Staff', 'href' => route('admin.staff.index')],
                ['label' => 'Performance'],
            ],
        ]);
    }

    /** @return Collection<int, object> */
    private function scorecard(): Collection
    {
        $since = now()->subDays((int) $this->days);

        $decisions = DB::table('moderation_actions')
            ->where('created_at', '>=', $since)
            ->whereNotNull('actor_id')
            ->selectRaw('actor_id, COUNT(*) as decisions')
            ->groupBy('actor_id')
            ->pluck('decisions', 'actor_id')
            ->all();

        // Appeals against decisions this person made, and how many were reversed.
        $appeals = DB::table('appeals')
            ->whereNotNull('original_decider_id')
            ->whereIn('status', [
                AppealStatus::Upheld->value,
                AppealStatus::Overturned->value,
                AppealStatus::PartiallyOverturned->value,
            ])
            ->selectRaw(sprintf(
                "original_decider_id, COUNT(*) as decided, SUM(status IN ('%s','%s')) as overturned",
                AppealStatus::Overturned->value,
                AppealStatus::PartiallyOverturned->value,
            ))
            ->groupBy('original_decider_id')
            ->get()
            ->keyBy('original_decider_id');

        $verifications = DB::table('verifications')
            ->where('reviewed_at', '>=', $since)
            ->whereNotNull('reviewed_by')
            ->selectRaw('reviewed_by, COUNT(*) as reviewed, AVG(TIMESTAMPDIFF(MINUTE, submitted_at, reviewed_at)) as avg_minutes')
            ->groupBy('reviewed_by')
            ->get()
            ->keyBy('reviewed_by');

        $reveals = DB::table('message_access_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('user_id, COUNT(*) as reveals')
            ->groupBy('user_id')
            ->pluck('reveals', 'user_id')
            ->all();

        return DB::table('users')
            ->whereNull('deleted_at')
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get()
            ->map(function (object $user) use ($decisions, $appeals, $verifications, $reveals): object {
                $appeal = $appeals->get($user->id);
                $verification = $verifications->get($user->id);

                $user->decisions = (int) ($decisions[$user->id] ?? 0);
                $user->appeals_decided = (int) ($appeal->decided ?? 0);
                $user->overturned = (int) ($appeal->overturned ?? 0);
                $user->overturn_rate = $user->appeals_decided > 0
                    ? round($user->overturned / $user->appeals_decided * 100, 1)
                    : null;
                $user->verifications = (int) ($verification->reviewed ?? 0);
                $user->median_minutes = $verification?->avg_minutes !== null
                    ? (int) round((float) $verification->avg_minutes)
                    : null;
                $user->reveals = (int) ($reveals[$user->id] ?? 0);

                return $user;
            })
            ->filter(fn (object $user): bool => $user->decisions > 0 || $user->verifications > 0)
            ->sortByDesc('decisions')
            ->values();
    }
}
