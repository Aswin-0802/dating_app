<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Photo */
class PhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'url' => $this->url,
            'thumb_url' => $this->thumb_url,
            'position' => $this->position,
            'is_primary' => (bool) $this->is_primary,

            // Moderation state appears only on a member's own photos, so they
            // can tell why one is not showing. On somebody else's profile it
            // would only reveal what our detection flagged.
            'moderation_status' => $this->when(
                $request->user()?->id === $this->app_user_id,
                fn (): string => (string) $this->moderation_status,
            ),
        ];
    }
}
