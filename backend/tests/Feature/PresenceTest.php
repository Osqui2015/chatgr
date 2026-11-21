<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Events\UserPresenceChanged;
use App\Models\User;
use App\Services\PresenceService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class PresenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_user_can_publish_each_presence_state(): void
    {
        $user = User::factory()->create();

        foreach (['online', 'away', 'busy', 'invisible'] as $status) {
            $this->actingAs($user)
                ->postJson('/api/users/me/status', ['status' => $status])
                ->assertOk()
                ->assertJsonPath('status', $status);

            $this->assertSame($status, $user->fresh()->status->value);
        }
    }

    public function test_changing_the_status_broadcasts_the_new_presence(): void
    {
        Event::fake([UserPresenceChanged::class]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/users/me/status', [
            'status' => 'busy',
        ]);

        $response->assertOk();

        Event::assertDispatched(
            UserPresenceChanged::class,
            fn (UserPresenceChanged $event): bool => $event->userId === $user->id
                && $event->status === 'busy'
                && $event->lastSeenAt !== null,
        );
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/users/me/status', ['status' => 'conectado'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_changing_the_status_requires_authentication(): void
    {
        $this->postJson('/api/users/me/status', ['status' => 'online'])
            ->assertUnauthorized();
    }

    public function test_marking_a_user_online_updates_redis_and_mysql(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Offline]);

        Redis::shouldReceive('setex')
            ->once()
            ->with(PresenceService::STATUS_KEY.$user->id, PresenceService::TTL_SECONDS, 'online');

        Redis::shouldReceive('sadd')
            ->once()
            ->with(PresenceService::ONLINE_SET, (string) $user->id);

        Redis::shouldReceive('expire')
            ->once()
            ->with(PresenceService::ONLINE_SET, PresenceService::TTL_SECONDS * 10);

        app(PresenceService::class)->markOnline($user);

        $user->refresh();

        $this->assertSame(UserStatus::Online, $user->status);
        $this->assertNotNull($user->last_seen_at);
    }

    public function test_the_observable_status_comes_from_redis_while_connected(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Away]);

        Redis::shouldReceive('sismember')
            ->once()
            ->with(PresenceService::ONLINE_SET, (string) $user->id)
            ->andReturn(true);

        Redis::shouldReceive('get')
            ->once()
            ->with(PresenceService::STATUS_KEY.$user->id)
            ->andReturn('busy');

        $this->assertSame(UserStatus::Busy, app(PresenceService::class)->effectiveStatus($user));
    }

    public function test_a_disconnected_user_always_appears_offline(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Online]);

        Redis::shouldReceive('sismember')
            ->once()
            ->andReturn(false);

        $this->assertSame(UserStatus::Offline, app(PresenceService::class)->effectiveStatus($user));
    }

    public function test_presence_falls_back_to_mysql_when_redis_is_unavailable(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Away]);

        Redis::shouldReceive('sismember')
            ->once()
            ->andThrow(new RuntimeException('Redis caído'));

        $service = app(PresenceService::class);

        $this->assertFalse($service->isOnline($user));
        $this->assertSame([], $service->onlineUserIds());
        $this->assertSame(0, $service->onlineCount());
    }

    public function test_online_user_ids_are_returned_as_integers(): void
    {
        Redis::shouldReceive('smembers')
            ->once()
            ->with(PresenceService::ONLINE_SET)
            ->andReturn(['7', '12']);

        Redis::shouldReceive('exists')->twice()->andReturn(1);

        $this->assertSame([7, 12], app(PresenceService::class)->onlineUserIds());
    }

    public function test_members_without_a_live_status_key_are_not_reported_online(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Offline]);

        Redis::shouldReceive('smembers')
            ->twice()
            ->with(PresenceService::ONLINE_SET)
            ->andReturn([(string) $user->id]);

        Redis::shouldReceive('exists')
            ->twice()
            ->with(PresenceService::STATUS_KEY.$user->id)
            ->andReturn(0);

        $service = app(PresenceService::class);

        $this->assertSame([], $service->onlineUserIds());
        $this->assertSame(0, $service->onlineCount());
    }
}
