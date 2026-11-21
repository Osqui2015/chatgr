<?php

namespace Database\Factories;

use App\Enums\ConversationType;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ConversationType::Direct,
            'title' => null,
            'last_message_at' => Carbon::now(),
        ];
    }

    /**
     * Indicate that the conversation is the shared global room.
     */
    public function global(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ConversationType::Global,
            'title' => 'Chat General',
        ]);
    }
}
