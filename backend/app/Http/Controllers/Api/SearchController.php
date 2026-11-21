<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global search across users, messages and conversations.
 *
 * Results never include content the requester is not allowed to see: blocked
 * users and other people's private conversations are filtered out.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ModerationService $moderation,
    ) {}

    /**
     * Run a search across the three indexed scopes.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:64'],
            'scope' => ['nullable', 'string', 'in:all,users,messages,conversations'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ], [
            'q.required' => 'Escribe al menos 2 caracteres para buscar.',
            'q.min' => 'Escribe al menos 2 caracteres para buscar.',
        ]);

        $term = trim($validated['q']);
        $scope = $validated['scope'] ?? 'all';
        $limit = (int) ($validated['limit'] ?? 20);
        $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $user = $request->user();
        $blockedIds = $this->moderation->blockedUserIds($user);

        $result = [];

        if ($scope === 'all' || $scope === 'users') {
            $result['users'] = UserResource::collection(
                User::query()
                    ->where('id', '!=', $user->id)
                    ->whereNotIn('id', $blockedIds)
                    ->where('nickname', 'like', $needle)
                    ->orderBy('nickname')
                    ->limit($limit)
                    ->get()
            );
        }

        if ($scope === 'all' || $scope === 'messages') {
            /** @var array<int, int> $conversationIds */
            $conversationIds = $this->conversations->forUser($user)->pluck('id')->all();

            $messages = Message::query()
                ->whereIn('conversation_id', $conversationIds)
                ->whereNull('deleted_at')
                ->where('body', 'like', $needle)
                ->with(['user:id,nickname,avatar_path,status,role,last_seen_at', 'replyTo:id,body,user_id,deleted_at,user:id,nickname'])
                ->withCount('reactions')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            $result['messages'] = MessageResource::collection($messages);
        }

        if ($scope === 'all' || $scope === 'conversations') {
            $conversations = Conversation::query()
                ->whereHas('users', fn ($query) => $query->where('users.id', $user->id))
                ->where(function ($query) use ($needle): void {
                    $query->where('title', 'like', $needle)
                        ->orWhereHas('users', fn ($q) => $q->where('nickname', 'like', $needle));
                })
                ->with(['users:id,nickname,avatar_path,status,last_seen_at'])
                ->withCount('users')
                ->orderByDesc('last_message_at')
                ->limit($limit)
                ->get();

            $result['conversations'] = ConversationResource::collection($conversations);
        }

        return response()->json([
            'data' => $result,
            'meta' => [
                'query' => $term,
                'scope' => $scope,
            ],
        ]);
    }
}
