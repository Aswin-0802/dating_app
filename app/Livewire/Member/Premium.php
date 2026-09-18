<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Services\Members\SwipeRecorder;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Plans and the member's current one.
 *
 * There is no card checkout on the website yet: the payment gateways under
 * System hold credentials, but nothing charges through them. Rather than a
 * button that pretends to take money, upgrades point to the store apps (when
 * their links are set in Branding) or to support.
 */
class Premium extends Component
{
    use InteractsWithMember;

    public function render(SwipeRecorder $swipes): View
    {
        $me = $this->member();

        return view('livewire.member.premium', [
            'me' => $me,
            'likesLeft' => $swipes->likesLeftToday($me),
        ])->layout('components.layouts.member', ['title' => 'Premium']);
    }
}
