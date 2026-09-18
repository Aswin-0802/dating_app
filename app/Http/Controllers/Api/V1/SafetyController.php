<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReportCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AppUserResource;
use App\Models\AppUser;
use App\Services\Members\SafetyActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports and blocks. The rules live in SafetyActions, which the member website
 * uses too.
 */
class SafetyController extends Controller
{
    public function report(Request $request, SafetyActions $safety): JsonResponse
    {
        $data = $request->validate([
            'reported_id' => ['required', 'uuid', 'exists:app_users,uuid'],
            'category' => ['required', 'in:'.implode(',', array_column(ReportCategory::cases(), 'value'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'message_id' => ['nullable', 'uuid', 'exists:messages,uuid'],
        ]);

        $reported = AppUser::query()->where('uuid', $data['reported_id'])->firstOrFail();

        $report = $safety->report(
            $request->user(),
            $reported,
            ReportCategory::from($data['category']),
            $data['description'] ?? null,
            $data['message_id'] ?? null,
        );

        return response()->json([
            'message' => 'Thank you. Our team will review this.',
            'report_id' => $report->uuid,
        ], 201);
    }

    public function blocks(Request $request): JsonResponse
    {
        $blocked = AppUser::query()
            ->whereIn('id', fn ($q) => $q->select('blocked_app_user_id')
                ->from('blocks')
                ->where('app_user_id', $request->user()->id))
            ->with('photos')
            ->get();

        return response()->json(['data' => AppUserResource::collection($blocked)]);
    }

    public function block(Request $request, SafetyActions $safety): JsonResponse
    {
        $data = $request->validate([
            'blocked_id' => ['required', 'uuid', 'exists:app_users,uuid'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $target = AppUser::query()->where('uuid', $data['blocked_id'])->firstOrFail();
        $safety->block($request->user(), $target, $data['reason'] ?? null);

        return response()->json(['message' => 'Blocked.'], 201);
    }

    public function unblock(Request $request, string $uuid, SafetyActions $safety): JsonResponse
    {
        $safety->unblock($request->user(), AppUser::query()->where('uuid', $uuid)->firstOrFail());

        return response()->json(['message' => 'Unblocked.']);
    }
}
