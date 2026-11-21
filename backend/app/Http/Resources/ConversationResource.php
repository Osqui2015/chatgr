<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Models\User;
use App\Services\ResourceContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a conversation.
 *
 * @mixin Conversation
 *
 * @property-read Collection<int, User> $users
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ResourceContext $context */
        $context = app(ResourceContext::class);

        $requestUser = $request->user();

        $pivot = $this->relationLoaded('users')
            ? $this->users->firstWhere('id', $requestUser?->id)?->pivot
            : null;

        $participants = $this->relationLoaded('users')
            ? $this->users->map(fn ($user) => [
                'id' => $user->id,
                'nickname' => $user->nickname,
                'avatar_url' => $context->avatarUrl($user, 64),
                'status' => $user->status->value,
                'sexual_role' => $user->sexual_role?->value,
                'unread_count' => (int) ($user->pivot->unread_count ?? 0),
            ])->values()
            : [];

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'participants' => $participants,
            'participant_count' => $this->users_count ?? $participants->count(),
            'unread_count' => (int) ($pivot->unread_count ?? 0),
            'last_read_at' => $pivot?->last_read_at?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
