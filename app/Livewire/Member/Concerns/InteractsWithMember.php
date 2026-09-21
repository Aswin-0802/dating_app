<?php

declare(strict_types=1);

namespace App\Livewire\Member\Concerns;

use App\Models\AppUser;
use Illuminate\Support\Facades\Auth;

trait InteractsWithMember
{
    /**
     * The signed-in member.
     *
     * Read from the `member` guard explicitly, never auth()->user(): that is
     * the staff guard, and a staff member browsing the website in the same
     * browser must never be mistaken for a member.
     */
    protected function member(): AppUser
    {
        $member = Auth::guard('member')->user();

        abort_unless($member instanceof AppUser, 401);

        return $member;
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('platform:toast', message: $message, type: $type);
    }
}
