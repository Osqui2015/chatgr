<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_global_conversation_is_created_on_demand(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAs($user)->getJson('/api/conversations/global');

        $first->assertOk()
            ->assertJsonPath('data.type', 'global')
            ->assertJsonPath('data.title', 'Chat General');

        $id = $first->json('data.id');

        $this->assertNotNull($id);

        $second = $this->actingAs($user)->getJson('/api/conversations/global');

        $second->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, Conversation::query()->where('type', 'global')->count());
    }

    public function test_the_global_conversation_serialises_the_pivot_dates(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/conversations/global');

        $response->assertOk();

        $lastRead = $response->json('data.last_read_at');

        $this->assertIsString($lastRead);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $lastRead,
            'El cursor de lectura debe serializarse como fecha ISO 8601.',
        );

        $this->assertIsInt($response->json('data.unread_count'));
        $this->assertSame($user->id, $response->json('data.participants.0.id'));
    }

    public function test_the_conversation_list_serialises_every_pivot(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        app(ConversationService::class)->directConversation($user, $other);
        app(ConversationService::class)->globalConversation();
        app(ConversationService::class)->ensureMembership(
            app(ConversationService::class)->globalConversation(),
            [$user->id],
        );

        $response = $this->actingAs($user)->getJson('/api/conversations');

        $response->assertOk();

        $conversations = $response->json('data');

        $this->assertCount(2, $conversations);

        foreach ($conversations as $conversation) {
            $this->assertIsInt($conversation['unread_count']);
            $this->assertIsString($conversation['last_read_at']);
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T/',
                $conversation['last_read_at'],
            );
        }
    }

    public function test_the_conversation_list_requires_authentication(): void
    {
        $this->getJson('/api/conversations')->assertUnauthorized();
        $this->getJson('/api/conversations/global')->assertUnauthorized();
    }
}
