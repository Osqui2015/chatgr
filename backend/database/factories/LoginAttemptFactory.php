<?php

namespace Database\Factories;

use App\Enums\LoginReason;
use App\Models\LoginAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoginAttempt>
 */
class LoginAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => $this->faker->safeEmail(),
            'ip_address' => $this->faker->ipv4(),
            'device_hash' => hash('sha256', $this->faker->uuid()),
            'reason' => LoginReason::InvalidCredentials->value,
            'succeeded' => false,
        ];
    }
}
