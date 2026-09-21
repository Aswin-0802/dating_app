<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Enums\AccountStatus;
use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Models\AppUser;
use App\Models\PushToken;
use App\Services\Members\SafetyActions;
use App\Support\PushSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Account extends Component
{
    use InteractsWithMember;

    public string $email = '';

    public string $emailPassword = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public string $deactivatePassword = '';

    public function mount(): void
    {
        $this->email = $this->member()->email;
    }

    public function render(): View
    {
        $me = $this->member();

        $blocked = AppUser::query()
            ->whereIn('id', fn ($q) => $q->select('blocked_app_user_id')->from('blocks')->where('app_user_id', $me->id))
            ->with('primaryPhoto')
            ->orderBy('display_name')
            ->get();

        return view('livewire.member.account', [
            'me' => $me,
            'blocked' => $blocked,
            'pushEnabled' => PushSettings::webEnabled(),
            'pushConfig' => PushSettings::webConfig(),
            'vapidKey' => PushSettings::vapidKey(),
            'devices' => PushToken::query()->where('app_user_id', $me->id)->latest('last_used_at')->get(),
        ])->layout('components.layouts.member', ['title' => 'Account']);
    }

    public function updateEmail(): void
    {
        $me = $this->member();

        $this->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('app_users', 'email')->ignore($me->id)],
            'emailPassword' => ['required', 'string'],
        ], [], ['emailPassword' => 'password']);

        $this->ensurePassword($this->emailPassword, 'emailPassword');

        $me->forceFill(['email' => strtolower($this->email)])->save();
        $this->emailPassword = '';
        $this->toast('Email updated.');
    }

    public function updatePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [], ['newPassword' => 'new password', 'currentPassword' => 'current password']);

        $this->ensurePassword($this->currentPassword, 'currentPassword');

        $me = $this->member();
        $me->forceFill(['password' => $this->newPassword])->save();

        // Signed-in phones hold API tokens that the old password issued. A
        // password change is often a response to somebody else having it.
        $me->tokens()->delete();

        $this->reset('currentPassword', 'newPassword', 'newPassword_confirmation');
        $this->toast('Password changed. Other devices have been signed out.');
    }

    public function unblock(string $uuid, SafetyActions $safety): void
    {
        $safety->unblock($this->member(), AppUser::query()->where('uuid', $uuid)->firstOrFail());
        $this->toast('Unblocked.');
    }

    public function deactivate(): void
    {
        $this->validate(['deactivatePassword' => ['required', 'string']], [], ['deactivatePassword' => 'password']);
        $this->ensurePassword($this->deactivatePassword, 'deactivatePassword');

        $me = $this->member();
        $me->forceFill(['account_status' => AccountStatus::Deactivated])->save();
        $me->tokens()->delete();

        Auth::guard('member')->logout();
        session()->regenerateToken();
        session()->flash('status', 'Your account has been deactivated. Contact support if you want it back.');

        $this->redirectRoute('home');
    }

    private function ensurePassword(string $password, string $field): void
    {
        if (! Hash::check($password, $this->member()->password)) {
            throw ValidationException::withMessages([$field => 'That password is not right.']);
        }
    }

    /**
     * Stop notifications going to one device.
     *
     * Deleting the token is the whole of it — the browser keeps its permission,
     * but nothing on our side knows where to send any more.
     */
    public function forgetDevice(int $id): void
    {
        PushToken::query()
            ->where('app_user_id', $this->member()->id)
            ->whereKey($id)
            ->delete();

        $this->toast('Notifications turned off for that device.');
    }
}
