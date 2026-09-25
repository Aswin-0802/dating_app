<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MeResource;
use App\Models\AppUser;
use App\Rules\SelectableCity;
use App\Services\Members\MemberAccounts;
use App\Support\ProfileOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:60', ProfileOptions::NAME_RULE],
            'email' => ['required', 'email', 'max:255', 'unique:app_users,email'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
            // Enforced at the schema boundary as well as in the app: an
            // under-18 account is a safety incident, not a validation warning.
            'birthdate' => ['required', 'date', 'before:'.now()->subYears(18)->toDateString()],
            'gender' => ['required', 'string', 'in:'.implode(',', array_column(Gender::cases(), 'value'))],
            'interested_in' => ['required', 'array', 'min:1'],
            // Optional here — onboarding asks again — but never a hidden place.
            'city_id' => ['sometimes', 'nullable', 'integer', new SelectableCity],
        ], [
            'birthdate.before' => 'You must be at least 18 to use this service.',
        ]);

        $member = app(MemberAccounts::class)->register($data, 'ios');

        return response()->json([
            'token' => $member->createToken('mobile', $this->abilitiesFor($member))->plainTextToken,
            'user' => new MeResource($member->load(['profile', 'preferences'])),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $member = AppUser::query()->where('email', strtolower($data['email']))->first();

        $accounts = app(MemberAccounts::class);

        if ($member === null || ! Hash::check($data['password'], $member->password)) {
            // Failed attempts against a real account are recorded, so credential
            // stuffing is visible in the login log.
            if ($member !== null) {
                $accounts->recordLogin($member, $request->ip(), succeeded: false);
            }

            throw ValidationException::withMessages([
                'email' => 'Those details do not match our records.',
            ]);
        }

        $accounts->recordLogin($member, $request->ip(), succeeded: true);

        return response()->json([
            'token' => $member->createToken('mobile', $this->abilitiesFor($member))->plainTextToken,
            'user' => new MeResource($member->load(['profile', 'preferences', 'photos', 'city.country'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Signed out everywhere.']);
    }

    /**
     * Token abilities.
     *
     * A member who has not finished onboarding gets a reduced set rather than a
     * full token they cannot usefully spend. A shadow-banned member gets the
     * full set — anything else would make the restriction detectable from the
     * token alone.
     *
     * @return array<int, string>
     */
    private function abilitiesFor(AppUser $member): array
    {
        if ($member->account_status === AccountStatus::Pending) {
            return ['profile:read', 'profile:write', 'verify'];
        }

        return ['profile:read', 'profile:write', 'swipe', 'message', 'report', 'verify'];
    }
}
