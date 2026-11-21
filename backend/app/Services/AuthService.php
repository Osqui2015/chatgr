<?php

namespace App\Services;

use App\Enums\LoginReason;
use App\Enums\OtpPurpose;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Implements the account lifecycle: registration with email verification,
 * credential login with device-aware OTP challenges, and account suspension.
 */
class AuthService
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly DeviceService $devices,
    ) {}

    /**
     * Create an account and issue its verification code.
     *
     * The account starts locked: it cannot authenticate until the email has
     * been confirmed through the OTP flow.
     */
    public function register(string $email, string $nickname, ?string $password = null): User
    {
        $email = Str::lower(trim($email));

        // The account and the code are created together, so a failure while
        // issuing the code never leaves an unusable account behind.
        return DB::transaction(function () use ($email, $nickname, $password): User {
            $user = User::create([
                'email' => $email,
                'nickname' => $nickname,
                // Accounts created through the OTP flow get a random password
                // so the account can never be entered without an email check.
                'password' => $password ?? Str::password(32),
                'email_verified_at' => null,
                'status' => UserStatus::Offline,
                'role' => UserRole::User,
            ]);

            $this->otp->issue($email, OtpPurpose::Registration, request()->ip());

            return $user;
        });
    }

    /**
     * Complete registration once the emailed code has been confirmed.
     *
     * @return array{0: bool, 1: ?User} Whether verification succeeded and the user.
     */
    public function completeRegistration(string $email, string $code, ?string $password = null): array
    {
        $email = Str::lower(trim($email));

        if (! $this->otp->verify($email, $code, OtpPurpose::Registration)) {
            return [false, null];
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return [false, null];
        }

        $user->forceFill([
            'email_verified_at' => Carbon::now(),
            'last_login_at' => Carbon::now(),
        ]);

        if ($password !== null && $password !== '') {
            $user->password = $password;
        }

        $user->save();

        return [true, $user];
    }

    /**
     * Attempt a password login.
     *
     * When the request comes from an untrusted device a fresh OTP is issued and
     * the caller is told to complete the challenge; otherwise the user is
     * logged in straight away.
     *
     * @return array{status: 'authenticated'|'otp_required'|'invalid', user: ?User}
     */
    public function attemptLogin(Request $request, string $email, string $password): array
    {
        $email = Str::lower(trim($email));
        $user = $this->devices->credentialsAreValid($email, $password);

        if ($user === null) {
            $this->devices->recordAttempt($request, LoginReason::InvalidCredentials, false, null, $email);

            return ['status' => 'invalid', 'user' => null];
        }

        if ($user->is_banned) {
            $this->devices->recordAttempt($request, LoginReason::AccountBanned, false, $user);

            return ['status' => 'invalid', 'user' => null];
        }

        if ($user->email_verified_at === null) {
            // The account was never verified; restart the verification flow.
            $this->otp->issue($email, OtpPurpose::Registration, $request->ip());
            $this->devices->recordAttempt($request, LoginReason::OtpRequired, false, $user);

            return ['status' => 'otp_required', 'user' => $user];
        }

        if (! $this->devices->isTrusted($request, $user)) {
            $this->otp->issue($email, OtpPurpose::Login, $request->ip());
            $this->devices->recordAttempt($request, LoginReason::UnknownDevice, false, $user);

            return ['status' => 'otp_required', 'user' => $user];
        }

        $this->establishSession($request, $user);

        return ['status' => 'authenticated', 'user' => $user];
    }

    /**
     * Finish a login that was interrupted by an OTP challenge.
     *
     * @return array{status: 'authenticated'|'invalid', user: ?User}
     */
    public function completeLogin(Request $request, string $email, string $code): array
    {
        $email = Str::lower(trim($email));

        if (! $this->otp->verify($email, $code, OtpPurpose::Login)) {
            $this->devices->recordAttempt($request, LoginReason::OtpFailed, false, null, $email);

            return ['status' => 'invalid', 'user' => null];
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->is_banned) {
            $this->devices->recordAttempt($request, LoginReason::InvalidCredentials, false, $user, $email);

            return ['status' => 'invalid', 'user' => null];
        }

        // The device proved its identity, so it may skip future challenges.
        $this->devices->trustDevice($request, $user);
        $this->establishSession($request, $user);

        return ['status' => 'authenticated', 'user' => $user];
    }

    /**
     * Authenticate a user and establish the API session.
     */
    public function establishSession(Request $request, User $user): void
    {
        $user->forceFill(['last_login_at' => Carbon::now()])->saveQuietly();

        $this->devices->resolveDevice($request, $user);
        $this->devices->recordAttempt($request, LoginReason::Success, true, $user);
    }

    /**
     * Whether the account currently has an active ban.
     */
    public function isBanned(User $user): bool
    {
        if ($user->is_banned) {
            return true;
        }

        return $user->bans()->whereNull('revoked_at')->where(function ($query): void {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
        })->exists();
    }
}
