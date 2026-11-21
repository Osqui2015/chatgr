<?php

namespace App\Services;

use RuntimeException;

/**
 * Raised when an OTP is requested before the resend cooldown has elapsed.
 */
class OtpCooldownException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Please wait before requesting another verification code.');
    }
}
