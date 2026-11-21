<?php

namespace App\Http\Middleware;

use App\Services\PresenceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the authenticated user as present and refreshes their presence TTL.
 *
 * The middleware runs on every authenticated API call, which doubles as a
 * heartbeat: clients poll it regularly, keeping the online set accurate even
 * if the WebSocket connection drops.
 */
class TrackPresence
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            app(PresenceService::class)->heartbeat($user);
        }

        return $next($request);
    }
}
