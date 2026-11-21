<?php

namespace App\Services;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates and resolves conversations.
 *
 * The global room is a singleton created on demand; direct conversations are
 * keyed by the ordered pair of participants so the same pair never produces
 * duplicates.
 */
class ConversationService
{
    /**
     * The shared public room.
     */
    public const GLOBAL = 'global';

    /**
     * Get (or create) the global conversation.
     */
    public function globalConversation(): Conversation
    {
        return Conversation::query()->firstOrCreate(
            ['type' => ConversationType::Global],
            ['title' => 'Chat General', 'last_message_at' => now()],
        );
    }

    /**
     * Find (or create) the direct conversation between two users.
     */
    public function directConversation(User $a, User $b): Conversation
    {
        [$low, $high] = $this->orderParticipants($a->id, $b->id);

        $existing = $this->findDirectConversation($low, $high);

        if ($existing !== null) {
            $this->ensureMembership($existing, [$a->id, $b->id]);

            return $existing;
        }

        return DB::transaction(function () use ($a, $b): Conversation {
            $conversation = Conversation::create([
                'type' => ConversationType::Direct,
                'last_message_at' => now(),
            ]);

            $conversation->users()->attach([
                $a->id => ['last_read_at' => now(), 'unread_count' => 0],
                $b->id => ['last_read_at' => now(), 'unread_count' => 0],
            ]);

            return $conversation;
        });
    }

    /**
     * Look up an existing direct conversation without creating one.
     */
    public function findDirectConversation(int $a, int $b): ?Conversation
    {
        [$low, $high] = $this->orderParticipants($a, $b);

        return Conversation::query()
            ->where('type', ConversationType::Direct)
            ->whereHas('users', fn ($query) => $query->where('users.id', $low))
            ->whereHas('users', fn ($query) => $query->where('users.id', $high))
            // Exactly two participants. A portable subquery is used instead of
            // HAVING because SQLite (the test driver) rejects HAVING on a
            // query without GROUP BY.
            ->whereRaw(
                '(select count(*) from conversation_user where conversation_user.conversation_id = conversations.id) = 2'
            )
            ->first();
    }

    /**
     * Whether a user belongs to a conversation.
     */
    public function userParticipates(User $user, Conversation $conversation): bool
    {
        if ($conversation->isGlobal()) {
            return true;
        }

        return $conversation->users()->where('users.id', $user->id)->exists();
    }

    /**
     * Ensure the given users are attached to the conversation.
     *
     * @param  array<int, int>  $userIds
     */
    public function ensureMembership(Conversation $conversation, array $userIds): void
    {
        $existing = $conversation->users()->pluck('users.id')->all();

        $missing = array_values(array_diff($userIds, $existing));

        if ($missing === []) {
            return;
        }

        $attachments = [];

        foreach ($missing as $userId) {
            $attachments[$userId] = ['last_read_at' => now(), 'unread_count' => 0];
        }

        $conversation->users()->attach($attachments);
    }

    /**
     * The conversations a user takes part in, newest activity first.
     *
     * @return Collection<int, Conversation>
     */
    public function forUser(User $user)
    {
        return Conversation::query()
            ->whereHas('users', fn ($query) => $query->where('users.id', $user->id))
            ->with(['users' => fn ($query) => $query->select('users.id', 'users.nickname', 'users.avatar_path', 'users.status', 'users.last_seen_at')])
            ->withCount('users')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Order two participant ids so the pair is always stored the same way.
     *
     * @return array{0: int, 1: int}
     */
    private function orderParticipants(int $a, int $b): array
    {
        return $a <= $b ? [$a, $b] : [$b, $a];
    }
}
