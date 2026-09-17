<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A message, returned to one of its own participants.
 *
 * `body` is hidden on the model, so it is read here through getRawOriginal.
 * That is safe precisely because this resource is only ever built inside a
 * request already scoped to a conversation the caller belongs to — the same
 * value reached from the admin side has to go through MessageRevealService.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $removed = $this->moderation_status === 'removed';

        return [
            'id' => $this->uuid,
            'type' => $this->type,
            // A removed message stays in the thread as a gap rather than
            // vanishing, so the conversation still makes sense.
            'body' => $removed ? null : $this->getRawOriginal('body'),
            'removed' => $removed,
            'media_url' => $removed ? null : $this->media_path,
            'is_mine' => $request->user()?->id === $this->sender_app_user_id,
            'read_at' => $this->read_at?->toIso8601String(),
            'sent_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
