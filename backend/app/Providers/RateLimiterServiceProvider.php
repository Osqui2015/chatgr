<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Registers the application rate limiters.
 */
class RateLimiterServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureLimiters();
    }

    /**
     * Define the named rate limiters used by the routes.
     */
    private function configureLimiters(): void
    {
        // Login attempts are limited per account *and* per IP so that a single
        // account cannot be brute forced and a single IP cannot spray many
        // accounts.
        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute((int) config('chat.rate_limits.login', 10))
                ->by($email.'|'.$request->ip());
        });

        // Verification codes are the most abusable endpoint: limit per email.
        RateLimiter::for('otp', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(3)->by('otp|'.$email.'|'.$request->ip());
        });

        // Uploads are expensive: limit per authenticated user.
        RateLimiter::for('upload', function (Request $request): Limit {
            $userId = $request->user()->id ?? $request->ip();

            return Limit::perMinute((int) config('chat.rate_limits.upload', 20))->by('upload|'.$userId);
        });

        // General API traffic.
        RateLimiter::for('api', function (Request $request): Limit {
            $userId = $request->user()->id;

            return Limit::perMinute((int) config('chat.rate_limits.api', 120))
                ->by($userId !== null ? 'user|'.$userId : 'ip|'.$request->ip());
        });

        // Moderation endpoints are only reachable by staff but are still
        // capped to keep the reporting tables bounded.
        RateLimiter::for('moderation', function (Request $request): Limit {
            return Limit::perMinute(60)->by('moderation|'.($request->user()->id ?? $request->ip()));
        });
    }
}
