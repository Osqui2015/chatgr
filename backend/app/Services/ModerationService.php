<?php

namespace App\Services;

use App\Models\Ban;
use App\Models\Block;
use App\Models\Message;
use App\Models\Mute;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Central place for every moderation action.
 *
 * Actions are separated from the controllers so they can be reused by console
 * commands, queued jobs or a future admin API without duplication.
 */
class ModerationService
{
    /**
     * Block a user for the given user.
     */
    public function block(User $blocker, User $blocked, ?string $reason = null): void
    {
        Block::query()->updateOrCreate(
            ['blocker_id' => $blocker->id, 'blocked_id' => $blocked->id],
            ['reason' => $reason],
        );
    }

    /**
     * Remove a block.
     */
    public function unblock(User $blocker, User $blocked): void
    {
        Block::query()
            ->where('blocker_id', $blocker->id)
            ->where('blocked_id', $blocked->id)
            ->delete();
    }

    /**
     * Mute a participant so their messages are hidden from the muting user.
     */
    public function mute(User $user, User $muted, ?Carbon $until = null): void
    {
        Mute::query()->updateOrCreate(
            ['user_id' => $user->id, 'muted_user_id' => $muted->id],
            ['muted_until' => $until],
        );
    }

    /**
     * Remove a mute.
     */
    public function unmute(User $user, User $muted): void
    {
        Mute::query()
            ->where('user_id', $user->id)
            ->where('muted_user_id', $muted->id)
            ->delete();
    }

    /**
     * The ids of the users muted by the given user.
     *
     * @return array<int, int>
     */
    public function mutedUserIds(User $user): array
    {
        return Mute::query()
            ->where('user_id', $user->id)
            ->where(function ($query): void {
                $query->whereNull('muted_until')->orWhere('muted_until', '>', Carbon::now());
            })
            ->pluck('muted_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The ids of the users blocked by the given user.
     *
     * @return array<int, int>
     */
    public function blockedUserIds(User $user): array
    {
        return Block::query()
            ->where('blocker_id', $user->id)
            ->pluck('blocked_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * File a report against a user or a message.
     */
    public function report(
        User $reporter,
        User $reported,
        string $reason,
        ?string $details = null,
        ?Message $message = null,
    ): Report {
        return Report::create([
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reported->id,
            'message_id' => $message?->id,
            'reason' => $reason,
            'details' => $details,
            'status' => 'pending',
        ]);
    }

    /**
     * Ban a user.
     */
    public function ban(User $moderator, User $target, ?string $reason = null, ?Carbon $expiresAt = null): Ban
    {
        $ban = Ban::create([
            'user_id' => $target->id,
            'banned_by' => $moderator->id,
            'reason' => $reason,
            'expires_at' => $expiresAt,
        ]);

        $target->forceFill(['is_banned' => true])->saveQuietly();

        return $ban;
    }

    /**
     * Lift a ban.
     */
    public function unban(User $moderator, User $target): void
    {
        Ban::query()
            ->where('user_id', $target->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);

        $target->forceFill(['is_banned' => false])->saveQuietly();
    }

    /**
     * Mark a report as handled.
     */
    public function resolveReport(User $moderator, Report $report, string $status): Report
    {
        $report->forceFill([
            'status' => $status,
            'resolved_by' => $moderator->id,
            'resolved_at' => Carbon::now(),
        ])->save();

        return $report;
    }
}
