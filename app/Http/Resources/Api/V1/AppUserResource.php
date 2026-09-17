<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\AppUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Another member, as seen by this one.
 *
 * Everything absent here is absent deliberately. No email, no phone, no
 * birthdate, no coordinates, no risk score, no report counts, no account status.
 * This is the resource a scraper would target, so it carries only what a profile
 * card needs in order to render.
 *
 * @mixin AppUser
 */
class AppUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'display_name' => $this->display_name,
            // Age, never birthdate: a date of birth is an identity-document
            // field and a profile card has no use for one.
            'age' => $this->age,
            'gender' => $this->gender?->value,
            'pronouns' => $this->pronouns,
            'is_verified' => $this->verification_status?->value === 'approved',

            'city' => $this->whenLoaded('city', fn (): ?string => $this->city?->name),

            // Bucketed to the kilometre. Exact distances from several vantage
            // points trilaterate a home address.
            'distance_km' => $this->when(
                isset($this->distance_km),
                fn (): int => (int) round((float) $this->distance_km),
            ),

            'photos' => PhotoResource::collection($this->whenLoaded('photos')),
            'profile' => new ProfileResource($this->whenLoaded('profile')),
            'interests' => $this->whenLoaded('interests', fn () => $this->interests->pluck('name')),
        ];
    }
}
