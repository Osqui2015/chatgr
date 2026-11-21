<?php

namespace App\Services;

use App\Models\User;

/**
 * A small, container-resolved context that resources use to build
 * presentation data without reaching into the service container themselves.
 *
 * Resources are serialised in many places (controllers, events, collections),
 * so a single shared instance keeps the dependency explicit and cheap.
 */
class ResourceContext
{
    public function __construct(
        private readonly MediaUrlService $urls,
        private readonly PresenceService $presence,
    ) {}

    /**
     * The avatar URL for a user.
     */
    public function avatarUrl(?User $user, int $size = 128): ?string
    {
        return $this->urls->avatarUrl($user?->avatar_path, $size);
    }

    /**
     * Every avatar variant for a user.
     *
     * @return array<string, string|null>
     */
    public function avatarSizes(?User $user): array
    {
        return $this->urls->avatarSizes($user?->avatar_path);
    }

    /**
     * The observable presence status of a user.
     */
    public function status(User $user): string
    {
        return $this->presence->effectiveStatus($user)->value;
    }

    /**
     * The public URL of a permanent attachment.
     */
    public function attachmentUrl(?string $path): ?string
    {
        return $path === null ? null : $this->urls->attachmentUrl($path);
    }

    /**
     * The authorized URL of a view-once asset.
     */
    public function ephemeralAssetUrl(string $uuid): string
    {
        return $this->urls->ephemeralAssetUrl($uuid);
    }
}
