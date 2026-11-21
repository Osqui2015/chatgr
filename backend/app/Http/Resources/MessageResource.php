<?php

namespace App\Http\Resources;

use App\Enums\MessageType;
use App\Models\MediaAsset;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use App\Services\ResourceContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a chat message.
 *
 * @mixin Message
 *
 * @property-read User|null $user
 * @property-read MediaAsset|null $mediaAsset
 * @property-read Message|null $replyTo
 * @property-read Collection<int, MessageReaction> $reactions
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ResourceContext $context */
        $context = app(ResourceContext::class);

        $isDeleted = $this->deleted_at !== null;

        $viewOnce = null;

        if ($this->type === MessageType::ViewOnce) {
            $asset = $this->relationLoaded('mediaAsset')
                ? $this->mediaAsset
                : $this->mediaAsset()->first();

            $viewer = $request->user();

            $available = ! $isDeleted && $asset !== null && $asset->isAvailable();
            $isAuthor = $viewer !== null && $viewer->id === $this->user_id;

            $viewOnce = [
                'available' => $available,
                'opened' => $asset === null || $asset->consumed_at !== null,
                'url' => $available && ! $isAuthor && $asset !== null
                    ? $context->ephemeralAssetUrl($asset->uuid)
                    : null,
                'width' => $asset?->width,
                'height' => $asset?->height,
            ];
        }

        $reactions = $this->relationLoaded('reactions')
            ? $this->reactions
                ->groupBy('emoji')
                ->map(fn ($group, $emoji) => [
                    'emoji' => $emoji,
                    'count' => $group->count(),
                    'user_ids' => $group->pluck('user_id')->values(),
                ])
                ->values()
                ->all()
            : [];

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user' => $this->whenLoaded('user', fn () => UserResource::make($this->user)),
            'reply_to' => $this->whenLoaded('replyTo', fn () => $this->replyTo === null ? null : [
                'id' => $this->replyTo->id,
                'body' => $this->replyTo->deleted_at !== null
                    ? null
                    : ($this->replyTo->type === MessageType::ViewOnce
                        ? 'Imagen de un solo uso'
                        : $this->replyTo->body),
                'user_id' => $this->replyTo->user_id,
                'user' => $this->replyTo->relationLoaded('user') && $this->replyTo->user !== null
                    ? UserResource::make($this->replyTo->user)
                    : null,
            ]),
            'body' => $isDeleted ? null : $this->body,
            'type' => $this->type->value,
            'attachment' => $this->attachment_path === null ? null : [
                'url' => $context->attachmentUrl($this->attachment_path),
                'mime' => $this->attachment_mime,
                'size' => $this->attachment_size,
                'width' => $this->attachment_width,
                'height' => $this->attachment_height,
            ],
            'view_once' => $viewOnce,
            'reactions' => $reactions,
            'edited_at' => $this->edited_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'is_deleted' => $isDeleted,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
