<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Builds public URLs for stored media.
 *
 * Kept separate from {@see MediaService} so resources and views can resolve
 * URLs without pulling in the full media lifecycle.
 */
class MediaUrlService
{
    /**
     * The avatar sizes that are generated on upload.
     *
     * @var array<int, int>
     */
    public const AVATAR_SIZES = [32, 64, 128, 512];

    /**
     * The URL of an avatar variant.
     */
    public function avatarUrl(?string $path, int $size = 128): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $directory = $this->directoryOf($path);
        $filename = "{$size}.webp";

        return Storage::disk('public')->url("{$directory}/{$filename}");
    }

    /**
     * Every generated avatar variant for a user.
     *
     * The keys are the variant edge lengths as strings, matching the JSON
     * payload the API returns.
     *
     * @return array<int|string, string|null>
     */
    public function avatarSizes(?string $path): array
    {
        $sizes = [];

        foreach (self::AVATAR_SIZES as $size) {
            $sizes[(string) $size] = $this->avatarUrl($path, $size);
        }

        return $sizes;
    }

    /**
     * The URL of a permanent attachment.
     */
    public function attachmentUrl(string $path, string $disk = 'public'): string
    {
        return Storage::disk($disk)->url($path);
    }

    /**
     * The authorized URL of a view-once asset linked to a message.
     */
    public function ephemeralAssetUrl(string $uuid): string
    {
        return route('api.media.ephemeral.asset', ['uuid' => $uuid]);
    }

    /**
     * The directory that contains the given file.
     */
    private function directoryOf(string $path): string
    {
        $position = strrpos($path, '/');

        return $position === false ? '' : substr($path, 0, $position);
    }
}
