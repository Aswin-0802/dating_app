<?php

namespace App\Providers;

use App\Http\Middleware\EnsureMemberCanUseApp;
use App\Http\Middleware\EnsureStaffIsActive;
use App\Listeners\RecordEmailOutcome;
use App\Models\AppUser;
use App\Models\Setting;
use App\Support\Branding;
use App\Support\MailSettings;
use App\Support\Masters;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use Livewire\Livewire;

use function Livewire\on;
use function Livewire\store;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Setting::forgetMemo();
        Masters::forgetMemo();

        /*
         * Livewire action requests go to /livewire/update and only re-run route
         * middleware registered as persistent. Without this, a member suspended
         * while a tab was open could keep swiping and messaging from it until
         * they reloaded the page.
         *
         * The same applies to staff, which was the gap: every admin screen is
         * Livewire, so a suspended moderator kept working through the open tab
         * until a full page load — which the console never forces. Individual
         * enforcement actions were safe (they authorize() on their own), but
         * everything around them was not.
         */
        Livewire::addPersistentMiddleware([
            EnsureMemberCanUseApp::class,
            EnsureStaffIsActive::class,
        ]);

        // Outgoing mail follows System -> Mail, not only the test message.
        MailSettings::apply();

        // The delivery log describes what happened, not what was attempted.
        Event::listen(NotificationSent::class, [RecordEmailOutcome::class, 'sent']);
        Event::listen(NotificationFailed::class, [RecordEmailOutcome::class, 'failed']);

        /*
         * Feedback for Livewire actions.
         *
         * Components report outcomes with session()->flash('status'|'error').
         * A flash only renders on the next full page load, so after an action
         * that stays on the page the message never appeared at all. This turns
         * it into a toast straight away — unless the action is redirecting, in
         * which case the next page shows the flash as before.
         */
        on('dehydrate', function (Component $component): void {
            if (! Livewire::isLivewireRequest() || store($component)->get('redirect')) {
                return;
            }

            foreach (['status' => 'success', 'error' => 'error'] as $key => $type) {
                if (session()->has($key)) {
                    $component->dispatch('platform:toast', message: (string) session()->pull($key), type: $type);
                }
            }
        });

        /*
         * Password reset links point at the right audience's page, and the
         * email carries the product's name rather than the framework default.
         */
        ResetPassword::createUrlUsing(fn ($user, string $token): string => $user instanceof AppUser
            ? route('member.password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()])
            : route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]));

        ResetPassword::toMailUsing(function ($user, string $token): MailMessage {
            $url = call_user_func(ResetPassword::$createUrlCallback, $user, $token);
            $name = Branding::name();

            return (new MailMessage)
                ->subject("Reset your {$name} password")
                ->greeting('Hello')
                ->line("We received a request to reset the password for your {$name} account.")
                ->action('Choose a new password', $url)
                ->line('This link expires in 60 minutes.')
                ->line('If you did not ask for this, you can ignore this email and your password will stay the same.')
                ->salutation("The {$name} team");
        });
    }
}
