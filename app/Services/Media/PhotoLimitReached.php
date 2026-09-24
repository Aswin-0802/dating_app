<?php

declare(strict_types=1);

namespace App\Services\Media;

use RuntimeException;

/**
 * The member already has as many photos as the operator allows.
 *
 * A distinct type so the API can answer with a machine-readable code
 * (`photo_limit_reached`) rather than a generic 422. The website still
 * catches it as the RuntimeException it always was.
 */
final class PhotoLimitReached extends RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("You can have up to {$limit} photos.");
    }
}
