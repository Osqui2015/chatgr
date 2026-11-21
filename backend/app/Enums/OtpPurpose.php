<?php

namespace App\Enums;

/**
 * The reason a one-time verification code was requested.
 */
enum OtpPurpose: string
{
    case Registration = 'registration';
    case Login = 'login';
    case Sensitive = 'sensitive';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
