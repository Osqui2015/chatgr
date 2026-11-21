<?php

namespace Tests;

use App\Notifications\OtpVerificationNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Helpers for asserting on the one-time codes the application delivers.
 */
trait InteractsWithOtpCodes
{
    /**
     * The last verification code that was delivered.
     *
     * @throws \RuntimeException When no code was sent.
     */
    protected function lastOtpCode(): string
    {
        $codes = [];

        foreach (Notification::sentNotifications() as $entry) {
            $this->collectCodes($entry, $codes);
        }

        if ($codes === []) {
            throw new \RuntimeException('No se entregó ningún código de verificación.');
        }

        return (string) end($codes);
    }

    /**
     * Walk a notification fake entry and collect every OTP it carries.
     *
     * The fake stores notifications under different shapes depending on the
     * recipient: a model recipient is keyed by the model class, an on-demand
     * recipient by the `AnonymousNotifiable` class. Both wrap the notification
     * in a nested array, so the structure is walked recursively.
     *
     * @param  array<int, mixed>  $codes
     */
    private function collectCodes(mixed $entry, array &$codes): void
    {
        if ($entry instanceof OtpVerificationNotification) {
            $codes[] = $entry->code;

            return;
        }

        if (! is_array($entry)) {
            return;
        }

        foreach ($entry as $value) {
            if ($value instanceof OtpVerificationNotification) {
                $codes[] = $value->code;

                continue;
            }

            if (is_array($value)) {
                $this->collectCodes($value, $codes);
            }
        }
    }
}
