<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Tracks who is online and what their status is.
 *
 * State lives in Redis so that presence reads never touch MySQL and remain
 * correct across multiple web nodes.
 *
 * Every Redis interaction is guarded: if the cache is temporarily unavailable
 * the chat keeps working and falls back to the status stored in MySQL, because
 * presence is a hint and must never take the application down.
 */
class PresenceService
{
    /**
     * Redis key holding the set of connected user ids.
     */
    public const ONLINE_SET = 'presence:online';

    /**
     * Redis key template holding a user's status.
     */
    public const STATUS_KEY = 'presence:status:';

    /**
     * Redis key template holding a user's last seen timestamp.
     */
    public const LAST_SEEN_KEY = 'presence:last_seen:';

    /**
     * How long a presence entry survives without a heartbeat.
     */
    public const TTL_SECONDS = 120;

    /**
     * Register a user as connected.
     */
    public function markOnline(User $user, UserStatus $status = UserStatus::Online): void
    {
        $this->writeStatus($user, $status);

        $this->guard(function () use ($user): void {
            Redis::sadd(self::ONLINE_SET, (string) $user->id);
            Redis::expire(self::ONLINE_SET, self::TTL_SECONDS * 10);
        });

        $user->forceFill([
            'status' => $status,
            'last_seen_at' => Carbon::now(),
        ])->saveQuietly();
    }

    /**
     * Remove a user from the connected set.
     */
    public function markOffline(User $user): void
    {
        $this->guard(function () use ($user): void {
            Redis::srem(self::ONLINE_SET, (string) $user->id);
            Redis::del(self::STATUS_KEY.$user->id);
            Redis::setex(self::LAST_SEEN_KEY.$user->id, 86400, Carbon::now()->toIso8601String());
        });

        $user->forceFill([
            'status' => UserStatus::Offline,
            'last_seen_at' => Carbon::now(),
        ])->saveQuietly();
    }

    /**
     * Persist a status chosen by the user.
     */
    public function setStatus(User $user, UserStatus $status): void
    {
        $this->writeStatus($user, $status);

        $user->forceFill(['status' => $status])->saveQuietly();
    }

    /**
     * Refresh the TTL of an online entry (heartbeat).
     */
    public function heartbeat(User $user): void
    {
        $this->guard(function () use ($user): void {
            Redis::sadd(self::ONLINE_SET, (string) $user->id);
            Redis::expire(self::ONLINE_SET, self::TTL_SECONDS * 10);
            Redis::setex(self::STATUS_KEY.$user->id, self::TTL_SECONDS, $this->effectiveStatus($user)->value);
        });
    }

    /**
     * The status other participants should observe for this user.
     */
    public function effectiveStatus(User $user): UserStatus
    {
        if (! $this->isOnline($user)) {
            return UserStatus::Offline;
        }

        $raw = $this->guard(
            fn () => Redis::get(self::STATUS_KEY.$user->id),
            null,
        );

        if (! is_string($raw) || $raw === '') {
            return $user->status;
        }

        return UserStatus::tryFrom($raw) ?? $user->status;
    }

    /**
     * Whether the user currently holds a connection.
     */
    public function isOnline(User $user): bool
    {
        return (bool) $this->guard(
            fn () => Redis::sismember(self::ONLINE_SET, (string) $user->id),
            false,
        );
    }

    /**
     * The last time the user was seen, if known.
     */
    public function lastSeen(User $user): ?Carbon
    {
        $raw = $this->guard(
            fn () => Redis::get(self::LAST_SEEN_KEY.$user->id),
            null,
        );

        if (! is_string($raw) || $raw === '') {
            return $user->last_seen_at;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return $user->last_seen_at;
        }
    }

    /**
     * The ids of every connected user.
     *
     * The online set is only an index: a client can disappear without sending
     * a goodbye, so its id may outlive the per-user status key by up to
     * {@see self::TTL_SECONDS} * 10. The status key is the authoritative
     * liveness signal and stale members are dropped here.
     *
     * @return array<int, int>
     */
    public function onlineUserIds(): array
    {
        $members = $this->guard(fn () => Redis::smembers(self::ONLINE_SET), []);

        $ids = array_values(array_unique(array_filter(
            array_map('intval', is_array($members) ? $members : []),
        )));

        return array_values(array_filter(
            $ids,
            fn (int $id): bool => (bool) $this->guard(
                fn () => Redis::exists(self::STATUS_KEY.$id),
                false,
            ),
        ));
    }

    /**
     * The number of connected users.
     */
    public function onlineCount(): int
    {
        return count($this->onlineUserIds());
    }

    /**
     * Persist the status in Redis, taking the invisible flag into account.
     */
    private function writeStatus(User $user, UserStatus $status): void
    {
        $this->guard(function () use ($user, $status): void {
            Redis::setex(self::STATUS_KEY.$user->id, self::TTL_SECONDS, $status->value);
        });
    }

    /**
     * Run a Redis interaction, swallowing connection failures.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    private function guard(callable $callback, mixed $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::warning('Redis is unavailable for presence tracking.', [
                'exception' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }
}
