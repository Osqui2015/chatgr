<?php

namespace App\Enums;

/**
 * Why a login attempt was recorded.
 */
enum LoginReason: string
{
    case Success = 'success';
    case InvalidCredentials = 'invalid_credentials';
    case UnknownDevice = 'unknown_device';
    case OtpRequired = 'otp_required';
    case OtpFailed = 'otp_failed';
    case AccountBanned = 'account_banned';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
