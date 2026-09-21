<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\AccountStatus;
use App\Models\AppUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The member's own view of their account.
 *
 * @mixin AppUser
 */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'birthdate' => $this->birthdate?->toDateString(),
            'age' => $this->age,
            'gender' => $this->gender?->value,
            'pronouns' => $this->pronouns,

            /*
             * A shadow ban that the member can detect is not a shadow ban.
             *
             * AccountStatus::publicValue() maps it to `active`, and it is the
             * single place that mapping lives — so there is no second code path
             * that could forget.
             */
            'account_status' => $this->account_status?->publicValue(),
            'verification_status' => $this->verification_status?->value,

            'is_premium' => (bool) $this->is_premium,
            'premium_tier' => $this->premium_tier,
            'premium_until' => $this->premium_until?->toIso8601String(),

            'profile_completion' => $this->profile_completion,
            'city' => $this->whenLoaded('city', fn (): ?array => $this->city ? [
                'name' => $this->city->name,
                'state' => $this->city->state?->name,
                'country' => $this->city->country?->iso2,
            ] : null),

            /*
             * Only feature limits are disclosed. A suspension the member can
             * see is fine — they are told about it — but a shadow ban must not
             * appear here under any name.
             */
            'restrictions' => $this->restrictions(),

            'profile' => new ProfileResource($this->whenLoaded('profile')),
            'preferences' => new PreferenceResource($this->whenLoaded('preferences')),
            'photos' => PhotoResource::collection($this->whenLoaded('photos')),
            'interests' => $this->whenLoaded('interests', fn () => $this->interests->pluck('name')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * A banned or suspended member is told plainly; nothing else leaks.
     */
    public function with(Request $request): array
    {
        if ($this->account_status === AccountStatus::Banned) {
            return ['meta' => ['restricted' => true, 'appealable' => true]];
        }

        return [];
    }
}
