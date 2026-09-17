<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Verification;
use App\Services\Audit\ActivityLogger;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a verification selfie.
 *
 * These are biometric submissions, so they live on a private disk and are
 * reached only through a signed, short-lived URL behind a permission check.
 * Routing them through PHP costs a request per image; putting them on the
 * public disk would be faster and indefensible.
 */
class VerificationSelfieController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function __invoke(Verification $verification): StreamedResponse
    {
        abort_unless(auth()->user()?->can('verifications'), 403);

        if ($verification->queue === 'restricted_minor') {
            abort_unless(auth()->user()?->can('restricted_minor_queue'), 404);
        }

        abort_unless($verification->selfieExists(), 404);

        // Viewing somebody's biometric submission is a privileged read, and is
        // logged as one.
        $this->logger->logSensitiveAccess(
            module: 'verification',
            action: 'viewed_selfie',
            subject: $verification,
            description: "Viewed verification selfie for {$verification->appUser?->display_name}",
        );

        return Storage::disk($verification->selfie_disk)->response(
            $verification->selfie_path,
            null,
            ['Cache-Control' => 'private, max-age=600'],
        );
    }
}
