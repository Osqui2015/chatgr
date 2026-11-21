<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lists conversations and opens direct ones.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
    ) {}

    /**
     * List every conversation the user takes part in.
     */
    public function index(Request $request): JsonResponse
    {
        $conversations = $this->conversations->forUser($request->user());

        return response()->json([
            'data' => ConversationResource::collection($conversations),
        ]);
    }

    /**
     * Show a single conversation.
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $conversation->load('users');

        return response()->json([
            'data' => ConversationResource::make($conversation),
        ]);
    }

    /**
     * Open (or reuse) a direct conversation with another user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ], [
            'user_id.required' => 'Indica el usuario con el que quieres conversar.',
            'user_id.exists' => 'El usuario no existe.',
        ]);

        $target = User::query()->findOrFail($validated['user_id']);

        if ($target->id === $request->user()->id) {
            return response()->json([
                'message' => 'No puedes abrir una conversación contigo mismo.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $conversation = $this->conversations->directConversation($request->user(), $target);
        $conversation->load('users');

        return response()->json([
            'data' => ConversationResource::make($conversation),
        ], Response::HTTP_CREATED);
    }

    /**
     * Make sure the global conversation exists and is returned.
     */
    public function global(Request $request): JsonResponse
    {
        $conversation = $this->conversations->globalConversation();

        $this->conversations->ensureMembership($conversation, [$request->user()->id]);
        $conversation->load('users');

        return response()->json([
            'data' => ConversationResource::make($conversation),
        ]);
    }
}
