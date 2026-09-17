<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Profile */
class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'bio' => $this->bio,
            'height_cm' => $this->height_cm,
            'job_title' => $this->job_title,
            'company' => $this->company,
            'school' => $this->school,
            'education' => $this->education,
            'relationship_goal' => $this->relationship_goal,
            'drinking' => $this->drinking,
            'smoking' => $this->smoking,
            'children' => $this->children,
            'languages' => $this->languages ?? [],
            'prompts' => $this->prompts ?? [],
        ];
    }
}
