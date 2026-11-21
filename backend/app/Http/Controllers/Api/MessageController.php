<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MediaService;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD operations for chat messages.
 */
class MessageController extends Controller
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly MediaService $media,
    ) {}

    /**
     * List the messages of a conversation.
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $messages = $this->messages->paginate($conversation, [
            'before_id' => $request->integer('before_id') ?: null,
            'after_id' => $request->integer('after_id') ?: null,
            'limit' => $request->integer('limit', 50),
            'viewer' => $request->user(),
        ]);

        return response()->json([
            'data' => MessageResource::collection($messages),
            'meta' => [
                'count' => $messages->count(),
                'has_more' => $messages->count() >= min(max($request->integer('limit', 50), 1), 100),
            ],
        ]);
    }

    /**
     * Send a new message.
     */
    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('post', $conversation);

        $attachment = null;
        $mediaAsset = null;
        $type = $request->enum('type', MessageType::class) ?? MessageType::Text;

        if ($type === MessageType::ViewOnce) {
            $mediaAsset = $this->media->findAttachableEphemeral(
                (string) $request->input('media_uuid'),
                $request->user(),
                $conversation,
            );

            if ($mediaAsset === null) {
                return response()->json([
                    'message' => 'La imagen de un solo uso no es válida o ya se ha utilizado.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if ($request->hasFile('image')) {
            $asset = $this->media->storeAttachment($request->file('image'), $request->user(), $conversation->id);

            $attachment = [
                'path' => $asset->path,
                'mime' => $asset->mime,
                'size' => $asset->size,
                'width' => $asset->width,
                'height' => $asset->height,
            ];

            $type = MessageType::Image;
        }

        $replyToId = $request->integer('reply_to_id') ?: null;

        if ($replyToId !== null) {
            $replyTo = Message::query()->find($replyToId);

            if ($replyTo === null || $replyTo->conversation_id !== $conversation->id) {
                return response()->json([
                    'message' => 'El mensaje original no pertenece a esta conversación.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $message = $this->messages->create(
            $request->user(),
            $conversation,
            trim((string) $request->input('body', '')),
            $replyToId,
            $type,
            $attachment,
            $mediaAsset,
        );

        return response()->json([
            'message' => MessageResource::make($message),
        ], Response::HTTP_CREATED);
    }

    /**
     * Edit a message.
     */
    public function update(Request $request, Message $message): JsonResponse
    {
        $this->authorize('update', $message);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.MessageService::MAX_BODY_LENGTH],
        ], [
            'body.required' => 'El mensaje no puede quedar vacío.',
        ]);

        $message = $this->messages->edit($message, $request->user(), trim($validated['body']));

        return response()->json([
            'message' => MessageResource::make($message),
        ]);
    }

    /**
     * Delete a message.
     */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        $this->authorize('delete', $message);

        $this->messages->delete($message);

        return response()->json([
            'message' => 'Mensaje eliminado.',
        ]);
    }

    /**
     * Add or remove an emoji reaction.
     */
    public function toggleReaction(Request $request, Message $message): JsonResponse
    {
        $this->authorize('react', $message);

        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:32'],
        ], [
            'emoji.required' => 'Indica un emoji.',
            'emoji.max' => 'El emoji es demasiado largo.',
        ]);

        $active = $this->messages->toggleReaction($message, $request->user(), $validated['emoji']);

        return response()->json([
            'active' => $active,
            'emoji' => $validated['emoji'],
        ]);
    }

    /**
     * Mark the conversation as read.
     */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $count = $this->messages->markAsRead(
            $conversation,
            $request->user(),
            $request->integer('message_id') ?: null,
        );

        return response()->json([
            'message' => 'Conversación marcada como leída.',
            'marked' => $count,
        ]);
    }
}
