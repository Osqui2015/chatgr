<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * The pivot row that links a user to a conversation.
 *
 * @property int $conversation_id
 * @property int $user_id
 * @property Carbon|null $last_read_at
 * @property int $unread_count
 */
class ConversationUser extends Pivot
{
    /**
     * The table associated with the pivot model.
     */
    protected $table = 'conversation_user';

    /**
     * The attributes of the pivot that are cast to objects.
     *
     * Without these casts the dates arrive as strings, which breaks the
     * resources that serialise them.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_read_at' => 'datetime',
        'unread_count' => 'integer',
        'conversation_id' => 'integer',
        'user_id' => 'integer',
    ];

    /**
     * The pivot row is updated in place instead of being replaced, so the
     * read cursor and the unread counter survive every attach.
     */
    public $incrementing = false;
}
