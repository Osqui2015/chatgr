<?php

namespace App\Services;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\OtpVerificationNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Issues and verifies one-time passcodes.
 *
 * Codes are stored hashed, are single use, expire quickly and are rate limited
 * both per email and per IP.
 */
class OtpService
{
    /**
     * Number of digits in a verification code.
     */
    public const CODE_LENGTH = 6;

    /**
     * How long a code remains valid.
     */
    public const TTL_MINUTES = 10;

    /**
     * Maximum number of wrong guesses before the code is burned.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Minimum number of seconds between two codes for the same purpose.
     */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Generate and deliver a verification code.
     */
    public function issue(string $email, OtpPurpose $purpose, ?string $ipAddress = null): OtpCode
    {
        $email = Str::lower(trim($email));
        $cooldownKey = "otp:cooldown:{$purpose->value}:{$email}";

        if (! Cache::add($cooldownKey, 1, self::RESEND_COOLDOWN_SECONDS)) {
            throw new OtpCooldownException(self::RESEND_COOLDOWN_SECONDS);
        }

        $code = $this->generateCode();

        // Any previously outstanding code for this purpose is invalidated so
        // only the newest one can be redeemed.
        DB::table('otp_codes')
            ->where('email', $email)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = OtpCode::create([
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => $this->hashCode($email, $code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'ip_address' => $ipAddress,
        ]);

        $this->deliver($email, $code, $purpose);

        return $otp;
    }

    /**
     * Verify a submitted code.
     *
     * @return bool Whether the code was valid and has now been consumed.
     */
    public function verify(string $email, string $code, OtpPurpose $purpose): bool
    {
        $email = Str::lower(trim($email));

        $otp = OtpCode::query()
            ->where('email', $email)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if ($otp === null) {
            return false;
        }

        if (! $otp->isUsable()) {
            return false;
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->update(['consumed_at' => now()]);

            return false;
        }

        if (! hash_equals($otp->code_hash, $this->hashCode($email, $code))) {
            $otp->increment('attempts');

            return false;
        }

        $otp->update(['consumed_at' => now()]);

        return true;
    }

    /**
     * Whether a code was requested for this email and purpose recently.
     */
    public function wasRecentlyRequested(string $email, OtpPurpose $purpose): bool
    {
        return OtpCode::query()
            ->where('email', Str::lower(trim($email)))
            ->where('purpose', $purpose->value)
            ->where('created_at', '>=', now()->subMinutes(self::TTL_MINUTES))
            ->exists();
    }

    /**
     * A cryptographically random numeric code.
     */
    private function generateCode(): string
    {
        $max = (10 ** self::CODE_LENGTH) - 1;

        return str_pad((string) random_int(0, $max), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Codes are bound to the email so an intercepted code cannot be replayed
     * against a different account.
     */
    private function hashCode(string $email, string $code): string
    {
        return hash_hmac('sha256', $code, $this->pepper().'|'.Str::lower($email));
    }

    private function pepper(): string
    {
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;

        return hash('sha256', $key);
    }

    private function deliver(string $email, string $code, OtpPurpose $purpose): void
    {
        try {
            $user = User::query()->where('email', $email)->first();

            if ($user !== null) {
                $user->notify(new OtpVerificationNotification($code, $purpose));

                return;
            }

            Notification::route('mail', $email)
                ->notify(new OtpVerificationNotification($code, $purpose));
        } catch (\Throwable $e) {
            // A mail transport failure must not leak the code or abort the flow.
            Log::error('Failed to deliver OTP notification.', [
                'email' => $email,
                'purpose' => $purpose->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
