<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sms\PhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phone verification for the mobile apps. */
class PhoneController extends Controller
{
    public function __construct(private readonly PhoneVerification $verification) {}

    public function sendCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:8', 'max:20', 'regex:/^\+?[0-9 ()-]{8,20}$/'],
        ]);

        if (! $this->verification->available()) {
            return response()->json([
                'message' => 'Phone verification is not available.',
                'code' => 'sms_unavailable',
            ], 503);
        }

        // Throws a 422 with the reason when the number is taken, the last code
        // is too recent, or the text could not be sent.
        $record = $this->verification->start($request->user(), $data['phone']);

        return response()->json([
            'data' => [
                'status' => 'sent',
                'expires_at' => $record->expires_at->toIso8601String(),
            ],
        ], 202);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        $this->verification->confirm($request->user(), $data['code']);

        return response()->json(['data' => ['status' => 'verified']]);
    }
}
