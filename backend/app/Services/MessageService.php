<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Events\MessageDeleted;
use App\Events\MessageReactionAdded;
use App\Events\MessageReactionRemoved;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Models\Conversation;
use App\Models\MediaAsset;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the message lifecycle: creation, editing, deletion, reactions and
 * read receipts.
 */
class MessageService
{
    /**
     * Maximum length of a message body.
     */
    public const MAX_BODY_LENGTH = 4000;

    public function __construct(private readonly ModerationService $moderation) {}

    /**
     * Create a message and broadcast it.
     */
    public function create(
        User $author,
        Conversation $conversation,
        string $body,
        ?int $replyToId = null,
        MessageType $type = MessageType::Text,
        ?array $attachment = null,
        ?MediaAsset $mediaAsset = null,
    ): Message {
        $message = DB::transaction(function () use ($author, $conversation, $body, $replyToId, $type, $attachment, $mediaAsset): Message {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $author->id,
                'reply_to_id' => $replyToId,
                'body' => $body,
                'type' => $type,
                'attachment_path' => $attachment['path'] ?? null,
                'attachment_mime' => $attachment['mime'] ?? null,
                'attachment_size' => $attachment['size'] ?? null,
                'attachment_width' => $attachment['width'] ?? null,
                'attachment_height' => $attachment['height'] ?? null,
                'media_asset_id' => $mediaAsset?->id,
                'edit_token' => bin2hex(random_bytes(16)),
            ]);

            if ($mediaAsset !== null) {
                // Once the image belongs to a message the upload token is
                // useless: the recipient opens it through the authorized
                // message URL and the author is never allowed to redeem it.
                $mediaAsset->forceFill(['token_hash' => null])->save();
            }

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            $this->incrementUnreadForOthers($conversation, $author);

            return $message;
        });

        $message->loadMissing(['user', 'replyTo.user', 'reactions.user', 'mediaAsset']);

        MessageSent::dispatch($message);

        return $message;
    }

    /**
     * Edit the body of a message.
     *
     * Only the author may edit their own message, and only within the allowed
     * window.
     */
    public function edit(Message $message, User $actor, string $body): Message
    {
        $message->body = $body;
        $message->edited_at = Carbon::now();
        $message->save();

        $message->loadMissing(['user', 'replyTo.user', 'reactions.user']);

        MessageUpdated::dispatch($message);

        return $message;
    }

    /**
     * Soft delete a message.
     */
    public function delete(Message $message): void
    {
        $conversationId = $message->conversation_id;
        $messageId = $message->id;

        $message->delete();

        MessageDeleted::dispatch($messageId, $conversationId);
    }

    /**
     * Toggle an emoji reaction on a message.
     *
     * @return bool Whether the reaction is present after the call.
     */
    public function toggleReaction(Message $message, User $user, string $emoji): bool
    {
        $existing = MessageReaction::query()
            ->where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            MessageReactionRemoved::dispatch($message->id, $message->conversation_id, $user->id, $emoji);

            return false;
        }

        MessageReaction::create([
            'message_id' => $message->id,
            'user_id' => $user->id,
            'emoji' => $emoji,
        ]);

        MessageReactionAdded::dispatch($message->id, $message->conversation_id, $user->id, $emoji);

        return true;
    }

    /**
     * Load a page of messages, oldest first.
     *
     * @param  array{before_id?: int, after_id?: int, limit?: int}  $options
     * @return Collection<int, Message>
     */
    public function paginate(Conversation $conversation, array $options = [])
    {
        $limit = min(max((int) ($options['limit'] ?? 50), 1), 100);
        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with([
                'user:id,nickname,avatar_path,status,role,sexual_role,last_seen_at',
                'replyTo:id,body,type,user_id,deleted_at,user:id,nickname',
                'mediaAsset:id,uuid,kind,width,height,expires_at,consumed_at',
            ])
            ->withCount('reactions')
            ->orderByDesc('id');

        if (($options['viewer'] ?? null) instanceof User) {
            $hidden = array_unique([
                ...$this->moderation->mutedUserIds($options['viewer']),
                ...$this->moderation->blockedUserIds($options['viewer']),
            ]);

            if ($hidden !== []) {
                $query->whereNotIn('user_id', $hidden);
            }
        }

        if (isset($options['before_id'])) {
            $query->where('id', '<', (int) $options['before_id']);
        }

        if (isset($options['after_id'])) {
            $query->where('id', '>', (int) $options['after_id']);
        }

        return $query->limit($limit)->get()->sortBy('id')->values();
    }

    /**
     * Search messages inside a conversation.
     *
     * @return Collection<int, Message>
     */
    public function search(Conversation $conversation, string $term, int $limit = 50)
    {
        $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('deleted_at')
            ->where('body', 'like', $needle)
            ->with(['user:id,nickname,avatar_path'])
            ->orderByDesc('id')
            ->limit(min(max($limit, 1), 100))
            ->get();
    }

    /**
     * Mark every message up to a point as read for a user.
     */
    public function markAsRead(Conversation $conversation, User $user, ?int $upToMessageId = null): int
    {
        $latestId = $upToMessageId ?? (int) Message::query()
            ->where('conversation_id', $conversation->id)
            ->max('id');

        if ($latestId === 0) {
            return 0;
        }

        $affected = DB::table('message_reads')->insertOrIgnore([
            [
                'message_id' => $latestId,
                'user_id' => $user->id,
                'read_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);

        $conversation->users()->updateExistingPivot($user->id, [
            'last_read_at' => Carbon::now(),
            'unread_count' => 0,
        ]);

        return $affected;
    }

    /**
     * Bump the unread counter of every participant except the author.
     */
    private function incrementUnreadForOthers(Conversation $conversation, User $author): void
    {
        DB::table('conversation_user')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $author->id)
            ->increment('unread_count');
    }
}
