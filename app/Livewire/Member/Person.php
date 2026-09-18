<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Enums\AccountStatus;
use App\Livewire\Member\Concerns\HandlesSafety;
use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Models\AppUser;
use App\Models\Block;
use App\Models\MatchRecord;
use App\Models\Swipe;
use App\Services\Members\SwipeRecorder;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Somebody else's full profile.
 *
 * Reachable from the deck, matches, messages and "liked you". A blocked person
 * (either direction) is a 404, not a 403: telling somebody they have been
 * blocked is itself information the blocker did not choose to share.
 */
class Person extends Component
{
    use HandlesSafety;
    use InteractsWithMember;

    #[Locked]
    public AppUser $person;

    public ?string $notice = null;

    public function mount(AppUser $person): void
    {
        $me = $this->member();

        abort_if($person->id === $me->id, 404);
        abort_unless($this->canSee($me, $person), 404);

        $this->person = $person;
    }

    public function render(): View
    {
        $me = $this->member()->loadMissing('interests');
        $person = $this->person->load(['photos', 'profile', 'city', 'interests']);

        $match = MatchRecord::query()->involving($me)->involving($person)->active()->with('conversation')->first();

        return view('livewire.member.person', [
            'me' => $me,
            'person' => $person,
            'match' => $match,
            'swiped' => Swipe::query()->where('app_user_id', $me->id)->where('target_app_user_id', $person->id)->value('action'),
            'categories' => $this->reportCategories(),
        ])->layout('components.layouts.member', ['title' => $person->display_name]);
    }

    public function swipe(string $action, SwipeRecorder $swipes): void
    {
        abort_unless(in_array($action, ['like', 'pass', 'superlike'], true), 422);
        abort_if($this->member()->account_status === AccountStatus::Pending, 403);

        try {
            $match = $swipes->record($this->member(), $this->person, $action, 'profile');
        } catch (ValidationException $e) {
            $this->notice = collect($e->errors())->flatten()->first();

            return;
        }

        if ($match !== null) {
            $this->toast("It's a match with {$this->person->display_name}!");
        } elseif ($action !== 'pass') {
            $this->toast($action === 'superlike' ? 'Superlike sent.' : 'Liked.');
        }
    }

    protected function afterSafetyAction(bool $blocked): void
    {
        if ($blocked) {
            $this->redirectRoute('member.discover', navigate: true);
        }
    }

    /**
     * Visible when neither has blocked the other and the account is in good
     * standing. Shadow-banned accounts stay visible to people already matched
     * with them — hiding them from an existing match would reveal the ban.
     */
    private function canSee(AppUser $me, AppUser $person): bool
    {
        $blocked = Block::query()
            ->where(fn ($q) => $q->where('app_user_id', $me->id)->where('blocked_app_user_id', $person->id))
            ->orWhere(fn ($q) => $q->where('app_user_id', $person->id)->where('blocked_app_user_id', $me->id))
            ->exists();

        if ($blocked) {
            return false;
        }

        if ($person->account_status === AccountStatus::Active) {
            return true;
        }

        return $person->account_status === AccountStatus::ShadowBanned
            && MatchRecord::query()->involving($me)->involving($person)->exists();
    }
}
