<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Conversation */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $other = $viewer ? $this->match?->otherParty($viewer) : null;

        return [
            'id' => $this->uuid,
            'other' => $other ? new AppUserResource($other) : null,
            'messages_count' => $this->messages_count,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'status' => $this->status,
        ];
    }
}
