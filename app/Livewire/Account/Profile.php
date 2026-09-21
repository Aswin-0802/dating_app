<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The signed-in staff member's own account: details and password.
 */
class Profile extends Component
{
    public string $name = '';

    public string $jobTitle = '';

    public string $currentPassword = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = (string) $user->name;
        $this->jobTitle = (string) $user->job_title;
    }

    public function render(): View
    {
        return view('livewire.account.profile', ['user' => auth()->user()])
            ->layout('components.layouts.admin', [
                'title' => 'My profile',
                'breadcrumbs' => [
                    ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                    ['label' => 'My profile'],
                ],
            ]);
    }

    public function saveDetails(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[\pL\pM\s\'.-]+$/u'],
            'jobTitle' => ['nullable', 'string', 'max:80'],
        ], ['name.regex' => 'Use letters, spaces, apostrophes and hyphens only.']);

        auth()->user()->forceFill(['name' => trim($this->name), 'job_title' => trim($this->jobTitle) ?: null])->save();

        $this->dispatch('platform:toast', message: 'Details saved.', type: 'success');
    }

    public function changePassword(ActivityLogger $logger): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:currentPassword', Password::min(10)->letters()->mixedCase()->numbers()],
        ], [
            'password.different' => 'Choose a password you have not used here before.',
        ], [
            'currentPassword' => 'current password',
            'password' => 'new password',
        ]);

        $user = auth()->user();

        if (! Hash::check($this->currentPassword, $user->password)) {
            throw ValidationException::withMessages(['currentPassword' => 'That is not your current password.']);
        }

        $user->forceFill(['password' => $this->password])->save();

        // Sign out every other browser that was using the old password.
        Auth::logoutOtherDevices($this->password);

        $logger->log(module: 'auth', action: 'password_changed', subject: $user, description: "{$user->name} changed their password");

        $this->reset('currentPassword', 'password', 'password_confirmation');
        $this->dispatch('platform:toast', message: 'Password changed. Other sessions have been signed out.', type: 'success');
    }
}
