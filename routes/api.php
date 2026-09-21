<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\SafetyController;
use App\Http\Controllers\Api\V1\SwipeController;
use App\Models\City;
use App\Models\Country;
use App\Models\Interest;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API, v1
|--------------------------------------------------------------------------
|
| Shares the domain layer with the admin console: the same models, the same
| observers, the same Actions. A report filed here becomes a case in the
| moderation queue with no glue code, and an enforcement applied there takes
| effect here on the next request.
|
| What differs is serialisation. The console reads models directly — it is
| permission-gated and audited — while everything leaving through this API goes
| through a Resource, because that is where risk scores, internal notes and
| shadow-ban state would otherwise leak.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    /*
    |----------------------------------------------------------------------
    | Public
    |----------------------------------------------------------------------
    */
    Route::get('config', function () {
        return response()->json([
            'min_supported_version' => veyra_setting('api.min_supported_version', '2.4.0'),
            'maintenance_mode' => (bool) veyra_setting('general.maintenance_mode', false),
            'min_age' => (int) veyra_setting('general.min_age', 18),
            'max_photos' => (int) veyra_setting('matching.max_photos', 9),
            'max_distance_km' => (int) veyra_setting('matching.max_distance_km', 160),
            'daily_like_limit' => (int) veyra_setting('matching.daily_like_limit_free', 100),
            'appeal_window_days' => (int) veyra_setting('enforcement.appeal_window_days', 30),
            'support_email' => veyra_setting('brand.support_email'),
        ]);
    })->name('config');

    Route::get('interests', fn () => response()->json([
        'data' => Interest::query()->where('is_active', true)->orderBy('category')->orderBy('sort_order')->orderBy('name')
            ->get(['slug', 'name', 'category']),
    ]))->name('interests');

    Route::get('countries', fn () => response()->json([
        'data' => Country::query()->where('is_active', true)->orderBy('name')
            ->get(['iso2', 'name', 'dial_code']),
    ]))->name('countries');

    Route::get('cities', fn () => response()->json([
        'data' => City::query()
            ->when(request('country'), fn ($q, $iso) => $q->whereHas('country', fn ($c) => $c->where('iso2', $iso)))
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'country_id']),
    ]))->name('cities');

    Route::prefix('auth')->name('auth.')->group(function (): void {
        // Keyed on IP and email together, so credential stuffing across many
        // accounts from one address does not get a fresh budget per account.
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:auth')->name('register');
        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:auth')->name('login');
    });

    /*
    |----------------------------------------------------------------------
    | Authenticated
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'appuser.active', 'throttle:api'])->group(function (): void {

        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');

        // ---- me ----
        Route::get('me', [ProfileController::class, 'me'])->name('me');
        Route::patch('me', [ProfileController::class, 'update'])->name('me.update');
        Route::patch('me/profile', [ProfileController::class, 'updateProfile'])->name('me.profile');
        Route::patch('me/preferences', [ProfileController::class, 'updatePreferences'])->name('me.preferences');
        Route::put('me/interests', [ProfileController::class, 'syncInterests'])->name('me.interests');

        // ---- discovery ----
        Route::get('deck', [SwipeController::class, 'deck'])
            ->middleware(['ability:swipe', 'throttle:deck'])->name('deck');

        Route::post('swipes', [SwipeController::class, 'store'])
            ->middleware(['ability:swipe', 'throttle:swipe'])->name('swipes.store');

        // ---- matches ----
        Route::get('matches', [ProfileController::class, 'matches'])->name('matches');
        Route::delete('matches/{match:uuid}', [ProfileController::class, 'unmatch'])->name('matches.unmatch');

        // ---- conversations ----
        Route::get('conversations', [MessageController::class, 'conversations'])->name('conversations');
        Route::get('conversations/{conversation:uuid}/messages', [MessageController::class, 'index'])
            ->name('conversations.messages');
        Route::post('conversations/{conversation:uuid}/messages', [MessageController::class, 'store'])
            ->middleware(['ability:message', 'throttle:message'])->name('conversations.messages.store');
        Route::post('conversations/{conversation:uuid}/read', [MessageController::class, 'markRead'])
            ->name('conversations.read');

        // ---- safety ----
        Route::post('reports', [SafetyController::class, 'report'])
            ->middleware(['ability:report', 'throttle:report'])->name('reports.store');
        Route::get('blocks', [SafetyController::class, 'blocks'])->name('blocks');
        Route::post('blocks', [SafetyController::class, 'block'])->name('blocks.store');

        /*
         * Push registration. No ability is required: a member who can sign in
         * can always be reached, and gating it behind a token ability would
         * silence exactly the accounts under review that we most need to
         * notify about a decision.
         */
        Route::post('devices/push-token', [DeviceController::class, 'storePushToken'])->name('devices.push-token.store');
        Route::delete('devices/push-token', [DeviceController::class, 'deletePushToken'])->name('devices.push-token.destroy');
        Route::delete('blocks/{uuid}', [SafetyController::class, 'unblock'])->name('blocks.destroy');

        // ---- verification ----
        Route::get('verification', [ProfileController::class, 'verification'])->name('verification');
        Route::get('verification/gesture', [ProfileController::class, 'gestureCode'])->name('verification.gesture');
        Route::post('verification', [ProfileController::class, 'submitVerification'])
            ->middleware(['ability:verify', 'throttle:verification'])->name('verification.store');
    });
});
