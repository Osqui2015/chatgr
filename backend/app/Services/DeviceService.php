<?php

namespace App\Services;

use App\Enums\LoginReason;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Detects and records the "known device" signal used to decide whether a
 * login must be challenged with a one-time code.
 */
class DeviceService
{
    /**
     * How long a device stays trusted after a successful verification.
     */
    public const TRUST_DURATION_DAYS = 30;

    /**
     * Build a stable fingerprint for the current client.
     *
     * The user agent is normalised and combined with a coarse client hint so
     * that trivial changes (patch versions, language) do not invalidate a
     * trusted device, while a genuinely different browser or OS does.
     */
    public function fingerprint(Request $request): string
    {
        $userAgent = (string) $request->userAgent();
        $clientHints = (string) $request->header('Sec-CH-UA-Platform', '');
        $acceptLanguage = Str::before((string) $request->header('Accept-Language', ''), ',');

        $material = implode('|', [
            $this->normaliseUserAgent($userAgent),
            $clientHints,
            $acceptLanguage,
        ]);

        return hash('sha256', $material);
    }

    /**
     * Resolve the device row for the request, creating it when unknown.
     */
    public function resolveDevice(Request $request, User $user): UserDevice
    {
        $hash = $this->fingerprint($request);

        /** @var UserDevice $device */
        $device = UserDevice::firstOrCreate(
            ['user_id' => $user->id, 'device_hash' => $hash],
            [
                'label' => $this->describeDevice($request),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ],
        );

        $device->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'last_used_at' => Carbon::now(),
        ])->save();

        return $device;
    }

    /**
     * Whether the request comes from a device the user has already vouched for.
     */
    public function isTrusted(Request $request, User $user): bool
    {
        $hash = $this->fingerprint($request);

        return UserDevice::query()
            ->where('user_id', $user->id)
            ->where('device_hash', $hash)
            ->where('trusted_until', '>', Carbon::now())
            ->exists();
    }

    /**
     * Mark the current device as trusted.
     */
    public function trustDevice(Request $request, User $user): UserDevice
    {
        $device = $this->resolveDevice($request, $user);
        $device->update(['trusted_until' => Carbon::now()->addDays(self::TRUST_DURATION_DAYS)]);

        return $device;
    }

    /**
     * Record an authentication attempt for auditing and abuse detection.
     */
    public function recordAttempt(
        Request $request,
        LoginReason $reason,
        bool $succeeded = false,
        ?User $user = null,
        ?string $email = null,
    ): LoginAttempt {
        return LoginAttempt::create([
            'user_id' => $user?->id,
            'email' => $email !== null ? Str::lower($email) : $user?->email,
            'ip_address' => (string) $request->ip(),
            'device_hash' => $this->fingerprint($request),
            'reason' => $reason->value,
            'succeeded' => $succeeded,
        ]);
    }

    /**
     * Whether the credentials pair match a user account.
     */
    public function credentialsAreValid(string $email, string $password): ?User
    {
        $user = User::query()->where('email', Str::lower(trim($email)))->first();

        if ($user === null) {
            // Keep the timing similar for unknown and known emails.
            Hash::make($password);

            return null;
        }

        return Hash::check($password, $user->password) ? $user : null;
    }

    /**
     * Strip version numbers so a browser update does not look like a new device.
     */
    private function normaliseUserAgent(string $userAgent): string
    {
        $normalised = preg_replace('/\s?\(?[^()]*\b(?:Windows NT|Mac OS X|Linux)[^)]*\)?/i', '', $userAgent) ?? $userAgent;
        $normalised = preg_replace('/\b(?:Chrome|Firefox|Safari|Edg|Version)\/[\d.]+/i', '', $normalised) ?? $normalised;

        return strtolower(trim(preg_replace('/\s+/', ' ', $normalised) ?? $normalised));
    }

    /**
     * A short human readable description of the device.
     */
    private function describeDevice(Request $request): string
    {
        $userAgent = (string) $request->userAgent();

        $platform = match (true) {
            stripos($userAgent, 'Windows') !== false => 'Windows',
            stripos($userAgent, 'Mac OS') !== false || stripos($userAgent, 'Macintosh') !== false => 'macOS',
            stripos($userAgent, 'Android') !== false => 'Android',
            stripos($userAgent, 'iPhone') !== false, stripos($userAgent, 'iPad') !== false => 'iOS',
            stripos($userAgent, 'Linux') !== false => 'Linux',
            default => 'Desconocido',
        };

        $browser = match (true) {
            stripos($userAgent, 'Edg/') !== false => 'Edge',
            stripos($userAgent, 'OPR/') !== false || stripos($userAgent, 'Opera') !== false => 'Opera',
            stripos($userAgent, 'Firefox') !== false => 'Firefox',
            stripos($userAgent, 'Chrome') !== false => 'Chrome',
            stripos($userAgent, 'Safari') !== false => 'Safari',
            default => 'Navegador',
        };

        return "{$browser} en {$platform}";
    }
}
