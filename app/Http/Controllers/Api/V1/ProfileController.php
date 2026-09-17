<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MatchResource;
use App\Http\Resources\Api\V1\MeResource;
use App\Http\Resources\Api\V1\VerificationResource;
use App\Models\City;
use App\Models\Interest;
use App\Models\MatchRecord;
use App\Models\Verification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $member = $request->user()->load([
            'profile', 'preferences', 'photos', 'interests', 'city.country', 'activeBan',
        ]);

        return response()->json(['data' => new MeResource($member)]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'min:2', 'max:60'],
            'pronouns' => ['sometimes', 'nullable', 'string', 'max:30'],
            'city_id' => ['sometimes', 'nullable', 'exists:cities,id'],
        ]);

        $member = $request->user();
        $member->fill($data);

        if ($member->isDirty('city_id') && $member->city_id !== null) {
            $member->country_id = City::query()->find($member->city_id)?->country_id;
        }

        $member->save();

        return response()->json(['data' => new MeResource($member->fresh(['profile', 'city.country']))]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'height_cm' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:250'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company' => ['sometimes', 'nullable', 'string', 'max:100'],
            'school' => ['sometimes', 'nullable', 'string', 'max:100'],
            'education' => ['sometimes', 'nullable', 'string', 'max:60'],
            'relationship_goal' => ['sometimes', 'in:long_term,short_term,friends,figuring_out,unspecified'],
            'drinking' => ['sometimes', 'in:never,socially,often,unspecified'],
            'smoking' => ['sometimes', 'in:never,socially,often,unspecified'],
            'children' => ['sometimes', 'in:have,want,dont_want,unspecified'],
            'languages' => ['sometimes', 'array'],
            'prompts' => ['sometimes', 'array'],
        ]);

        $member = $request->user();
        $profile = $member->profile;

        // Flagged on write so the risk engine reads a stored fact rather than
        // re-scanning every bio on every recompute.
        if (array_key_exists('bio', $data)) {
            $data['bio_contains_contact'] = $data['bio'] !== null && $this->looksLikeContactDetails($data['bio']);
        }

        $profile->update($data);

        $this->refreshCompletion($member);

        return response()->json(['data' => new MeResource($member->fresh(['profile', 'photos']))]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'interested_in' => ['sometimes', 'array', 'min:1'],
            'age_min' => ['sometimes', 'integer', 'min:18', 'max:99'],
            'age_max' => ['sometimes', 'integer', 'min:18', 'max:99', 'gte:age_min'],
            'max_distance_km' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'global_mode' => ['sometimes', 'boolean'],
            'show_verified_only' => ['sometimes', 'boolean'],
        ]);

        $request->user()->preferences->update($data);

        return response()->json(['data' => new MeResource($request->user()->fresh('preferences'))]);
    }

    public function syncInterests(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slugs' => ['required', 'array', 'max:10'],
            'slugs.*' => ['string', 'exists:interests,slug'],
        ]);

        $ids = Interest::query()->whereIn('slug', $data['slugs'])->pluck('id');
        $request->user()->interests()->sync($ids);

        return response()->json(['data' => new MeResource($request->user()->fresh('interests'))]);
    }

    public function matches(Request $request): JsonResponse
    {
        $matches = MatchRecord::query()
            ->involving($request->user())
            ->active()
            ->with(['userOne.photos', 'userTwo.photos'])
            ->orderByDesc('matched_at')
            ->cursorPaginate(25);

        return response()->json([
            'data' => MatchResource::collection($matches->items()),
            'meta' => ['next_cursor' => $matches->nextCursor()?->encode()],
        ]);
    }

    public function unmatch(Request $request, MatchRecord $match): JsonResponse
    {
        abort_unless(
            in_array($request->user()->id, [$match->app_user_one_id, $match->app_user_two_id], true),
            403,
        );

        $match->forceFill(['status' => 'unmatched', 'unmatched_by' => $request->user()->id])->save();

        return response()->json(['message' => 'Unmatched.']);
    }

    public function verification(Request $request): JsonResponse
    {
        $verification = $request->user()->latestVerification;

        return response()->json([
            'data' => $verification ? new VerificationResource($verification) : null,
        ]);
    }

    /**
     * Submit a selfie for verification.
     *
     * The gesture code is issued by the server and must appear in the capture:
     * it is what stops somebody uploading a photograph of a photograph.
     */
    public function submitVerification(Request $request): JsonResponse
    {
        $request->validate([
            'selfie' => ['required', 'image', 'max:10240'],
            'gesture_code' => ['required', 'string', 'size:4'],
        ]);

        $member = $request->user();
        $max = (int) config('veyra.verification.max_attempts', 3);
        $used = Verification::query()->where('app_user_id', $member->id)->count();

        if ($used >= $max) {
            return response()->json([
                'message' => 'You have used all your verification attempts. Contact support.',
                'code' => 'verification_attempts_exhausted',
            ], 422);
        }

        $path = $request->file('selfie')->store('selfies', 'verifications');

        $verification = Verification::query()->create([
            'uuid' => (string) Str::uuid(),
            'app_user_id' => $member->id,
            'attempt_no' => $used + 1,
            'type' => 'selfie',
            'status' => 'pending',
            'queue' => 'standard',
            'selfie_disk' => 'verifications',
            'selfie_path' => $path,
            'gesture_code' => strtoupper($request->string('gesture_code')),
            'submitted_at' => now(),
            'sla_due_at' => now()->addHours((int) veyra_setting('verification.sla_hours', 24)),
        ]);

        $member->forceFill(['verification_status' => 'pending'])->save();

        return response()->json(['data' => new VerificationResource($verification)], 202);
    }

    public function gestureCode(): JsonResponse
    {
        return response()->json([
            'gesture_code' => strtoupper(Str::random(2).random_int(10, 99)),
            'expires_in' => 600,
        ]);
    }

    /**
     * Recompute how complete a profile is, and promote out of `pending` once
     * there is enough there to be worth showing to anybody.
     */
    private function refreshCompletion($member): void
    {
        $profile = $member->profile;

        $filled = collect([
            filled($profile?->bio),
            filled($profile?->job_title),
            filled($profile?->education),
            $profile?->height_cm !== null,
            $profile?->relationship_goal !== 'unspecified',
            $member->photos()->count() > 0,
            $member->interests()->count() >= 3,
            filled($member->city_id),
        ])->filter()->count();

        $completion = (int) round($filled / 8 * 100);

        $attributes = ['profile_completion' => $completion];

        if ($completion >= 50 && $member->account_status === AccountStatus::Pending) {
            $attributes['account_status'] = AccountStatus::Active;
            $attributes['profile_completed_at'] = $member->profile_completed_at ?? now();
        }

        $member->forceFill($attributes)->save();
    }

    private function looksLikeContactDetails(string $bio): bool
    {
        return (bool) preg_match('~@[a-z0-9._]{3,}|\+?\d[\d\s().-]{7,}\d|\b(whatsapp|telegram|snap(chat)?|insta(gram)?)\b~i', $bio);
    }
}
