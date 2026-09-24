<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AppUserResource;
use App\Http\Resources\Api\V1\MatchResource;
use App\Http\Resources\Api\V1\MeResource;
use App\Http\Resources\Api\V1\VerificationResource;
use App\Models\City;
use App\Models\Interest;
use App\Models\MatchRecord;
use App\Services\Members\AccountDeletion;
use App\Services\Members\ContentScanner;
use App\Services\Members\Likers;
use App\Services\Members\MatchActions;
use App\Services\Members\ProfileCompletion;
use App\Services\Members\VerificationSubmission;
use App\Support\ProfileOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'display_name' => ['sometimes', 'string', 'min:2', 'max:60', ProfileOptions::NAME_RULE],
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
            'education' => ['sometimes', 'nullable', Rule::in(array_keys(ProfileOptions::forSelect('education', $request->user()->profile?->education)))],
            'relationship_goal' => ['sometimes', Rule::in(array_keys(ProfileOptions::forSelect('relationship_goal', $request->user()->profile?->relationship_goal)))],
            'drinking' => ['sometimes', Rule::in(array_keys(ProfileOptions::forSelect('drinking', $request->user()->profile?->drinking)))],
            'smoking' => ['sometimes', Rule::in(array_keys(ProfileOptions::forSelect('smoking', $request->user()->profile?->smoking)))],
            'children' => ['sometimes', Rule::in(array_keys(ProfileOptions::forSelect('children', $request->user()->profile?->children)))],
            'languages' => ['sometimes', 'array'],
            'prompts' => ['sometimes', 'array'],
        ]);

        $member = $request->user();
        $profile = $member->profile;

        // Flagged on write so the risk engine reads a stored fact rather than
        // re-scanning every bio on every recompute.
        if (array_key_exists('bio', $data)) {
            $data['bio_contains_contact'] = $data['bio'] !== null && ContentScanner::containsContactInfo($data['bio']);
        }

        $profile->update($data);

        app(ProfileCompletion::class)->refresh($member);

        return response()->json(['data' => new MeResource($member->fresh(['profile', 'photos']))]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'interested_in' => ['sometimes', 'array', 'min:1'],
            'age_min' => ['sometimes', 'integer', 'min:18', 'max:99'],
            'age_max' => ['sometimes', 'integer', 'min:18', 'max:99'],
            'max_distance_km' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'global_mode' => ['sometimes', 'boolean'],
            'show_verified_only' => ['sometimes', 'boolean'],
        ]);

        $preferences = $request->user()->preferences;

        /*
         * Checked against the stored pair, not the payload.
         *
         * `gte:age_min` only fires when age_min is in the same request, so
         * PATCH {"age_max": 20} was refused while PATCH {"age_min": 60} sailed
         * past and left 60..28 in the database — a range no member can satisfy,
         * and an empty deck for ever with nothing on screen to explain it.
         */
        $min = (int) ($data['age_min'] ?? $preferences->age_min);
        $max = (int) ($data['age_max'] ?? $preferences->age_max);

        if ($min > $max) {
            throw ValidationException::withMessages([
                array_key_exists('age_min', $data) ? 'age_min' : 'age_max' => "That would leave an impossible range ({$min} to {$max}). Send both ages to move the whole range.",
            ]);
        }

        $preferences->update($data);

        return response()->json(['data' => new MeResource($request->user()->fresh('preferences'))]);
    }

    public function syncInterests(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slugs' => ['required', 'array', 'max:10'],
            'slugs.*' => ['string', Rule::exists('interests', 'slug')->where('is_active', true)],
        ]);

        $ids = Interest::query()->whereIn('slug', $data['slugs'])->pluck('id');
        $request->user()->interests()->sync($ids);

        // Interests are a checklist item. The website recomputed completion
        // here and this endpoint did not, so a member finishing their profile
        // from the app stayed pending.
        app(ProfileCompletion::class)->refresh($request->user());

        return response()->json(['data' => new MeResource($request->user()->fresh('interests'))]);
    }

    /**
     * Who has liked this member and is waiting for an answer.
     *
     * Everybody may know how many; only a plan with `see_likers` may know who.
     * The refusal carries the count so the app can show "7 people liked you"
     * as the upsell without revealing anyone.
     */
    public function likers(Request $request, Likers $likers): JsonResponse
    {
        $member = $request->user();

        if (! $member->hasPremiumFeature('see_likers')) {
            return response()->json([
                'message' => 'Seeing who liked you is a Premium feature.',
                'code' => 'premium_required',
                'count' => $likers->count($member),
            ], 403);
        }

        $page = $likers->query($member)
            ->with(['photos', 'city'])
            ->orderByDesc('id')
            ->cursorPaginate(25);

        return response()->json([
            'data' => AppUserResource::collection($page->items()),
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'count' => $likers->count($member),
            ],
        ]);
    }

    /**
     * Delete the account.
     *
     * Soft-delete and anonymise — see AccountDeletion for why a hard erase is
     * the wrong thing on a platform whose moderation record is evidence.
     */
    public function destroy(Request $request, AccountDeletion $deletion): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $deletion->delete($request->user(), $data['password']);

        return response()->json(['message' => 'Your account has been deleted.']);
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

    public function unmatch(Request $request, MatchRecord $match, MatchActions $matches): JsonResponse
    {
        $matches->end($request->user(), $match);

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
    public function submitVerification(Request $request, VerificationSubmission $submission): JsonResponse
    {
        $data = $request->validate([
            'selfie' => ['required', 'image', 'max:10240'],
            'gesture_code' => ['required', 'string', 'size:4'],
        ]);

        $verification = $submission->submit($request->user(), $request->file('selfie'), $data['gesture_code']);

        if ($verification === null) {
            return response()->json([
                'message' => 'You have used all your verification attempts. Contact support.',
                'code' => 'verification_attempts_exhausted',
            ], 422);
        }

        return response()->json(['data' => new VerificationResource($verification)], 202);
    }

    public function gestureCode(Request $request, VerificationSubmission $submission): JsonResponse
    {
        return response()->json([
            'gesture_code' => $submission->issueGestureCode($request->user()),
            'expires_in' => VerificationSubmission::GESTURE_TTL_MINUTES * 60,
        ]);
    }
}
