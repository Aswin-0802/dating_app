<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Enums\AccountStatus;
use App\Livewire\Member\Concerns\HandlesSafety;
use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Models\AppUser;
use App\Services\Members\DiscoveryDeck;
use App\Services\Members\ProfileCompletion;
use App\Services\Members\SwipeRecorder;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One person at a time, like the phone apps.
 *
 * The deck is fetched in batches of uuids held in component state, so a swipe
 * is one small request and never re-runs the discovery query.
 */
class Discover extends Component
{
    use HandlesSafety;
    use InteractsWithMember;

    /**
     * uuids still to be shown.
     *
     * Locked: the deck query is what enforces blocks, shadow bans and
     * preferences, and an editable queue would let a crafted request swipe on
     * anybody at all.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $queue = [];

    /** Set when the last like completed a match, to show the celebration. */
    #[Locked]
    public ?string $matchedWith = null;

    #[Locked]
    public ?string $matchConversation = null;

    /** True once the member's city ran out and the deck widened beyond it. */
    #[Locked]
    public bool $widened = false;

    public ?string $limitMessage = null;

    public function mount(DiscoveryDeck $deck): void
    {
        $this->refill($deck);
    }

    public function render(SwipeRecorder $swipes, ProfileCompletion $completion): View
    {
        $me = $this->member()->loadMissing(['interests', 'primaryPhoto', 'city']);
        $isPending = $me->account_status === AccountStatus::Pending;

        $current = ! $isPending && $this->queue !== []
            ? AppUser::query()
                ->where('uuid', $this->queue[0])
                ->with(['photos', 'profile', 'city', 'interests'])
                ->first()
            : null;

        return view('livewire.member.discover', [
            'me' => $me,
            'current' => $current,
            'isPending' => $isPending,
            'checklist' => $isPending ? $completion->checklist($me) : [],
            'likesLeft' => $swipes->likesLeftToday($me),
            'matched' => $this->matchedWith
                ? AppUser::query()->where('uuid', $this->matchedWith)->with('primaryPhoto')->first()
                : null,
            'categories' => $this->reportCategories(),
        ])->layout('components.layouts.member', ['title' => 'Discover']);
    }

    /** @param  'like'|'pass'|'superlike'  $action */
    public function swipe(string $action, SwipeRecorder $swipes, DiscoveryDeck $deck): void
    {
        if (! in_array($action, ['like', 'pass', 'superlike'], true) || $this->queue === []) {
            return;
        }

        $member = $this->member();
        abort_if($member->account_status === AccountStatus::Pending, 403);

        $target = AppUser::query()->where('uuid', $this->queue[0])->first();

        if ($target === null) {
            $this->advance($deck);

            return;
        }

        try {
            $match = $swipes->record($member, $target, $action);
        } catch (ValidationException $e) {
            // The daily like budget — shown in place rather than as an error.
            $this->limitMessage = collect($e->errors())->flatten()->first();

            return;
        }

        $this->limitMessage = null;
        $this->advance($deck);

        if ($match !== null) {
            $this->matchedWith = $target->uuid;
            $this->matchConversation = $match->conversation?->uuid;
        }
    }

    public function closeMatch(): void
    {
        $this->matchedWith = null;
        $this->matchConversation = null;
    }

    protected function afterSafetyAction(bool $blocked): void
    {
        $this->advance(app(DiscoveryDeck::class));
    }

    private function advance(DiscoveryDeck $deck): void
    {
        array_shift($this->queue);

        if (count($this->queue) < 3) {
            $this->refill($deck);
        }
    }

    private function refill(DiscoveryDeck $deck): void
    {
        [$people, $widened] = $deck->withFallback($this->member(), 20);

        $this->widened = $widened;
        $this->queue = array_values(array_unique([...$this->queue, ...$people->pluck('uuid')->all()]));
    }
}
