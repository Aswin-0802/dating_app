<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\ReasonCode;
use App\Models\Verification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Verification */
class VerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $max = (int) config('veyra.verification.max_attempts', 3);

        return [
            'id' => $this->uuid,
            'status' => $this->status?->value,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'attempts_used' => $this->attempt_no,
            'attempts_remaining' => max(0, $max - $this->attempt_no),

            /*
             * The member gets the human-readable reason and nothing else.
             *
             * The face-match score, the liveness score and the duplicate-face
             * count stay internal: they are detection signals, and publishing
             * them tells anybody gaming the system exactly what to change.
             */
            'rejection_reason' => $this->when(
                $this->rejection_reason_code !== null,
                fn (): ?string => $this->rejection_reason_code instanceof ReasonCode
                    ? $this->rejection_reason_code->label()
                    : null,
            ),
        ];
    }
}
