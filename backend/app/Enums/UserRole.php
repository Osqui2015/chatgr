<?php

namespace App\Enums;

/**
 * Role-based authorisation levels, ordered from least to most privileged.
 */
enum UserRole: string
{
    case User = 'user';
    case Moderator = 'moderator';
    case Admin = 'admin';

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    public function isModerator(): bool
    {
        return $this === self::Moderator || $this === self::Admin;
    }

    /**
     * Moderators and admins can act on any user.
     */
    public function outranks(self $other): bool
    {
        return $this->outranksValue($this) > $this->outranksValue($other);
    }

    private function outranksValue(self $role): int
    {
        return match ($role) {
            self::Admin => 3,
            self::Moderator => 2,
            self::User => 1,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
