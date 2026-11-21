<?php

namespace Tests\Feature;

use App\Events\TypingIndicator;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TypingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_participant_can_broadcast_a_typing_indicator(): void
    {
        Event::fake([TypingIndicator::class]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->global()->create();

        $response = $this->actingAs($user)->postJson(
            "/api/conversations/{$conversation->id}/typing",
            ['is_typing' => true],
        );

        $response->assertOk()->assertJsonPath('message', 'Indicador actualizado.');

        Event::assertDispatched(
            TypingIndicator::class,
            fn (TypingIndicator $event): bool => $event->conversationId === $conversation->id
                && $event->userId === $user->id
                && $event->nickname === $user->nickname
                && $event->isTyping === true
                && $event->broadcastAs() === 'typing.indicator',
        );
    }

    public function test_the_indicator_can_be_stopped(): void
    {
        Event::fake([TypingIndicator::class]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->global()->create();

        $this->actingAs($user)->postJson(
            "/api/conversations/{$conversation->id}/typing",
            ['is_typing' => false],
        )->assertOk();

        Event::assertDispatched(
            TypingIndicator::class,
            fn (TypingIndicator $event): bool => $event->isTyping === false,
        );
    }

    public function test_a_non_participant_cannot_broadcast_typing_in_a_direct_conversation(): void
    {
        $outsider = User::factory()->create();
        $conversation = Conversation::factory()->create();

        $conversation->users()->attach([
            User::factory()->create()->id,
            User::factory()->create()->id,
        ]);

        Event::fake([TypingIndicator::class]);

        $this->actingAs($outsider)
            ->postJson("/api/conversations/{$conversation->id}/typing", ['is_typing' => true])
            ->assertForbidden();

        Event::assertNotDispatched(TypingIndicator::class);
    }

    public function test_the_typing_payload_must_be_boolean(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->global()->create();

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/typing", ['is_typing' => 'quizá'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_typing');
    }

    public function test_typing_requires_authentication(): void
    {
        $conversation = Conversation::factory()->global()->create();

        $this->postJson("/api/conversations/{$conversation->id}/typing", ['is_typing' => true])
            ->assertUnauthorized();
    }
}
