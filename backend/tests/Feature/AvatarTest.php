<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * The avatar sizes the API must generate.
     *
     * @var array<int, int>
     */
    private const SIZES = [32, 64, 128, 512];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_uploading_an_avatar_generates_every_webp_variant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            '/api/users/me/avatar',
            ['avatar' => UploadedFile::fake()->image('foto.jpg', 640, 480)],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()
            ->assertJsonPath('message', 'Avatar actualizado.')
            ->assertJsonPath('user.id', $user->id);

        $files = Storage::disk('public')->files("avatars/{$user->id}");

        $this->assertCount(4, $files, 'Deben generarse exactamente cuatro variantes.');

        foreach (self::SIZES as $size) {
            $path = "avatars/{$user->id}/{$size}.webp";

            $this->assertTrue(
                Storage::disk('public')->exists($path),
                "Falta la variante de {$size}px.",
            );

            $binary = Storage::disk('public')->get($path);
            $info = getimagesizefromstring($binary);

            $this->assertIsArray($info);
            $this->assertSame('image/webp', $info['mime']);
            $this->assertSame($size, $info[0], 'El ancho de la variante no coincide.');
            $this->assertSame($size, $info[1], 'El alto de la variante no coincide.');
        }

        $user->refresh();

        $this->assertSame("avatars/{$user->id}/128.webp", $user->avatar_path);
        $this->assertSame('image/webp', $user->avatar_mime);
        $this->assertSame(
            Storage::disk('public')->size("avatars/{$user->id}/128.webp"),
            $user->avatar_size,
        );

        $url = $response->json('user.avatar_url');

        $this->assertIsString($url);
        $this->assertStringEndsWith("avatars/{$user->id}/128.webp", $url);
        $this->assertSame(
            ['32', '64', '128', '512'],
            array_map('strval', array_keys($response->json('user.avatar_sizes'))),
        );
    }

    public function test_the_original_upload_is_never_stored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(
            '/api/users/me/avatar',
            ['avatar' => UploadedFile::fake()->image('original.png', 300, 300)],
            ['Accept' => 'application/json'],
        )->assertOk();

        $files = Storage::disk('public')->files("avatars/{$user->id}");

        $this->assertCount(4, $files);

        foreach ($files as $file) {
            $this->assertStringEndsWith('.webp', $file);
        }
    }

    public function test_embedded_payloads_are_removed_by_the_re_encoding(): void
    {
        $user = User::factory()->create();

        $jpeg = UploadedFile::fake()->image('foto.jpg', 300, 300)->getContent();
        $payload = '<?php echo "payload-malicioso"; ?>';
        $file = UploadedFile::fake()->createWithContent('foto.jpg', $jpeg.$payload);

        $this->actingAs($user)->post(
            '/api/users/me/avatar',
            ['avatar' => $file],
            ['Accept' => 'application/json'],
        )->assertOk();

        foreach (Storage::disk('public')->files("avatars/{$user->id}") as $path) {
            $this->assertStringNotContainsString(
                'payload-malicioso',
                Storage::disk('public')->get($path),
                'Ninguna variante debe conservar datos anexados al archivo original.',
            );
        }
    }

    public function test_uploading_a_new_avatar_replaces_the_previous_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(
            '/api/users/me/avatar',
            ['avatar' => UploadedFile::fake()->createWithContent('primera.jpg', $this->solidJpeg(20, 40, 160))],
            ['Accept' => 'application/json'],
        )->assertOk();

        $first = Storage::disk('public')->get("avatars/{$user->id}/512.webp");

        $this->actAsSecondUpload($user);

        $second = Storage::disk('public')->get("avatars/{$user->id}/512.webp");

        $this->assertNotSame($first, $second, 'La segunda subida debe sustituir las variantes.');
        $this->assertCount(4, Storage::disk('public')->files("avatars/{$user->id}"));
        $this->assertSame("avatars/{$user->id}/128.webp", $user->fresh()->avatar_path);
    }

    public function test_deleting_the_avatar_removes_every_variant_and_clears_the_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(
            '/api/users/me/avatar',
            ['avatar' => UploadedFile::fake()->image('foto.jpg', 400, 400)],
            ['Accept' => 'application/json'],
        )->assertOk();

        $this->assertNotEmpty(Storage::disk('public')->files("avatars/{$user->id}"));

        $this->actingAs($user)
            ->deleteJson('/api/users/me/avatar')
            ->assertOk()
            ->assertJsonPath('message', 'Avatar eliminado.');

        $this->assertEmpty(Storage::disk('public')->files("avatars/{$user->id}"));

        $user->refresh();

        $this->assertNull($user->avatar_path);
        $this->assertNull($user->avatar_mime);
        $this->assertNull($user->avatar_size);
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(
            '/api/users/me/avatar',
            ['avatar' => UploadedFile::fake()->create('documento.pdf', 10, 'application/pdf')],
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    }

    public function test_the_avatar_endpoint_requires_authentication(): void
    {
        $this->post(
            '/api/users/me/avatar',
            ['avatar' => UploadedFile::fake()->image('foto.jpg', 100, 100)],
            ['Accept' => 'application/json'],
        )->assertUnauthorized();
    }

    private function actAsSecondUpload(User $user): void
    {
        $this->actingAs($user)->post(
            '/api/users/me/avatar',
            ['avatar' => UploadedFile::fake()->createWithContent('segunda.jpg', $this->solidJpeg(220, 90, 20))],
            ['Accept' => 'application/json'],
        )->assertOk();
    }

    /**
     * Build a solid-colour JPEG so two uploads are byte-wise different.
     */
    private function solidJpeg(int $red, int $green, int $blue, int $side = 600): string
    {
        $image = imagecreatetruecolor($side, $side);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));

        ob_start();
        imagejpeg($image, null, 90);
        $binary = (string) ob_get_clean();

        imagedestroy($image);

        return $binary;
    }
}
