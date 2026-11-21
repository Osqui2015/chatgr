<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'user_id' => User::factory(),
            'conversation_id' => null,
            'uuid' => $uuid,
            'disk' => 'public',
            'path' => "media/{$uuid}.webp",
            'mime' => 'image/webp',
            'size' => $this->faker->numberBetween(1024, 512000),
            'width' => 1024,
            'height' => 1024,
            'kind' => 'attachment',
        ];
    }

    /**
     * Indicate that the asset is a view-once image.
     */
    public function ephemeral(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => 'ephemeral',
            'token_hash' => hash('sha256', $this->faker->uuid()),
            'expires_at' => now()->addHours(24),
        ]);
    }
}
