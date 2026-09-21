<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where the mobile apps hand over their push token.
 *
 * The app sends this after the member allows notifications, and again whenever
 * Firebase rotates the token — which it does on reinstall, restore and clear
 * data, so an app that only registers once eventually goes silent.
 */
class DeviceController extends Controller
{
    public function storePushToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:512'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
            'label' => ['nullable', 'string', 'max:120'],
            // Optional: ties the token to the device record used for risk work.
            'device_fingerprint' => ['nullable', 'string', 'max:255'],
        ]);

        $member = $request->user();

        $deviceId = null;

        if (! empty($data['device_fingerprint'])) {
            $deviceId = Device::query()
                ->where('app_user_id', $member->id)
                ->where('fingerprint_hash', hash('sha256', $data['device_fingerprint']))
                ->value('id');
        }

        PushToken::register(
            member: $member,
            token: $data['token'],
            platform: $data['platform'],
            label: $data['label'] ?? null,
            deviceId: $deviceId,
        );

        return response()->json(['data' => ['status' => 'registered']], 201);
    }

    /** Called on sign-out, so the next person on that phone gets nothing of theirs. */
    public function deletePushToken(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);

        PushToken::query()
            ->where('app_user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->delete();

        return response()->json(['data' => ['status' => 'removed']]);
    }
}
