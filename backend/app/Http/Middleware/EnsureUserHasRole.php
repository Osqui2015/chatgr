<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to the given roles.
 */
class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $allowed = array_map('strval', $roles);

        if (! in_array($user->role->value, $allowed, true)) {
            return response()->json(['message' => 'No tienes permisos para esta acción.'], 403);
        }

        return $next($request);
    }
}
