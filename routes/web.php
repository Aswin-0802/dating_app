<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\MemberSessionController;
use App\Livewire\Member;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Three audiences share this file:
|
|   /               the public website — anybody
|   /login, /join   member sign-in and sign-up
|   /app/*          the member web app — signed-in members
|   /admin/login    staff sign-in (the console itself is routes/admin.php)
|
| Staff and members use different guards (`web` and `member`) over different
| tables, so being signed in as one never grants anything as the other.
|
*/

// ---- public website --------------------------------------------------------

Route::get('/', HomeController::class)->name('home');
Route::view('safety', 'site.safety')->name('site.safety');
Route::view('terms', 'site.legal', ['page' => 'terms'])->name('site.terms');
Route::view('privacy', 'site.legal', ['page' => 'privacy'])->name('site.privacy');

// ---- member sign-in and sign-up ---------------------------------------------

Route::middleware('guest:member')->group(function (): void {
    Route::get('login', Member\Auth\Login::class)->name('member.login');
    Route::get('join', Member\Auth\Register::class)->name('member.register');

    Route::controller(PasswordResetController::class)->group(function (): void {
        Route::get('forgot-password', 'create')->defaults('audience', 'member')->name('member.password.request');
        Route::post('forgot-password', 'store')->defaults('audience', 'member')->middleware('throttle:5,1')->name('member.password.email');
        Route::get('reset-password/{token}', 'edit')->defaults('audience', 'member')->name('member.password.reset');
        Route::post('reset-password', 'update')->defaults('audience', 'member')->middleware('throttle:5,1')->name('member.password.update');
    });
});

Route::post('logout', [MemberSessionController::class, 'destroy'])
    ->middleware('auth:member')
    ->name('member.logout');

// ---- member web app ----------------------------------------------------------

Route::middleware(['auth:member', 'member.active'])
    ->prefix('app')
    ->name('member.')
    ->group(function (): void {
        Route::redirect('/', '/app/discover');
        Route::get('discover', Member\Discover::class)->name('discover');
        Route::get('matches', Member\Matches::class)->name('matches');
        Route::get('messages/{conversation:uuid?}', Member\Messages::class)->name('messages');
        Route::get('people/{person:uuid}', Member\Person::class)->name('person');
        Route::get('profile', Member\Profile::class)->name('profile');
        Route::get('verification', Member\Verification::class)->name('verification');
        Route::get('premium', Member\Premium::class)->name('premium');
        Route::get('account', Member\Account::class)->name('account');
        Route::get('restricted', Member\Restricted::class)->name('restricted');
    });

// ---- staff sign-in -----------------------------------------------------------

Route::middleware('guest')->group(function (): void {
    Route::get('admin/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('admin/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:6,1');

    Route::controller(PasswordResetController::class)->group(function (): void {
        Route::get('admin/forgot-password', 'create')->defaults('audience', 'staff')->name('password.request');
        Route::post('admin/forgot-password', 'store')->defaults('audience', 'staff')->middleware('throttle:5,1')->name('password.email');
        Route::get('admin/reset-password/{token}', 'edit')->defaults('audience', 'staff')->name('password.reset');
        Route::post('admin/reset-password', 'update')->defaults('audience', 'staff')->middleware('throttle:5,1')->name('password.update');
    });
});

Route::post('admin/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:web')
    ->name('logout');
