<?php

namespace Database\Factories;

use App\Models\Mute;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mute>
 */
class MuteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'muted_user_id' => User::factory(),
            'muted_until' => null,
        ];
    }
}
