<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Services\Members\SwipeRecorder;
use App\Services\Payments\Checkout;
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

    public function render(SwipeRecorder $swipes, Checkout $checkout): View
    {
        $me = $this->member();

        return view('livewire.member.premium', [
            'me' => $me,
            'likesLeft' => $swipes->likesLeftToday($me),
            // Empty when no gateway is switched on, and the page falls back to
            // the store links or support, exactly as before checkout existed.
            'gateways' => $checkout->availableGateways(),
            'orders' => $me->orders()->latest()->limit(5)->get(),
        ])->layout('components.layouts.member', ['title' => 'Premium']);
    }
}
