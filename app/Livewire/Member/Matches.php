<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Models\AppUser;
use App\Models\MatchRecord;
use App\Services\Members\Likers;
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
        // The exclusions live in the shared Likers service, so this page and
        // the mobile API cannot disagree about who a member is allowed to see.
        return app(Likers::class)->query($me)
            ->with('primaryPhoto')
            ->latest('last_active_at')
            ->limit(24)
            ->get();
    }
}
