<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\TrackPresence;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'presence' => TrackPresence::class,
        ]);

        // The SPA is served from a different origin during development, so the
        // API routes must accept a cross-site credentialed request. The
        // session cookie is still protected by the CSRF token and by
        // SameSite, and every state changing request is verified by the
        // XSRF-TOKEN header that Axios sends automatically.
        $middleware->web(append: [
            TrackPresence::class,
        ]);

        // The SPA authenticates with a Sanctum cookie session, so the API
        // routes need the session middleware to read and write that cookie.
        // `PreventRequestForgery` is added explicitly because the `api` group
        // does not include it by default; without it the cookie session would
        // be vulnerable to cross-site request forgery.
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            PreventRequestForgery::class,
            TrackPresence::class,
        ]);

        // Trust the proxy headers so the real client IP is used for presence
        // and rate limiting behind the reverse proxy on the VPS.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Unauthenticated API calls must answer with 401 instead of a redirect
        // to the login page.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Tu sesión ha expirado. Inicia sesión de nuevo.',
                ], 401);
            }

            return null;
        });
    })->create();
