<?php

namespace App\Http\Controllers\Api;

use App\Enums\LoginReason;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendCodeRequest;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\DeviceService;
use App\Services\OtpCooldownException;
use App\Services\OtpService;
use App\Services\PresenceService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles registration, verification, login and logout.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly OtpService $otp,
        private readonly DeviceService $devices,
        private readonly PresenceService $presence,
    ) {}

    /**
     * The session guard used to sign the user in.
     */
    private function guard(): StatefulGuard
    {
        $guard = Auth::guard('web');

        assert($guard instanceof StatefulGuard);

        return $guard;
    }

    /**
     * Start registration: create the account and send the verification code.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $user = $this->auth->register($data['email'], $data['nickname'], $data['password']);
        } catch (OtpCooldownException $e) {
            // A code was sent moments ago, so a new one is not issued.
            return response()->json([
                'message' => 'Espera unos segundos antes de solicitar otro código.',
                'retry_after' => $e->retryAfterSeconds,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return response()->json([
            'message' => 'Te enviamos un código de 6 dígitos a tu correo para completar el registro.',
            'email' => $user->email,
            'purpose' => OtpPurpose::Registration->value,
        ], Response::HTTP_CREATED);
    }

    /**
     * Confirm the emailed code and finish registration.
     */
    public function verifyRegistration(VerifyCodeRequest $request): JsonResponse
    {
        [$verified, $user] = $this->auth->completeRegistration(
            $request->string('email')->toString(),
            $request->string('code')->toString(),
            $request->input('password'),
        );

        if (! $verified || $user === null) {
            throw ValidationException::withMessages([
                'code' => 'El código es incorrecto o ha expirado.',
            ]);
        }

        if ($request->filled('password')) {
            $user->forceFill([
                'password' => Hash::make($request->string('password')->toString()),
            ])->save();
        }

        $this->guard()->login($user);
        $request->session()->regenerate();
        $this->presence->markOnline($user, UserStatus::Online);

        return response()->json([
            'message' => '¡Cuenta verificada!',
            'user' => UserResource::make($user),
        ]);
    }

    /**
     * Resend a verification code.
     */
    public function resendCode(ResendCodeRequest $request): JsonResponse
    {
        try {
            $this->otp->issue(
                $request->string('email')->toString(),
                OtpPurpose::Registration,
                $request->ip(),
            );
        } catch (OtpCooldownException $e) {
            return response()->json([
                'message' => 'Espera unos segundos antes de solicitar otro código.',
                'retry_after' => $e->retryAfterSeconds,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return response()->json([
            'message' => 'Te enviamos un nuevo código de verificación.',
        ]);
    }

    /**
     * Log in with email and password.
     *
     * A device that is not trusted triggers an OTP challenge and the response
     * tells the client to continue with the verification step.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->auth->attemptLogin(
                $request,
                $request->string('email')->toString(),
                $request->string('password')->toString(),
            );
        } catch (OtpCooldownException $e) {
            // A code was sent moments ago, so a new one is not issued.
            return response()->json([
                'message' => 'Espera unos segundos antes de solicitar otro código.',
                'retry_after' => $e->retryAfterSeconds,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($result['status'] === 'invalid') {
            $this->devices->recordAttempt(
                $request,
                LoginReason::InvalidCredentials,
                false,
                null,
                $request->string('email')->toString(),
            );

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas.',
            ]);
        }

        if ($result['status'] === 'otp_required') {
            return response()->json([
                'message' => 'Hemos detectado un dispositivo nuevo. Te enviamos un código de verificación.',
                'requires_otp' => true,
                'email' => $request->string('email')->toString(),
                'purpose' => OtpPurpose::Login->value,
            ], Response::HTTP_UNAUTHORIZED);
        }

        /** @var User $user */
        $user = $result['user'];

        $this->guard()->login($user);
        $request->session()->regenerate();
        $this->presence->markOnline($user, UserStatus::Online);

        return response()->json([
            'message' => 'Sesión iniciada.',
            'user' => UserResource::make($user),
        ]);
    }

    /**
     * Complete a login that was challenged with an OTP.
     */
    public function verifyLogin(VerifyCodeRequest $request): JsonResponse
    {
        $result = $this->auth->completeLogin(
            $request,
            $request->string('email')->toString(),
            $request->string('code')->toString(),
        );

        if ($result['status'] === 'invalid' || $result['user'] === null) {
            throw ValidationException::withMessages([
                'code' => 'El código es incorrecto o ha expirado.',
            ]);
        }

        $user = $result['user'];

        $this->guard()->login($user);
        $request->session()->regenerate();
        $this->presence->markOnline($user, UserStatus::Online);

        return response()->json([
            'message' => 'Sesión iniciada.',
            'user' => UserResource::make($user),
        ]);
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => UserResource::make($user),
        ]);
    }

    /**
     * Log out and clear the session.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->presence->markOffline($user);

        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Sesión cerrada.',
        ]);
    }
}
