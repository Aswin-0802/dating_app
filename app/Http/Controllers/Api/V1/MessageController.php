<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Members\MessageSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function index(Request $request, Conversation $conversation, MessageSender $sender): JsonResponse
    {
        // Reading is gated exactly like writing. A thread closed by a block or
        // an unmatch disappears for both people; leaving it readable kept the
        // blocked party watching the conversation they had been removed from.
        $sender->assertUsableBy($conversation, $request->user());

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

    public function store(Request $request, Conversation $conversation, MessageSender $sender): JsonResponse
    {
        // send() asserts this too — it is the owner of the rule. Repeated here
        // only so a stranger gets 403 rather than a 422 describing the payload.
        $sender->assertUsableBy($conversation, $request->user());

        $data = $request->validate([
            'body' => ['required_without:media_path', 'nullable', 'string', 'max:2000'],
            'type' => ['nullable', 'in:text,image,gif,voice'],
            'media_path' => ['nullable', 'string', 'max:255'],
        ]);

        $message = $sender->send(
            $conversation,
            $request->user(),
            $data['body'] ?? null,
            $data['type'] ?? 'text',
            $data['media_path'] ?? null,
        );

        return response()->json(['data' => new MessageResource($message)], 201);
    }

    public function markRead(Request $request, Conversation $conversation, MessageSender $sender): JsonResponse
    {
        $sender->markRead($conversation, $request->user());

        return response()->json(['message' => 'Marked as read.']);
    }
}
