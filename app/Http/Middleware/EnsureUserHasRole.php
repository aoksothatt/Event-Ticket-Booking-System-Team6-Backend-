<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Throwable) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Role is always read from the database user, never trusted from the client.
        if (! $user->hasRole(...$roles)) {
            return response()->json([
                'message' => 'Unauthorized. Your role does not have access to this resource.',
                'required_roles' => $roles,
                'your_role' => $user->role,
            ], 403);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
