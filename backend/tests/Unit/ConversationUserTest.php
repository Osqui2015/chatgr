<?php

namespace Tests\Unit;

use App\Models\ConversationUser;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConversationUserTest extends TestCase
{
    public function test_conversation_user_casts_last_read_at_to_carbon(): void
    {
        $pivot = new ConversationUser();
        $pivot->setRawAttributes([
            'last_read_at' => '2026-11-20 12:00:00',
            'unread_count' => '5',
        ]);

        $this->assertInstanceOf(Carbon::class, $pivot->last_read_at);
        $this->assertSame(5, $pivot->unread_count);
        $this->assertSame('2026-11-20T12:00:00.000000Z', $pivot->last_read_at->toISOString());
    }
}
