<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserDevice>
 */
class UserDeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_hash' => hash('sha256', $this->faker->unique()->uuid()),
            'label' => 'Chrome en Windows',
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'last_used_at' => now(),
            'trusted_until' => now()->addDays(30),
        ];
    }

    /**
     * Indicate that the device is not trusted.
     */
    public function untrusted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'trusted_until' => null,
        ]);
    }
}
