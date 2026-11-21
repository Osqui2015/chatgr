<?php

namespace App\Models;

use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A stored media file.
 *
 * `kind = ephemeral` implements the "view once" flow: the asset carries a
 * single-use token, is never rendered in message history, and is destroyed
 * (file + row) once it has been consumed.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $conversation_id
 * @property string $uuid
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string $kind
 * @property string|null $token_hash
 * @property Carbon|null $expires_at
 * @property Carbon|null $consumed_at
 * @property int $view_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'conversation_id',
    'uuid',
    'disk',
    'path',
    'mime',
    'size',
    'width',
    'height',
    'kind',
    'token_hash',
    'expires_at',
    'consumed_at',
    'view_count',
])]
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'view_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Whether the asset is a one-time view image.
     */
    public function isEphemeral(): bool
    {
        return $this->kind === 'ephemeral';
    }

    /**
     * Whether the asset may still be served.
     */
    public function isAvailable(): bool
    {
        if ($this->consumed_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Generate a fresh random UUID for a new asset.
     */
    public static function newUuid(): string
    {
        return (string) Str::uuid();
    }
}
