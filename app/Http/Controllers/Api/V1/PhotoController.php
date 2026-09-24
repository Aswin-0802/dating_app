<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PhotoResource;
use App\Models\AppUser;
use App\Models\Photo;
use App\Services\Media\MemberPhotoStore;
use App\Services\Media\PhotoLimitReached;
use App\Services\Members\ProfileCompletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A member's own photos.
 *
 * Everything here delegates to MemberPhotoStore — the same service the
 * website uses. That service decodes and re-encodes every upload through GD,
 * which is what strips EXIF GPS and neutralises polyglot files. It is the
 * security control, and this controller must never grow a second path
 * around it.
 */
class PhotoController extends Controller
{
    public function __construct(
        private readonly MemberPhotoStore $store,
        private readonly ProfileCompletion $completion,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:10240'],
        ], [
            'photo.max' => 'A photo can be up to 10 MB.',
            'photo.image' => 'That file is not an image.',
        ]);

        $member = $request->user();

        try {
            $photo = $this->store->store($member, $request->file('photo'));
        } catch (PhotoLimitReached $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'photo_limit_reached',
                'errors' => ['photo' => [$e->getMessage()]],
                'limit' => $e->limit,
            ], 422);
        } catch (RuntimeException $e) {
            // The file passed the extension check but GD could not decode it.
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        }

        // A photo is a checklist item; this is what promotes a pending member.
        $this->completion->refresh($member);

        return response()->json(['data' => new PhotoResource($photo)], 201);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $member = $request->user();

        $this->store->delete($this->ownPhoto($member, $uuid));
        $this->completion->refresh($member);

        return response()->json(['message' => 'Photo removed.']);
    }

    public function primary(Request $request, string $uuid): JsonResponse
    {
        $member = $request->user();

        $this->store->makePrimary($this->ownPhoto($member, $uuid));

        return response()->json(['data' => PhotoResource::collection($member->photos()->get())]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuids' => ['required', 'array', 'min:1'],
            'uuids.*' => ['required', 'string', 'uuid', 'distinct'],
        ]);

        $member = $request->user();

        $this->store->reorder($member, $data['uuids']);

        return response()->json(['data' => PhotoResource::collection($member->photos()->get())]);
    }

    /**
     * A photo by uuid, only if it is this member's.
     *
     * 404 when it does not exist, 403 when it is somebody else's — the
     * difference tells an honest client its list is stale rather than that
     * it has a bug.
     */
    private function ownPhoto(AppUser $member, string $uuid): Photo
    {
        $photo = Photo::query()->where('uuid', $uuid)->first();

        abort_if($photo === null, 404, 'Photo not found.');
        abort_unless($photo->app_user_id === $member->id, 403, 'That photo is not yours.');

        return $photo;
    }
}
