<?php

namespace App\Enums;

/**
 * Presence states a user can expose to other participants.
 */
enum UserStatus: string
{
    case Online = 'online';
    case Away = 'away';
    case Busy = 'busy';
    case Invisible = 'invisible';
    case Offline = 'offline';

    /**
     * Statuses that make the user appear online to other users.
     */
    public function isOnline(): bool
    {
        return $this !== self::Offline;
    }

    /**
     * Statuses that are reachable for direct messages.
     */
    public function isReachable(): bool
    {
        return $this === self::Online || $this === self::Away || $this === self::Busy;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
