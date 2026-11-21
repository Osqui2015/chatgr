<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\MediaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Removes an ephemeral media asset and its file from disk.
 *
 * The job is dispatched when the asset is consumed and again as an expiry
 * safety net. The safety net only deletes assets that were never opened and
 * whose lifetime has really elapsed, so a misconfigured queue can never
 * destroy an image that has not expired yet.
 */
class DestroyEphemeralMediaJob implements ShouldQueue
{
    use Queueable;

    /**
     * Number of attempts before the job is considered failed.
     */
    public int $tries = 3;

    /**
     * Seconds before the job may be retried.
     */
    public int $backoff = 10;

    /**
     * Whether the job may delete an asset that has not been consumed.
     */
    public function __construct(
        public readonly int $mediaAssetId,
        public readonly bool $force = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(MediaService $media): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if ($asset === null) {
            return;
        }

        // A consumed asset is always removed. An unconsumed one is only
        // removed once it has genuinely expired.
        if (! $this->force && $asset->consumed_at === null && $asset->isAvailable()) {
            return;
        }

        $media->destroy($asset);
    }

    /**
     * Handle a failed attempt.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to destroy ephemeral media asset.', [
            'media_asset_id' => $this->mediaAssetId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
