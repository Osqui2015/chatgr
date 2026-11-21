<?php

namespace Tests\Feature;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Services\DeviceService;
use App\Services\OtpCooldownException;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\InteractsWithOtpCodes;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use InteractsWithOtpCodes;
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_a_visitor_can_register_and_receives_a_verification_code(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'nuevo@example.com',
            'nickname' => 'nuevo',
            'password' => 'Contraseña123!',
            'password_confirmation' => 'Contraseña123!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'nuevo@example.com')
            ->assertJsonPath('purpose', OtpPurpose::Registration->value);

        $this->assertDatabaseHas('users', ['email' => 'nuevo@example.com']);
        $this->assertNull(
            User::query()->where('email', 'nuevo@example.com')->firstOrFail()->email_verified_at
        );
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existe@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'email' => 'existe@example.com',
            'nickname' => 'otro',
            'password' => 'Contraseña123!',
            'password_confirmation' => 'Contraseña123!',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'debil@example.com',
            'nickname' => 'debil',
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_an_account_cannot_log_in_before_the_email_is_verified(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'sin-verificar@example.com',
            'password' => Hash::make('Contraseña123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Contraseña123!',
        ]);

        $response->assertUnauthorized()->assertJsonPath('requires_otp', true);
        $this->assertGuest();
    }

    public function test_login_with_wrong_credentials_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'usuario@example.com',
            'password' => Hash::make('Contraseña123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'usuario@example.com',
            'password' => 'ContraseñaIncorrecta1!',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_an_unknown_device_must_complete_an_otp_challenge(): void
    {
        User::factory()->create([
            'email' => 'dispositivo@example.com',
            'password' => Hash::make('Contraseña123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'dispositivo@example.com',
            'password' => 'Contraseña123!',
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('requires_otp', true)
            ->assertJsonPath('purpose', OtpPurpose::Login->value);

        $this->assertGuest();
        $this->assertDatabaseHas('otp_codes', [
            'email' => 'dispositivo@example.com',
            'purpose' => OtpPurpose::Login->value,
        ]);
    }

    public function test_login_with_active_cooldown_returns_429_with_retry_after(): void
    {
        User::factory()->create([
            'email' => 'cooldown-login@example.com',
            'password' => Hash::make('Contraseña123!'),
        ]);

        $this->mock(OtpService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('issue')
                ->once()
                ->andThrow(new OtpCooldownException(30));
        });

        $response = $this->postJson('/api/auth/login', [
            'email' => 'cooldown-login@example.com',
            'password' => 'Contraseña123!',
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('retry_after', 30)
            ->assertJsonStructure(['message', 'retry_after']);

        $this->assertGuest();
    }

    public function test_a_trusted_device_logs_in_without_an_otp(): void
    {
        $user = User::factory()->create([
            'email' => 'de-confianza@example.com',
            'password' => Hash::make('Contraseña123!'),
        ]);

        $this->actingAs($user)->postJson('/api/auth/logout');

        // Trust the current device for this request fingerprint.
        $request = Request::create('/api/auth/login', 'POST');
        $request->headers->set('User-Agent', 'TestAgent/1.0');
        app(DeviceService::class)->trustDevice($request, $user);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Contraseña123!',
        ], ['User-Agent' => 'TestAgent/1.0'])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);

        $this->assertAuthenticated();
    }

    public function test_the_registration_code_completes_the_account_and_signs_the_user_in(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => 'completa@example.com',
            'nickname' => 'completa',
            'password' => 'Contraseña123!',
            'password_confirmation' => 'Contraseña123!',
        ])->assertCreated();

        $code = $this->captureCode('completa@example.com', OtpPurpose::Registration);

        $response = $this->postJson('/api/auth/verify/registration', [
            'email' => 'completa@example.com',
            'code' => $code,
        ]);

        $response->assertOk()->assertJsonPath('user.nickname', 'completa');

        $this->assertNotNull(
            User::query()->where('email', 'completa@example.com')->firstOrFail()->email_verified_at
        );
        $this->assertAuthenticated();
    }

    public function test_an_incorrect_registration_code_is_rejected(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => 'codigo@example.com',
            'nickname' => 'codigo',
            'password' => 'Contraseña123!',
            'password_confirmation' => 'Contraseña123!',
        ])->assertCreated();

        $response = $this->postJson('/api/auth/verify/registration', [
            'email' => 'codigo@example.com',
            'code' => '000000',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertGuest();
        $this->assertNull(
            User::query()->where('email', 'codigo@example.com')->firstOrFail()->email_verified_at
        );
    }

    public function test_the_authenticated_user_can_be_retrieved_and_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->actingAs($user)
            ->postJson('/api/auth/logout')
            ->assertOk();
    }

    public function test_protected_endpoints_require_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/conversations')->assertUnauthorized();
    }

    public function test_a_code_can_be_resent_with_only_the_email(): void
    {
        User::factory()->unverified()->create(['email' => 'reenvio@example.com']);

        $this->postJson('/api/auth/resend', ['email' => 'reenvio@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'Te enviamos un nuevo código de verificación.');

        $this->assertDatabaseHas('otp_codes', [
            'email' => 'reenvio@example.com',
            'purpose' => OtpPurpose::Registration->value,
        ]);
    }

    public function test_resending_within_the_cooldown_returns_429_with_retry_after(): void
    {
        User::factory()->unverified()->create(['email' => 'espera@example.com']);

        $this->postJson('/api/auth/resend', ['email' => 'espera@example.com'])->assertOk();

        $response = $this->postJson('/api/auth/resend', ['email' => 'espera@example.com']);

        $response->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after']);

        $this->assertIsInt($response->json('retry_after'));
        $this->assertGreaterThan(0, $response->json('retry_after'));
    }

    public function test_registration_is_rolled_back_when_the_code_cannot_be_issued(): void
    {
        $this->mock(OtpService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('issue')
                ->once()
                ->andThrow(new OtpCooldownException(42));
        });

        $response = $this->postJson('/api/auth/register', [
            'email' => 'rollback@example.com',
            'nickname' => 'rollback',
            'password' => 'Contraseña123!',
            'password_confirmation' => 'Contraseña123!',
        ]);

        $response->assertStatus(429)->assertJsonPath('retry_after', 42);

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
    }

    /**
     * Read the code that was delivered for the given email and purpose.
     */
    private function captureCode(string $email, OtpPurpose $purpose): string
    {
        $this->assertDatabaseHas('otp_codes', [
            'email' => $email,
            'purpose' => $purpose->value,
        ]);

        return $this->lastOtpCode();
    }
}
