<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    public function conversations(Request $request): JsonResponse
    {
        $member = $request->user();

        $conversations = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('app_users.id', $member->id))
            ->where('status', 'open')
            ->with(['match.userOne.photos', 'match.userTwo.photos'])
            ->orderByDesc('last_message_at')
            ->cursorPaginate(25);

        return response()->json([
            'data' => ConversationResource::collection($conversations->items()),
            'meta' => ['next_cursor' => $conversations->nextCursor()?->encode()],
        ]);
    }

    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipation($request, $conversation);

        // Cursor pagination, never offset: a thread grows from the end, so page
        // numbers shift under the reader every time somebody replies.
        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('created_at')
            ->cursorPaginate(50);

        return response()->json([
            'data' => MessageResource::collection($messages->items()),
            'meta' => ['next_cursor' => $messages->nextCursor()?->encode()],
        ]);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipation($request, $conversation);

        $data = $request->validate([
            'body' => ['required_without:media_path', 'nullable', 'string', 'max:2000'],
            'type' => ['nullable', 'in:text,image,gif,voice'],
            'media_path' => ['nullable', 'string', 'max:255'],
        ]);

        $member = $request->user();
        $body = $data['body'] ?? null;

        $message = DB::transaction(function () use ($conversation, $member, $data, $body): Message {
            $message = Message::query()->create([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'sender_app_user_id' => $member->id,
                'type' => $data['type'] ?? 'text',
                'body' => $body,
                'media_path' => $data['media_path'] ?? null,
                // Scanned on write, so the risk engine and the flagged-message
                // stream have something to read without reprocessing history.
                'contains_link' => $body !== null && $this->containsLink($body),
                'contains_contact_info' => $body !== null && $this->containsContactInfo($body),
                'created_at' => now(),
            ]);

            $isFirst = $conversation->messages_count === 0;

            $conversation->forceFill([
                'messages_count' => $conversation->messages_count + 1,
                'last_message_at' => now(),
                'started_at' => $conversation->started_at ?? now(),
            ])->save();

            $match = $conversation->match;

            if ($match !== null) {
                $attributes = [
                    'messages_count' => $match->messages_count + 1,
                    'last_message_at' => now(),
                ];

                if ($isFirst) {
                    $attributes['first_message_at'] = now();
                }

                // A reply is the first message from the OTHER party. That is the
                // metric that matters — matches alone are vanity.
                if ($match->first_reply_at === null
                    && $match->first_message_at !== null
                    && ! $isFirst
                    && Message::query()
                        ->where('conversation_id', $conversation->id)
                        ->where('sender_app_user_id', '!=', $member->id)
                        ->exists()) {
                    $attributes['first_reply_at'] = now();
                }

                $match->forceFill($attributes)->save();
            }

            if ($member->first_message_at === null) {
                $member->forceFill(['first_message_at' => now()])->saveQuietly();
            }

            return $message;
        });

        return response()->json(['data' => new MessageResource($message)], 201);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipation($request, $conversation);

        Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_app_user_id', '!=', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Marked as read.']);
    }

    /**
     * Ownership, not a policy.
     *
     * The only person entitled to a conversation is somebody in it, so this is
     * a membership check rather than a permission — there is no role that grants
     * access to strangers' threads through this API.
     */
    private function authorizeParticipation(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->hasParticipant($request->user()), 403, 'This conversation is not yours.');
    }

    private function containsLink(string $body): bool
    {
        return (bool) preg_match('~https?://|www\.|\b[a-z0-9-]+\.(com|net|org|io|co|me)\b~i', $body);
    }

    private function containsContactInfo(string $body): bool
    {
        return (bool) preg_match('~@[a-z0-9._]{3,}|\+?\d[\d\s().-]{7,}\d|\b(whatsapp|telegram|snap(chat)?|insta(gram)?)\b~i', $body);
    }
}
