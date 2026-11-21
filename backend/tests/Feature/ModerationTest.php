<?php

namespace Tests\Feature;

use App\Models\Ban;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_user_can_report_another_user(): void
    {
        $reporter = User::factory()->create();
        $reported = User::factory()->create();

        $response = $this->actingAs($reporter)->postJson('/api/reports', [
            'reported_user_id' => $reported->id,
            'reason' => 'spam',
            'details' => 'Envía mensajes no deseados.',
        ]);

        $response->assertCreated()->assertJsonPath('message', 'Denuncia registrada. Nuestro equipo la revisará.');

        $this->assertDatabaseHas('reports', [
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reported->id,
            'reason' => 'spam',
            'status' => 'pending',
        ]);
    }

    public function test_a_user_cannot_report_themselves(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/reports', [
                'reported_user_id' => $user->id,
                'reason' => 'spam',
            ])
            ->assertForbidden();
    }

    public function test_an_invalid_report_reason_is_rejected(): void
    {
        $reporter = User::factory()->create();
        $reported = User::factory()->create();

        $this->actingAs($reporter)
            ->postJson('/api/reports', [
                'reported_user_id' => $reported->id,
                'reason' => 'motivo-invalido',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_user_can_block_and_unblock_another_user(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/users/{$target->id}/block")
            ->assertOk();

        $this->assertDatabaseHas('blocks', [
            'blocker_id' => $user->id,
            'blocked_id' => $target->id,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/users/{$target->id}/block")
            ->assertOk();

        $this->assertDatabaseMissing('blocks', [
            'blocker_id' => $user->id,
            'blocked_id' => $target->id,
        ]);
    }

    public function test_a_user_cannot_block_themselves(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/users/{$user->id}/block")
            ->assertForbidden();
    }

    public function test_a_user_can_mute_and_unmute_another_user(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/users/{$target->id}/mute")
            ->assertOk();

        $this->assertDatabaseHas('mutes', [
            'user_id' => $user->id,
            'muted_user_id' => $target->id,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/users/{$target->id}/mute")
            ->assertOk();

        $this->assertDatabaseMissing('mutes', [
            'user_id' => $user->id,
            'muted_user_id' => $target->id,
        ]);
    }

    public function test_muted_users_messages_are_hidden_from_the_muting_user(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create();
        $conversation = app(ConversationService::class)->directConversation($viewer, $target);

        Message::factory()->for($conversation)->for($target, 'user')->create();

        $this->actingAs($viewer)
            ->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($viewer)->postJson("/api/users/{$target->id}/mute")->assertOk();

        $this->actingAs($viewer)
            ->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($viewer)->deleteJson("/api/users/{$target->id}/mute")->assertOk();

        $this->actingAs($viewer)
            ->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_blocked_users_messages_are_hidden_from_the_blocker(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create();
        $conversation = app(ConversationService::class)->directConversation($viewer, $target);

        Message::factory()->for($conversation)->for($target, 'user')->create();

        $this->actingAs($viewer)->postJson("/api/users/{$target->id}/block")->assertOk();

        $this->actingAs($viewer)
            ->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_profile_reports_the_moderation_state_of_the_viewer(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($viewer)
            ->getJson("/api/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('moderation.blocked', false)
            ->assertJsonPath('moderation.muted', false);

        $this->actingAs($viewer)->postJson("/api/users/{$target->id}/block")->assertOk();
        $this->actingAs($viewer)->postJson("/api/users/{$target->id}/mute")->assertOk();

        $this->actingAs($viewer)
            ->getJson("/api/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('moderation.blocked', true)
            ->assertJsonPath('moderation.muted', true);
    }

    public function test_a_regular_user_cannot_reach_the_admin_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/admin/statistics')
            ->assertForbidden();
    }

    public function test_a_moderator_can_reach_the_admin_panel(): void
    {
        $moderator = User::factory()->moderator()->create();

        $this->actingAs($moderator)
            ->getJson('/api/admin/statistics')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['users', 'messages', 'presence', 'moderation'],
            ]);
    }

    public function test_only_an_administrator_can_ban_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $moderator = User::factory()->moderator()->create();
        $target = User::factory()->create();

        $this->actingAs($moderator)
            ->postJson("/api/admin/users/{$target->id}/ban", ['reason' => 'Spam'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$target->id}/ban", ['reason' => 'Spam'])
            ->assertOk();

        $this->assertDatabaseHas('bans', [
            'user_id' => $target->id,
            'banned_by' => $admin->id,
        ]);

        $this->assertTrue($target->fresh()->is_banned);
    }

    public function test_an_administrator_can_lift_a_ban(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/ban")->assertOk();
        $this->actingAs($admin)->deleteJson("/api/admin/users/{$target->id}/ban")->assertOk();

        $this->assertFalse($target->fresh()->is_banned);
        $this->assertNotNull(Ban::query()->where('user_id', $target->id)->firstOrFail()->revoked_at);
    }

    public function test_an_administrator_can_change_a_role(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'moderator'])
            ->assertOk()
            ->assertJsonPath('user.role', 'moderator');

        $this->assertSame('moderator', $target->fresh()->role->value);
    }

    public function test_an_administrator_can_resolve_a_report(): void
    {
        $admin = User::factory()->admin()->create();
        $report = Report::factory()->create();

        $this->actingAs($admin)
            ->patchJson("/api/admin/reports/{$report->id}", ['status' => 'resolved'])
            ->assertOk();

        $this->assertSame('resolved', $report->fresh()->status);
    }

    public function test_an_administrator_can_delete_a_reported_message(): void
    {
        $admin = User::factory()->admin()->create();
        $message = Message::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/messages/{$message->id}")
            ->assertOk();

        $this->assertSoftDeleted($message);
    }

    public function test_the_admin_panel_requires_authentication(): void
    {
        $this->getJson('/api/admin/statistics')->assertUnauthorized();
    }
}
