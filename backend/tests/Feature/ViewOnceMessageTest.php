<?php

namespace Tests\Feature;

use App\Jobs\DestroyEphemeralMediaJob;
use App\Models\Conversation;
use App\Models\MediaAsset;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ViewOnceMessageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();
    }

    public function test_the_author_can_send_a_view_once_message(): void
    {
        [$author, $recipient, $conversation] = $this->participants();
        $upload = $this->upload($author, $conversation);

        $response = $this->actingAs($author)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            [
                'type' => 'view_once',
                'media_uuid' => $upload['uuid'],
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('message.type', 'view_once')
            ->assertJsonPath('message.view_once.available', true)
            ->assertJsonPath('message.view_once.opened', false)
            ->assertJsonPath('message.view_once.url', null);

        $this->assertNotNull($response->json('message.view_once.width'));
        $this->assertNotNull($response->json('message.view_once.height'));

        $message = Message::query()->findOrFail($response->json('message.id'));

        $this->assertSame($upload['uuid'], $message->mediaAsset?->uuid);
    }

    public function test_the_upload_token_stops_working_once_the_image_is_sent(): void
    {
        [$author, $recipient, $conversation] = $this->participants();
        $upload = $this->upload($author, $conversation);

        $this->actingAs($author)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            [
                'type' => 'view_once',
                'media_uuid' => $upload['uuid'],
            ],
        )->assertCreated();

        $this->getJson("/api/media/ephemeral/{$upload['token']}")->assertGone();
    }

    public function test_the_recipient_can_open_the_image_exactly_once(): void
    {
        [$author, $recipient, $conversation] = $this->participants();
        $upload = $this->upload($author, $conversation);

        $this->actingAs($author)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            [
                'type' => 'view_once',
                'media_uuid' => $upload['uuid'],
            ],
        )->assertCreated();

        $storedPath = MediaAsset::query()->where('uuid', $upload['uuid'])->value('path');

        $this->assertIsString($storedPath);

        $path = $this->assetPath($recipient, $conversation);

        $first = $this->actingAs($recipient)->get($path);

        $first->assertOk();
        $this->assertSame('image/webp', $first->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));
        $this->assertTrue(Storage::disk('public')->missing($storedPath));
        Queue::assertPushed(DestroyEphemeralMediaJob::class);

        $this->actingAs($recipient)->get($path)->assertGone();

        $this->actingAs($recipient)
            ->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.view_once.available', false)
            ->assertJsonPath('data.0.view_once.opened', true)
            ->assertJsonPath('data.0.view_once.url', null);
    }

    public function test_the_author_cannot_open_their_own_view_once_image(): void
    {
        [$author, $recipient, $conversation] = $this->participants();
        $upload = $this->upload($author, $conversation);

        $this->actingAs($author)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            [
                'type' => 'view_once',
                'media_uuid' => $upload['uuid'],
            ],
        )->assertCreated();

        $this->actingAs($author)
            ->getJson("/api/media/ephemeral/asset/{$upload['uuid']}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo el destinatario puede abrir esta imagen.');
    }

    public function test_a_user_outside_the_conversation_cannot_open_the_image(): void
    {
        [$author, $recipient, $conversation] = $this->participants();
        $stranger = User::factory()->create();
        $upload = $this->upload($author, $conversation);

        $this->actingAs($author)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            [
                'type' => 'view_once',
                'media_uuid' => $upload['uuid'],
            ],
        )->assertCreated();

        $this->actingAs($stranger)
            ->getJson("/api/media/ephemeral/asset/{$upload['uuid']}")
            ->assertForbidden();
    }

    public function test_an_asset_uploaded_by_another_user_is_rejected(): void
    {
        [$author, $recipient, $conversation] = $this->participants();
        $upload = $this->upload($author, $conversation);

        $this->actingAs($recipient)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            [
                'type' => 'view_once',
                'media_uuid' => $upload['uuid'],
            ],
        )->assertUnprocessable()
            ->assertJsonPath('message', 'La imagen de un solo uso no es válida o ya se ha utilizado.');
    }

    public function test_an_asset_uploaded_for_another_conversation_is_rejected(): void
    {
        [$author, $recipient, $conversation] = $this->participants();
        $other = User::factory()->create();
        $otherConversation = app(ConversationService::class)->directConversation($author, $other);
        $upload = $this->upload($author, $conversation);

        $this->actingAs($author)->postJson(
            "/api/conversations/{$otherConversation->id}/messages",
            [
                'type' => 'view_once',
                'media_uuid' => $upload['uuid'],
            ],
        )->assertUnprocessable();
    }

    public function test_an_asset_can_only_be_attached_to_one_message(): void
    {
        [$author, $recipient, $conversation] = $this->participants();
        $upload = $this->upload($author, $conversation);

        $payload = [
            'type' => 'view_once',
            'media_uuid' => $upload['uuid'],
        ];

        $this->actingAs($author)
            ->postJson("/api/conversations/{$conversation->id}/messages", $payload)
            ->assertCreated();

        $this->actingAs($author)
            ->postJson("/api/conversations/{$conversation->id}/messages", $payload)
            ->assertUnprocessable();
    }

    public function test_a_consumed_asset_cannot_be_attached(): void
    {
        [$author, $recipient, $conversation] = $this->participants();
        $upload = $this->upload($author, $conversation);

        $this->getJson("/api/media/ephemeral/{$upload['token']}")->assertOk();

        $this->actingAs($author)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            [
                'type' => 'view_once',
                'media_uuid' => $upload['uuid'],
            ],
        )->assertUnprocessable();
    }

    public function test_a_view_once_message_requires_the_media_uuid(): void
    {
        [$author, $recipient, $conversation] = $this->participants();

        $this->actingAs($author)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            ['type' => 'view_once'],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('media_uuid');
    }

    public function test_a_view_once_message_rejects_text_and_inline_images(): void
    {
        [$author, $recipient, $conversation] = $this->participants();
        $upload = $this->upload($author, $conversation);

        $this->actingAs($author)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            [
                'type' => 'view_once',
                'media_uuid' => $upload['uuid'],
                'body' => 'Con texto no permitido',
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->actingAs($author)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            [
                'type' => 'view_once',
                'media_uuid' => $upload['uuid'],
                'image' => UploadedFile::fake()->image('otra.jpg', 200, 200),
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    /**
     * Create two users and the direct conversation between them.
     *
     * @return array{0: User, 1: User, 2: Conversation}
     */
    private function participants(): array
    {
        $author = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = app(ConversationService::class)->directConversation($author, $recipient);

        return [$author, $recipient, $conversation];
    }

    /**
     * Upload a view-once image and return the response payload.
     *
     * @return array<string, mixed>
     */
    private function upload(User $user, Conversation $conversation): array
    {
        $payload = [
            'image' => UploadedFile::fake()->image('secreto.jpg', 400, 400),
            'conversation_id' => $conversation->id,
        ];

        return $this->actingAs($user)
            ->postJson('/api/media/ephemeral', $payload)
            ->assertCreated()
            ->json();
    }

    /**
     * Read the authorized URL from the recipient message list.
     */
    private function assetPath(User $recipient, Conversation $conversation): string
    {
        $url = $this->actingAs($recipient)
            ->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->json('data.0.view_once.url');

        $this->assertIsString($url);

        $path = parse_url($url, PHP_URL_PATH);

        $this->assertIsString($path);

        return $path;
    }
}
