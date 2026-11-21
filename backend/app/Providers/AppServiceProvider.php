<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Policies\ConversationPolicy;
use App\Policies\MessagePolicy;
use App\Policies\UserPolicy;
use App\Services\PresenceService;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the application services, policies and broadcast channels.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerBroadcastChannels();
    }

    /**
     * Map the domain models to their authorization policies.
     */
    private function registerPolicies(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
    }

    /**
     * Authorise the broadcast channels.
     */
    private function registerBroadcastChannels(): void
    {
        Broadcast::channel('App.Models.User.{id}', function (User $user, int $id): bool {
            return $user->id === $id;
        });

        // Only verified accounts may listen to the shared presence channel.
        Broadcast::channel('presence.global', function (User $user): array {
            return [
                'id' => $user->id,
                'nickname' => $user->nickname,
                'status' => app(PresenceService::class)->effectiveStatus($user)->value,
            ];
        });

        // A private conversation channel is limited to its participants.
        Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId): bool {
            $conversation = Conversation::query()->find($conversationId);

            if ($conversation === null) {
                return false;
            }

            if ($conversation->isGlobal()) {
                return $user->email_verified_at !== null;
            }

            return $conversation->users()->where('users.id', $user->id)->exists();
        });
    }
}
