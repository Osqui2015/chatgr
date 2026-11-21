<?php

namespace Tests\Feature;

use App\Models\Ban;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_statistics_summarise_users_messages_and_moderation(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(3)->create();
        User::factory()->banned()->create();
        Message::factory()->count(2)->create();
        Report::factory()->create();
        Ban::factory()->create(['banned_by' => $admin->id]);

        $response = $this->actingAs($admin)->getJson('/api/admin/statistics');

        $response->assertOk()
            ->assertJsonPath('data.users.total', User::query()->count())
            ->assertJsonPath('data.users.banned', 1)
            ->assertJsonPath('data.messages.total', 2)
            ->assertJsonPath('data.moderation.pending_reports', 1)
            ->assertJsonPath('data.moderation.active_bans', 1);

        $this->assertIsInt($response->json('data.presence.online'));
        $this->assertIsInt($response->json('data.users.new_24h'));
    }

    public function test_the_user_list_can_be_filtered_by_role_and_search_term(): void
    {
        $admin = User::factory()->admin()->create();
        $moderator = User::factory()->moderator()->create(['nickname' => 'moderador-filtro']);
        User::factory()->create(['nickname' => 'usuario-normal']);

        $byRole = $this->actingAs($admin)->getJson('/api/admin/users?role=moderator');

        $byRole->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame('moderador-filtro', $byRole->json('data.0.nickname'));

        $byTerm = $this->actingAs($admin)->getJson('/api/admin/users?q=moderador-filtro');

        $byTerm->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($moderator->id, $byTerm->json('data.0.id'));
    }

    public function test_an_invalid_role_filter_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson('/api/admin/users?role=superadmin')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_the_ban_list_returns_the_banned_accounts(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['nickname' => 'baneado']);

        Ban::factory()->create([
            'user_id' => $target->id,
            'banned_by' => $admin->id,
            'reason' => 'Spam reiterado',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/bans');

        $response->assertOk();
        $this->assertSame($target->id, $response->json('data.0.user.id'));
        $this->assertSame('Spam reiterado', $response->json('data.0.reason'));
    }

    public function test_reports_can_be_filtered_by_status(): void
    {
        $admin = User::factory()->admin()->create();
        $pending = Report::factory()->create(['status' => 'pending']);
        Report::factory()->resolved()->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/reports?status=pending');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($pending->id, $response->json('data.0.id'));
    }

    public function test_the_online_endpoint_returns_a_consistent_list(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/online');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['count']]);

        $this->assertSame(
            count($response->json('data')),
            $response->json('meta.count'),
        );
    }

    public function test_a_moderator_cannot_change_roles(): void
    {
        $moderator = User::factory()->moderator()->create();
        $target = User::factory()->create();

        $this->actingAs($moderator)
            ->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'admin'])
            ->assertForbidden();
    }

    public function test_an_administrator_cannot_ban_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$admin->id}/ban", ['reason' => 'Error'])
            ->assertForbidden();
    }

    public function test_a_moderator_can_review_a_report(): void
    {
        $moderator = User::factory()->moderator()->create();
        $report = Report::factory()->create();

        $this->actingAs($moderator)
            ->patchJson("/api/admin/reports/{$report->id}", ['status' => 'dismissed'])
            ->assertOk()
            ->assertJsonPath('report.status', 'dismissed');

        $this->assertSame($moderator->id, $report->fresh()->resolved_by);
        $this->assertNotNull($report->fresh()->resolved_at);
    }

    public function test_a_ban_with_an_expiry_in_the_past_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$target->id}/ban", [
                'reason' => 'Spam',
                'expires_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_at');
    }
}
