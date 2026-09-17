<?php

declare(strict_types=1);

use App\Livewire\Users;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin console
|--------------------------------------------------------------------------
|
| Prefixed with /admin and named admin.* by bootstrap/app.php.
|
| Every route carries the permission it needs. Routes are added module by
| module; App\Support\Navigation tolerates ones that do not exist yet, so the
| sidebar stays correct throughout the build.
|
*/

Route::middleware(['auth', 'staff.active'])->group(function (): void {

    Route::view('/', 'admin.dashboard')
        ->middleware('permission:dashboard')
        ->name('dashboard');

    Route::view('profile', 'admin.profile')->name('profile');

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
     * Component gallery. Every UI primitive in every variant on one page — the
     * fastest way to catch a token regression, and the reference when building
     * a new screen.
     */
    if (! app()->environment('production')) {
        Route::view('_kitchen-sink', 'admin.kitchen-sink')->name('kitchen-sink');
    }
});
