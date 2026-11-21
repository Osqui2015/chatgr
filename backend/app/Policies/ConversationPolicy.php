<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Decides who may read and write in a conversation.
 */
class ConversationPolicy
{
    /**
     * Participants may view a conversation.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        if ($conversation->isGlobal()) {
            return $user->email_verified_at !== null;
        }

        return $conversation->users()->where('users.id', $user->id)->exists();
    }

    /**
     * Participants may post in a conversation.
     */
    public function post(User $user, Conversation $conversation): bool
    {
        if ($user->is_muted) {
            return false;
        }

        if ($user->is_banned) {
            return false;
        }

        return $this->view($user, $conversation);
    }
}
