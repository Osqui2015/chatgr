<?php

namespace App\Models;

use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single chat message. Soft deleted rows keep the thread timeline stable
 * while hiding the body from every read path.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $user_id
 * @property int|null $reply_to_id
 * @property string $body
 * @property MessageType $type
 * @property string|null $attachment_path
 * @property string|null $attachment_mime
 * @property int|null $attachment_size
 * @property int|null $attachment_width
 * @property int|null $attachment_height
 * @property int|null $media_asset_id
 * @property string|null $edit_token
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Conversation|null $conversation
 * @property-read MediaAsset|null $mediaAsset
 * @property-read Message|null $replyTo
 * @property-read Collection<int, MessageReaction> $reactions
 */
#[Fillable([
    'conversation_id',
    'user_id',
    'reply_to_id',
    'body',
    'type',
    'attachment_path',
    'attachment_mime',
    'attachment_size',
    'attachment_width',
    'attachment_height',
    'media_asset_id',
    'edit_token',
    'edited_at',
])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
            'attachment_size' => 'integer',
            'attachment_width' => 'integer',
            'attachment_height' => 'integer',
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
     * The ephemeral asset behind a view-once image.
     *
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    /**
     * @return HasMany<MessageReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * @return HasMany<MessageRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(MessageRead::class);
    }

    /**
     * Limit a query to messages that still have a visible body.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('deleted_at');
    }
}
