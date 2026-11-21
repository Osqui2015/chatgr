<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserProfileRoleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_user_can_set_and_change_their_profile_role(): void
    {
        $user = User::factory()->create();

        foreach (['activo', 'versatil', 'pasivo'] as $role) {
            $response = $this->actingAs($user)
                ->patchJson('/api/users/me', ['sexual_role' => $role])
                ->assertOk()
                ->assertJsonPath('user.sexual_role', $role);

            $this->assertSame($role, $user->fresh()->sexual_role->value);
        }
    }

    public function test_a_user_can_clear_their_profile_role(): void
    {
        $user = User::factory()->create([
            'sexual_role' => 'activo',
        ]);

        $this->actingAs($user)
            ->patchJson('/api/users/me', ['sexual_role' => null])
            ->assertOk()
            ->assertJsonPath('user.sexual_role', null);

        $this->assertNull($user->fresh()->sexual_role);
    }

    public function test_invalid_profile_role_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/users/me', ['sexual_role' => 'invalido'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sexual_role']);
    }

    public function test_another_user_can_see_the_profile_role(): void
    {
        $owner = User::factory()->create([
            'sexual_role' => 'versatil',
        ]);

        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->getJson("/api/users/{$owner->id}")
            ->assertOk()
            ->assertJsonPath('data.sexual_role', 'versatil')
            ->assertJsonPath('data.sexual_role_label', 'Versátil');
    }
}
