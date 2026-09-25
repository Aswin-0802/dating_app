<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\PhoneController;
use App\Http\Controllers\Api\V1\PhotoController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\SafetyController;
use App\Http\Controllers\Api\V1\StoreReceiptController;
use App\Http\Controllers\Api\V1\SwipeController;
use App\Models\City;
use App\Models\Country;
use App\Models\Interest;
use App\Models\Plan;
use App\Models\State;
use App\Services\Media\MemberPhotoStore;
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
            'min_supported_version' => platform_setting('api.min_supported_version', '2.4.0'),
            'maintenance_mode' => (bool) platform_setting('general.maintenance_mode', false),
            'min_age' => (int) platform_setting('general.min_age', 18),
            'max_photos' => MemberPhotoStore::maxPhotos(),
            'max_distance_km' => (int) platform_setting('matching.max_distance_km', 160),
            'daily_like_limit' => (int) platform_setting('matching.daily_like_limit_free', 100),
            'appeal_window_days' => (int) platform_setting('enforcement.appeal_window_days', 30),
            'support_email' => platform_setting('brand.support_email'),
        ]);
    })->name('config');

    /*
     * Plans on sale, with the store product identifiers the app asks StoreKit
     * and Play Billing for. Prices come from the stores, in the member's own
     * currency; the website's prices are for the website.
     */
    Route::get('plans', fn () => response()->json([
        'data' => Plan::query()->where('is_active', true)->orderBy('sort_order')->orderBy('monthly_price')->get()
            ->map(fn (Plan $plan): array => [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'tagline' => $plan->tagline,
                'features' => $plan->features ?? [],
                'perks' => $plan->perks ?? [],
                'is_featured' => (bool) $plan->is_featured,
                'products' => $plan->storeProducts(),
            ])->values(),
    ]))->name('plans');

    Route::get('interests', fn () => response()->json([
        'data' => Interest::query()->where('is_active', true)->orderBy('category')->orderBy('sort_order')->orderBy('name')
            ->get(['slug', 'name', 'category']),
    ]))->name('interests');

    Route::get('countries', fn () => response()->json([
        'data' => Country::query()->where('is_active', true)->orderBy('name')
            ->get(['iso2', 'name', 'dial_code']),
    ]))->name('countries');

    // ?country=IN lists that country's states; hidden ones are left out so the
    // apps offer exactly what sign-up accepts.
    Route::get('states', fn () => response()->json([
        'data' => State::query()
            ->where('is_active', true)
            ->when(request('country'), fn ($q, $iso) => $q->whereHas('country', fn ($c) => $c->where('iso2', $iso)))
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'code', 'country_id']),
    ]))->name('states');

    Route::get('cities', fn () => response()->json([
        'data' => City::query()
            ->selectable()
            ->when(request('country'), fn ($q, $iso) => $q->whereHas('country', fn ($c) => $c->where('iso2', $iso)))
            ->when(request('state'), fn ($q, $state) => $q->where('state_id', $state))
            ->with('state:id,name')
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'country_id', 'state_id'])
            ->map(fn (City $city): array => [
                'id' => $city->id,
                'name' => $city->name,
                'country_id' => $city->country_id,
                'state' => $city->state?->name,
                'state_id' => $city->state_id,
            ]),
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
        Route::delete('me', [ProfileController::class, 'destroy'])->name('me.destroy');
        Route::patch('me/profile', [ProfileController::class, 'updateProfile'])->name('me.profile');
        Route::patch('me/preferences', [ProfileController::class, 'updatePreferences'])->name('me.preferences');
        Route::put('me/interests', [ProfileController::class, 'syncInterests'])->name('me.interests');

        /*
         * Photos need only profile:write, which a PENDING token carries. A
         * photo is one of the checklist items behind the pending → active
         * promotion, so a member who could not upload one from the app could
         * be stuck in pending for ever.
         */
        Route::middleware('ability:profile:write')->group(function (): void {
            Route::post('me/photos', [PhotoController::class, 'store'])->name('me.photos.store');
            Route::patch('me/photos/reorder', [PhotoController::class, 'reorder'])->name('me.photos.reorder');
            Route::patch('me/photos/{uuid}/primary', [PhotoController::class, 'primary'])->name('me.photos.primary');
            Route::delete('me/photos/{uuid}', [PhotoController::class, 'destroy'])->name('me.photos.destroy');
        });

        // Premium-gated in the controller, not here: a free member gets the
        // count with the refusal, which is the upsell.
        Route::get('me/likers', [ProfileController::class, 'likers'])->name('me.likers');

        /*
         * "I bought Premium in the app store." The token is an identifier;
         * the server asks the store, and what the store says is what the
         * member gets. Posting the same transaction twice changes nothing.
         */
        Route::post('me/premium/receipt', [StoreReceiptController::class, 'store'])
            ->middleware(['ability:profile:write', 'throttle:receipt'])->name('me.premium.receipt');

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
        /*
         * Phone verification. Throttled hard: each request costs money and an
         * unthrottled OTP endpoint is a way to run up somebody's SMS bill.
         */
        Route::post('phone/send-code', [PhoneController::class, 'sendCode'])
            ->middleware('throttle:4,10')->name('phone.send-code');
        Route::post('phone/verify', [PhoneController::class, 'verify'])
            ->middleware('throttle:10,10')->name('phone.verify');

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
