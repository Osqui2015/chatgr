<?php

namespace App\Http\Controllers\Api;

use App\Events\TypingIndicator;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Broadcasts the typing indicator of a conversation.
 *
 * The event travels through the websocket server, so every participant of the
 * conversation receives it without polling the database.
 */
class TypingController extends Controller
{
    /**
     * Tell the other participants that the user is typing.
     */
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $validated = $request->validate([
            'is_typing' => ['required', 'boolean'],
        ], [
            'is_typing.required' => 'Indica si el usuario está escribiendo.',
            'is_typing.boolean' => 'El valor debe ser verdadero o falso.',
        ]);

        TypingIndicator::dispatch(
            $conversation->id,
            $request->user()->id,
            $request->user()->nickname,
            $validated['is_typing'],
        );

        return response()->json([
            'message' => 'Indicador actualizado.',
        ]);
    }
}
