<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nickname' => $this->faker->unique()->userName(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => Carbon::now(),
            'password' => static::$password ??= Hash::make('Password123!'),
            'status' => UserStatus::Offline,
            'role' => UserRole::User,
            'is_banned' => false,
            'is_muted' => false,
            'last_seen_at' => Carbon::now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the account has not been verified yet.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the account is an administrator.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Admin,
        ]);
    }

    /**
     * Indicate that the account is a moderator.
     */
    public function moderator(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Moderator,
        ]);
    }

    /**
     * Indicate that the account is banned.
     */
    public function banned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_banned' => true,
        ]);
    }

    /**
     * Indicate that the account is online.
     */
    public function online(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatus::Online,
        ]);
    }
}
