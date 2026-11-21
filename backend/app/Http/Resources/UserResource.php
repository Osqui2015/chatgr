<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\ResourceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Attributes\PreserveKeys;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a user.
 *
 * @mixin User
 */
#[PreserveKeys]
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ResourceContext $context */
        $context = app(ResourceContext::class);

        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            'email' => $this->when(
                $request->user()?->id === $this->id,
                fn () => $this->email,
            ),
            'avatar_url' => $context->avatarUrl($this->resource, 128),
            'avatar_sizes' => $context->avatarSizes($this->resource),
            'status' => $context->status($this->resource),
            'sexual_role' => $this->sexual_role?->value,
            'sexual_role_label' => $this->sexual_role?->label(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'role' => $this->role->value,
            'is_muted' => $this->is_muted,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
