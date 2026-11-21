<?php

namespace Database\Factories;

use App\Models\Ban;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ban>
 */
class BanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'banned_by' => User::factory()->state(['role' => 'admin']),
            'reason' => $this->faker->sentence(),
            'expires_at' => null,
            'revoked_at' => null,
        ];
    }

    /**
     * Indicate that the ban has been lifted.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
        ]);
    }
}
