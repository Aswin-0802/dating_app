<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\VerificationSelfieController;
use App\Livewire\Account;
use App\Livewire\Appeals;
use App\Livewire\Audit;
use App\Livewire\Cases;
use App\Livewire\Conversations;
use App\Livewire\Dashboard;
use App\Livewire\Enforcement;
use App\Livewire\Matches;
use App\Livewire\Notifications;
use App\Livewire\Roles;
use App\Livewire\Settings;
use App\Livewire\Staff;
use App\Livewire\System;
use App\Livewire\Users;
use App\Livewire\Verifications;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin console
|--------------------------------------------------------------------------
|
| Prefixed with /admin and named admin.* by bootstrap/app.php.
|
| Every route carries the permission it needs. App\Support\Navigation filters
| the sidebar by the same permissions, so an area a user cannot reach is not
| rendered rather than rendered-and-refused.
|
*/

// `auth:web` rather than bare `auth`: the console must only ever accept a staff
// session, whatever the default guard happens to be for the request.
// `auth.session` is what makes "sign out other sessions" on a password change
// take effect: it rejects sessions created with the old password.
Route::middleware(['auth:web', 'auth.session', 'staff.active'])->group(function (): void {

    Route::get('/', Dashboard\Overview::class)
        ->middleware('permission:dashboard')
        ->name('dashboard');

    Route::get('profile', Account\Profile::class)->name('profile');

    /*
    |----------------------------------------------------------------------
    | Users & profiles
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:users')->prefix('users')->name('users.')->group(function (): void {
        Route::get('/', Users\Index::class)->name('index');
        Route::get('{appUser:uuid}', Users\Show::class)->name('show');
    });

    /*
    |----------------------------------------------------------------------
    | Photo & identity verification
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:verifications')
        ->prefix('verifications')
        ->name('verifications.')
        ->group(function (): void {
            Route::get('/', Verifications\Queue::class)->name('index');

            // The restricted queue 404s rather than 403s without its own
            // permission, so its existence is not advertised to staff who
            // cannot open it.
            Route::get('restricted', Verifications\Queue::class)
                ->defaults('queue', 'restricted_minor')
                ->name('restricted');

            Route::get('{verification:uuid}', Verifications\Review::class)->name('review');

            Route::get('{verification:uuid}/selfie', VerificationSelfieController::class)
                ->middleware('signed')
                ->name('selfie');
        });

    /*
    |----------------------------------------------------------------------
    | Matches
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:matches')->prefix('matches')->name('matches.')->group(function (): void {
        Route::get('/', Matches\Index::class)->name('index');
    });

    /*
    |----------------------------------------------------------------------
    | Conversations
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:conversations')
        ->prefix('conversations')
        ->name('conversations.')
        ->group(function (): void {
            Route::get('/', Conversations\Index::class)->name('index');
            Route::get('{conversation:uuid}', Conversations\Show::class)->name('show');
        });

    /*
    |----------------------------------------------------------------------
    | Reports & moderation
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:cases')->prefix('cases')->name('cases.')->group(function (): void {
        Route::get('/', Cases\Index::class)->name('index');
        Route::get('{reportCase:case_number}', Cases\Show::class)->name('show');
    });

    /*
    |----------------------------------------------------------------------
    | Enforcement
    |----------------------------------------------------------------------
    */
    Route::prefix('enforcement')->name('enforcement.')->group(function (): void {
        Route::get('bans', Enforcement\Bans::class)
            ->middleware('permission:bans')->name('bans');

        // The safeguard that stops a shadow ban becoming a permanent,
        // invisible, never-revisited punishment.
        Route::get('shadow-reviews', Enforcement\ShadowBanReviews::class)
            ->middleware('permission:shadow_ban_users')->name('shadow-reviews');

        Route::get('devices', Enforcement\Devices::class)
            ->middleware('permission:device_ban_users')->name('devices');

        Route::get('blocks', Enforcement\Blocks::class)
            ->middleware('permission:blocks')->name('blocks');
    });

    /*
    |----------------------------------------------------------------------
    | Appeals
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:appeals')->prefix('appeals')->name('appeals.')->group(function (): void {
        Route::get('/', Appeals\Index::class)->name('index');
        Route::get('{appeal:uuid}', Appeals\Show::class)->name('show');
    });

    /*
    |----------------------------------------------------------------------
    | Notifications
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:notifications')
        ->prefix('notifications')
        ->name('notifications.')
        ->group(function (): void {
            Route::get('/', Notifications\Campaigns::class)->name('campaigns');

            Route::get('templates', Notifications\Templates::class)
                ->middleware('permission:notification_templates')->name('templates');

            Route::get('logs', Notifications\Logs::class)
                ->middleware('permission:push_logs')->name('logs');
        });

    /*
    |----------------------------------------------------------------------
    | Analytics
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:analytics')->prefix('analytics')->name('analytics.')->group(function (): void {
        Route::get('funnel', Dashboard\Funnel::class)->name('funnel');
        Route::get('matching', Dashboard\MatchingHealth::class)->name('matching');
        Route::get('safety', Dashboard\SafetyTrends::class)->name('safety');
        Route::get('retention', Dashboard\Retention::class)->name('retention');
    });

    /*
    |----------------------------------------------------------------------
    | System — platform integrations, kept apart from the dating-app settings
    |----------------------------------------------------------------------
    |
    | Mail, payment and SMS providers plus their delivery logs. Separated from
    | Settings on purpose: those are product and safety policy, these are
    | infrastructure, and the people who own them are rarely the same.
    |
    */
    Route::middleware('permission:settings')->prefix('system')->name('system.')->group(function (): void {
        Route::get('mail', System\Mail::class)->name('mail');

        Route::get('payments', System\Gateways::class)
            ->defaults('kind', 'payment')->name('payments');

        Route::get('sms', System\Gateways::class)
            ->defaults('kind', 'sms')->name('sms');

        Route::get('logs/{kind?}', System\Logs::class)->name('logs');

        Route::get('backup', System\Backup::class)
            ->middleware('permission:run_maintenance_jobs')->name('backup');
    });

    /*
    |----------------------------------------------------------------------
    | Administration
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:staff')->prefix('staff')->name('staff.')->group(function (): void {
        Route::get('/', Staff\Index::class)->name('index');
        Route::get('performance', Staff\Performance::class)
            ->middleware('permission:view_staff_performance')->name('performance');
    });

    Route::middleware('permission:roles')->prefix('roles')->name('roles.')->group(function (): void {
        Route::get('/', Roles\Index::class)->name('index');
        Route::get('{role}/permissions', Roles\PermissionMatrix::class)->name('permissions');
    });

    Route::middleware('permission:activity_log')->prefix('audit')->name('audit.')->group(function (): void {
        Route::get('/', Audit\Index::class)->name('index');
        Route::get('message-access', Audit\MessageAccess::class)
            ->middleware('permission:message_access_log')->name('message-access');
    });

    Route::middleware('permission:settings')->prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/', Settings\Index::class)->name('general');
        // Before {group}, which would otherwise swallow it as a group name.
        Route::get('branding', Settings\Branding::class)->name('branding');
        Route::get('locations', Settings\Locations::class)->name('locations');
        Route::get('{group}', Settings\Index::class)->name('group');
    });

    /*
     * Component gallery. Every UI primitive in every variant on one page — the
     * fastest way to catch a token regression, and the reference when building
     * a new screen.
     */
    if (! app()->environment('production')) {
        Route::view('_kitchen-sink', 'admin.kitchen-sink')->name('kitchen-sink');
    }
});
