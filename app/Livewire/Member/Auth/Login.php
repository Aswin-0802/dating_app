<?php

declare(strict_types=1);

namespace App\Livewire\Member\Auth;

use App\Models\AppUser;
use App\Services\Members\MemberAccounts;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = true;

    public function render(): View
    {
        return view('livewire.member.auth.login')
            ->layout('components.layouts.member-auth', ['title' => 'Sign in']);
    }

    public function login(MemberAccounts $accounts): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Keyed on the address AND the IP: per-IP alone lets one attacker spray
        // many accounts, per-address alone lets anybody lock a member out.
        $key = 'member-login:'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $member = AppUser::query()->where('email', Str::lower($this->email))->first();

        if (! Auth::guard('member')->attempt(['email' => Str::lower($this->email), 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key, 60);

            if ($member !== null) {
                $accounts->recordLogin($member, request()->ip(), succeeded: false);
            }

            throw ValidationException::withMessages(['email' => 'That email and password do not match.']);
        }

        RateLimiter::clear($key);
        session()->regenerate();

        /** @var AppUser $signedIn */
        $signedIn = Auth::guard('member')->user();
        $accounts->recordLogin($signedIn, request()->ip(), succeeded: true);

        $this->redirectIntended(route('member.discover'), navigate: false);
    }
}
