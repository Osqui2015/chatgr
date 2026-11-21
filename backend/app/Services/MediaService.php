<?php

namespace App\Services;

use App\Jobs\DestroyEphemeralMediaJob;
use App\Models\Conversation;
use App\Models\MediaAsset;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores chat media and implements the "view once" lifecycle.
 *
 * Ephemeral assets are handed out through a single-use token. When the token is
 * redeemed the file is served once and a queued job removes both the file and
 * the database row, so the image can never be retrieved again.
 */
class MediaService
{
    /**
     * How long a view-once token stays valid before it expires unused.
     */
    public const EPHEMERAL_TTL_HOURS = 24;

    /**
     * The token presented to the recipient of a view-once image.
     */
    public const TOKEN_BYTES = 32;

    public function __construct(private readonly ImageService $images) {}

    /**
     * Store a permanent chat attachment.
     */
    public function storeAttachment(UploadedFile $file, User $user, ?int $conversationId = null): MediaAsset
    {
        $clean = $this->images->sanitize($file);
        $webp = $this->images->toWebp($clean);
        [$width, $height] = $this->images->dimensions($webp);

        $uuid = MediaAsset::newUuid();
        $path = "media/{$user->id}/{$uuid}.webp";

        Storage::disk('public')->put($path, $webp);

        return MediaAsset::create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'uuid' => $uuid,
            'disk' => 'public',
            'path' => $path,
            'mime' => 'image/webp',
            'size' => strlen($webp),
            'width' => $width,
            'height' => $height,
            'kind' => 'attachment',
        ]);
    }

    /**
     * Store a view-once image and return the asset together with its
     * single-use token.
     *
     * @return array{0: MediaAsset, 1: string}
     */
    public function storeEphemeral(UploadedFile $file, User $user, ?int $conversationId = null): array
    {
        $clean = $this->images->sanitize($file);
        $webp = $this->images->toWebp($clean);
        [$width, $height] = $this->images->dimensions($webp);

        $uuid = MediaAsset::newUuid();
        $path = "ephemeral/{$user->id}/{$uuid}.webp";
        $token = $this->generateToken();

        Storage::disk('public')->put($path, $webp);

        $asset = MediaAsset::create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'uuid' => $uuid,
            'disk' => 'public',
            'path' => $path,
            'mime' => 'image/webp',
            'size' => strlen($webp),
            'width' => $width,
            'height' => $height,
            'kind' => 'ephemeral',
            'token_hash' => $this->hashToken($token),
            'expires_at' => Carbon::now()->addHours(self::EPHEMERAL_TTL_HOURS),
        ]);

        // Safety net: even if nobody ever opens the image the file is removed
        // once its lifetime has elapsed.
        DestroyEphemeralMediaJob::dispatch($asset->id)->delay(
            Carbon::now()->addHours(self::EPHEMERAL_TTL_HOURS)
        );

        return [$asset, $token];
    }

    /**
     * Redeem a view-once token.
     *
     * The token is consumed atomically: a second attempt with the same token
     * always fails, even under concurrent requests.
     *
     * @return array{0: ?MediaAsset, 1: ?string} The asset and its binary content.
     */
    public function redeemEphemeral(string $token): array
    {
        $asset = MediaAsset::query()
            ->where('kind', 'ephemeral')
            ->where('token_hash', $this->hashToken($token))
            ->first();

        if ($asset === null) {
            return [null, null];
        }

        $binary = $this->consume($asset);

        return $binary === null ? [null, null] : [$asset, $binary];
    }

    /**
     * Resolve the ephemeral asset a sender may attach to a message.
     *
     * The asset must be theirs, still available, uploaded for the same
     * conversation and not attached to another message yet.
     */
    public function findAttachableEphemeral(string $uuid, User $user, Conversation $conversation): ?MediaAsset
    {
        $asset = MediaAsset::query()
            ->where('uuid', $uuid)
            ->where('kind', 'ephemeral')
            ->where('user_id', $user->id)
            ->first();

        if ($asset === null || ! $asset->isAvailable()) {
            return null;
        }

        if ($asset->conversation_id !== null && $asset->conversation_id !== $conversation->id) {
            return null;
        }

        if (Message::query()->where('media_asset_id', $asset->id)->exists()) {
            return null;
        }

        return $asset;
    }

    /**
     * Consume an ephemeral asset and return its binary content.
     *
     * The row is locked while it is marked as consumed, so two concurrent
     * requests can never both receive the image. The file is removed as soon
     * as it has been read and the queued job cleans up the row.
     */
    public function consume(MediaAsset $asset): ?string
    {
        $consumed = DB::transaction(function () use ($asset): ?MediaAsset {
            $locked = MediaAsset::query()
                ->whereKey($asset->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->isAvailable()) {
                return null;
            }

            $locked->forceFill([
                'consumed_at' => Carbon::now(),
                'view_count' => $locked->view_count + 1,
            ])->save();

            return $locked;
        });

        if ($consumed === null) {
            return null;
        }

        $disk = Storage::disk($consumed->disk);

        if (! $disk->exists($consumed->path)) {
            // The file is gone; drop the orphaned row as well.
            $consumed->delete();

            return null;
        }

        $binary = $disk->get($consumed->path);

        $disk->delete($consumed->path);
        DestroyEphemeralMediaJob::dispatch($consumed->id, force: true);

        return $binary;
    }

    /**
     * Remove an asset and its file.
     */
    public function destroy(MediaAsset $asset): void
    {
        Storage::disk($asset->disk)->delete($asset->path);
        $asset->delete();
    }

    /**
     * A cryptographically random single-use token.
     */
    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    /**
     * Tokens are stored hashed so a database leak cannot be replayed.
     */
    private function hashToken(string $token): string
    {
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;

        return hash_hmac('sha256', $token, $key);
    }

    /**
     * The public URL of a permanent attachment.
     */
    public function publicUrl(MediaAsset $asset): string
    {
        return Storage::disk($asset->disk)->url($asset->path);
    }

    /**
     * The storage path of an avatar variant.
     */
    public function avatarUrl(?string $path, int $size = 128): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $directory = Str::beforeLast($path, '/');
        $filename = "{$size}.webp";

        return Storage::disk('public')->url("{$directory}/{$filename}");
    }
}
