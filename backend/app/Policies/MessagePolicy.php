<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

/**
 * Decides who may edit or delete a message.
 */
class MessagePolicy
{
    /**
     * The author may edit their own message.
     */
    public function update(User $user, Message $message): bool
    {
        return $user->id === $message->user_id;
    }

    /**
     * The author may delete their own message; moderators may delete any.
     */
    public function delete(User $user, Message $message): bool
    {
        return $user->id === $message->user_id || $user->role->isModerator();
    }

    /**
     * Reactions are open to every authenticated participant.
     */
    public function react(User $user, Message $message): bool
    {
        return $user->email_verified_at !== null;
    }
}
