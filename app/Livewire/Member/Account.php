<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Enums\AccountStatus;
use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Models\AppUser;
use App\Models\PushToken;
use App\Services\Members\AccountDeletion;
use App\Services\Members\SafetyActions;
use App\Services\Sms\PhoneVerification;
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

    public string $deletePassword = '';

    // ---- phone verification ----------------------------------------------------

    public string $phone = '';

    public string $phoneCode = '';

    /** Set once a code has been texted, so the form shows the code box. */
    public bool $codeSent = false;

    public function mount(): void
    {
        $this->email = $this->member()->email;
        $this->phone = (string) $this->member()->phone;
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
            'phoneVerificationAvailable' => app(PhoneVerification::class)->available(),
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

    /**
     * Delete the account — not the same thing as deactivating it.
     *
     * Deactivation is reversible and keeps everything. Deletion anonymises
     * the account and cannot be undone. Both go through the same services
     * the mobile API uses.
     */
    public function deleteAccount(AccountDeletion $deletion): void
    {
        $this->validate(['deletePassword' => ['required', 'string']], [], ['deletePassword' => 'password']);

        try {
            $deletion->delete($this->member(), $this->deletePassword);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'deletePassword' => $e->errors()['password'][0] ?? 'That password is not right.',
            ]);
        }

        Auth::guard('member')->logout();
        session()->invalidate();
        session()->regenerateToken();
        session()->flash('status', 'Your account has been deleted.');

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

    // ---- phone verification ----------------------------------------------------

    /**
     * Text a code to the number in the form.
     *
     * Validation errors from the service (number taken, texting failed) come
     * back on the field, so the member is never told a text is on its way
     * when none was sent.
     */
    public function sendPhoneCode(PhoneVerification $verification): void
    {
        $this->validate([
            'phone' => ['required', 'string', 'min:8', 'max:20', 'regex:/^\+?[0-9 ()-]{8,20}$/'],
        ], [
            'phone.regex' => 'Use the number in full, with its country code, such as +91 98765 43210.',
        ]);

        $verification->start($this->member(), $this->phone);

        $this->codeSent = true;
        $this->phoneCode = '';
        $this->toast('We have texted you a code.');
    }

    public function confirmPhoneCode(PhoneVerification $verification): void
    {
        $this->validate(['phoneCode' => ['required', 'digits:6']], [
            'phoneCode.digits' => 'The code is six digits.',
        ]);

        try {
            $verification->confirm($this->member(), $this->phoneCode);
        } catch (ValidationException $e) {
            // The service talks about a "code"; the form shows "phoneCode".
            // Without this the member would see nothing at all go wrong.
            throw ValidationException::withMessages([
                'phoneCode' => $e->validator->errors()->first(),
            ]);
        }

        $this->codeSent = false;
        $this->phoneCode = '';
        $this->toast('Your phone number is verified.');
    }
}
