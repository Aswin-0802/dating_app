<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Enums\AccountStatus;
use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Models\AppUser;
use App\Models\MatchRecord;
use App\Services\Members\MatchActions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class Matches extends Component
{
    use InteractsWithMember;

    public function render(): View
    {
        $me = $this->member();

        $matches = MatchRecord::query()
            ->involving($me)
            ->active()
            ->with(['userOne.primaryPhoto', 'userTwo.primaryPhoto', 'conversation'])
            ->orderByDesc('matched_at')
            ->get();

        [$new, $talking] = $matches->partition(fn (MatchRecord $m): bool => (int) $m->messages_count === 0);

        return view('livewire.member.matches', [
            'me' => $me,
            'new' => $new->values(),
            'talking' => $talking->sortByDesc('last_message_at')->values(),
            'likers' => $this->likers($me),
        ])->layout('components.layouts.member', ['title' => 'Matches', 'wide' => true]);
    }

    public function unmatch(string $matchUuid, MatchActions $matches): void
    {
        $me = $this->member();
        $match = MatchRecord::query()->where('uuid', $matchUuid)->involving($me)->firstOrFail();

        $matches->end($me, $match);

        $this->toast('Unmatched.');
    }

    /**
     * People who have liked this member and are still waiting for an answer.
     *
     * Everybody sees how many; Premium sees who. Blocks in either direction and
     * restricted accounts are excluded, so the list never surfaces somebody the
     * member has chosen not to see.
     *
     * @return Collection<int, AppUser>
     */
    private function likers(AppUser $me): Collection
    {
        return AppUser::query()
            ->whereIn('id', fn ($q) => $q->select('app_user_id')
                ->from('swipes')
                ->where('target_app_user_id', $me->id)
                ->whereIn('action', ['like', 'superlike'])
                ->where('is_match', false))
            ->whereNotIn('id', fn ($q) => $q->select('target_app_user_id')->from('swipes')->where('app_user_id', $me->id))
            ->whereNotIn('id', fn ($q) => $q->select('blocked_app_user_id')->from('blocks')->where('app_user_id', $me->id))
            ->whereNotIn('id', fn ($q) => $q->select('app_user_id')->from('blocks')->where('blocked_app_user_id', $me->id))
            ->where('account_status', AccountStatus::Active->value)
            ->with('primaryPhoto')
            ->latest('last_active_at')
            ->limit(24)
            ->get();
    }
}
