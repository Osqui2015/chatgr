<?php

namespace Tests\Feature;

use App\Enums\ConversationType;
use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MessageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_message_cannot_be_sent_without_authentication(): void
    {
        $conversation = Conversation::factory()->global()->create();

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Hola',
        ])->assertUnauthorized();
    }

    public function test_a_user_can_send_a_message_to_the_global_room(): void
    {
        Event::fake([MessageSent::class]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->global()->create();

        $response = $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Hola a todos',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message.body', 'Hola a todos')
            ->assertJsonPath('message.user.id', $user->id)
            ->assertJsonPath('message.type', 'text');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => 'Hola a todos',
        ]);

        Event::assertDispatched(MessageSent::class);
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->global()->create();

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_a_message_that_is_too_long_is_rejected(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->global()->create();

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'body' => str_repeat('a', 4001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_a_user_can_reply_to_a_message(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->global()->create();
        $original = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => 'Mensaje original',
        ]);

        $response = $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Esta es una respuesta',
            'reply_to_id' => $original->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('message.reply_to.id', $original->id)
            ->assertJsonPath('message.reply_to.body', 'Mensaje original');
    }

    public function test_a_reply_to_a_message_in_another_conversation_is_rejected(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->global()->create();
        $other = Conversation::factory()->create();

        $foreign = Message::factory()->create([
            'conversation_id' => $other->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'body' => 'Respuesta inválida',
                'reply_to_id' => $foreign->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El mensaje original no pertenece a esta conversación.');
    }

    public function test_only_the_author_can_edit_a_message(): void
    {
        Event::fake([MessageUpdated::class]);

        $author = User::factory()->create();
        $other = User::factory()->create();
        $message = Message::factory()->create(['user_id' => $author->id]);

        $this->actingAs($other)
            ->patchJson("/api/messages/{$message->id}", ['body' => 'Mensaje alterado'])
            ->assertForbidden();

        $this->actingAs($author)
            ->patchJson("/api/messages/{$message->id}", ['body' => 'Mensaje corregido'])
            ->assertOk()
            ->assertJsonPath('message.body', 'Mensaje corregido');

        $this->assertNotNull($message->fresh()->edited_at);
        Event::assertDispatched(MessageUpdated::class);
    }

    public function test_a_moderator_can_delete_any_message(): void
    {
        Event::fake([MessageDeleted::class]);

        $author = User::factory()->create();
        $moderator = User::factory()->moderator()->create();
        $message = Message::factory()->create(['user_id' => $author->id]);

        $this->actingAs($moderator)
            ->deleteJson("/api/messages/{$message->id}")
            ->assertOk();

        $this->assertSoftDeleted($message);
        Event::assertDispatched(MessageDeleted::class);
    }

    public function test_the_author_can_delete_their_own_message(): void
    {
        $author = User::factory()->create();
        $message = Message::factory()->create(['user_id' => $author->id]);

        $this->actingAs($author)
            ->deleteJson("/api/messages/{$message->id}")
            ->assertOk();

        $this->assertSoftDeleted($message);
    }

    public function test_a_reaction_can_be_toggled(): void
    {
        $user = User::factory()->create();
        $message = Message::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('active', true);

        $this->assertDatabaseHas('message_reactions', [
            'message_id' => $message->id,
            'user_id' => $user->id,
            'emoji' => '👍',
        ]);

        $this->actingAs($user)
            ->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('active', false);

        $this->assertDatabaseMissing('message_reactions', [
            'message_id' => $message->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_messages_are_listed_oldest_first(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->global()->create();

        Message::factory()->count(3)->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/conversations/{$conversation->id}/messages");

        $response->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $sorted = $ids;
        sort($sorted);

        $this->assertSame($sorted, $ids);
    }

    public function test_a_user_cannot_read_a_private_conversation_they_do_not_belong_to(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();

        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Direct,
        ]);
        $conversation->users()->attach($owner->id);

        $this->actingAs($outsider)
            ->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertForbidden();
    }

    public function test_a_muted_user_cannot_post(): void
    {
        $user = User::factory()->create(['is_muted' => true]);
        $conversation = Conversation::factory()->global()->create();

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Hola'])
            ->assertForbidden();
    }
}
