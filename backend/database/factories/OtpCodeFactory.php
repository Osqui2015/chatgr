<?php

namespace Database\Factories;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OtpCode>
 */
class OtpCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->safeEmail(),
            'purpose' => OtpPurpose::Registration,
            'code_hash' => hash('sha256', $this->faker->numerify('######')),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => $this->faker->ipv4(),
        ];
    }

    /**
     * Indicate that the code has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
