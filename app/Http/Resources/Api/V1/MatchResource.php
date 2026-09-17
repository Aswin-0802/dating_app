<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\MatchRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatchRecord */
class MatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $other = $viewer ? $this->otherParty($viewer) : null;

        return [
            'id' => $this->uuid,
            'matched_at' => $this->matched_at?->toIso8601String(),
            'status' => $this->status,
            'other' => $other ? new AppUserResource($other) : null,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'messages_count' => $this->messages_count,
            // Whether anybody has spoken yet is the only state a client needs to
            // choose between "say hello" and "open the thread".
            'has_conversation' => $this->first_message_at !== null,
        ];
    }
}
