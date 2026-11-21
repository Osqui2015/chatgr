<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EphemeralMediaTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_a_view_once_image_is_stored_and_returns_a_single_use_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/media/ephemeral', [
            'image' => UploadedFile::fake()->image('secreto.jpg', 400, 400),
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['uuid', 'token', 'url', 'width', 'height']);

        $this->assertDatabaseHas('media_assets', [
            'user_id' => $user->id,
            'kind' => 'ephemeral',
        ]);
    }

    public function test_the_token_serves_the_image_exactly_once(): void
    {
        $user = User::factory()->create();

        $token = $this->actingAs($user)->postJson('/api/media/ephemeral', [
            'image' => UploadedFile::fake()->image('secreto.jpg', 400, 400),
        ])->json('token');

        $first = $this->getJson("/api/media/ephemeral/{$token}");

        $first->assertOk();
        $this->assertSame('image/webp', $first->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));

        // The second attempt with the same token must fail.
        $this->getJson("/api/media/ephemeral/{$token}")
            ->assertGone()
            ->assertJsonPath('message', 'Esta imagen ya no está disponible.');
    }

    public function test_the_file_is_deleted_once_the_image_has_been_opened(): void
    {
        $user = User::factory()->create();

        $token = $this->actingAs($user)->postJson('/api/media/ephemeral', [
            'image' => UploadedFile::fake()->image('secreto.jpg', 400, 400),
        ])->json('token');

        $asset = MediaAsset::query()->where('kind', 'ephemeral')->firstOrFail();

        $this->getJson("/api/media/ephemeral/{$token}")->assertOk();

        $this->assertFalse(
            Storage::disk('public')->exists($asset->path),
            'El archivo de la imagen temporal debe eliminarse tras abrirse.',
        );
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->getJson('/api/media/ephemeral/token-inexistente')
            ->assertGone();
    }

    public function test_the_upload_requires_authentication(): void
    {
        $this->postJson('/api/media/ephemeral', [
            'image' => UploadedFile::fake()->image('secreto.jpg', 400, 400),
        ])->assertUnauthorized();
    }

    public function test_a_file_that_is_not_an_image_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/media/ephemeral', [
                'image' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }
}
