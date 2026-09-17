<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Preference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Preference */
class PreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'interested_in' => $this->interested_in ?? [],
            'age_min' => $this->age_min,
            'age_max' => $this->age_max,
            'max_distance_km' => $this->max_distance_km,
            'global_mode' => (bool) $this->global_mode,
            'show_verified_only' => (bool) $this->show_verified_only,
        ];
    }
}
