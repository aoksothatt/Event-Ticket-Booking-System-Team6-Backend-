<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = JWTAuth::parseToken()->authenticate();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (! $user->hasRole(...$roles)) {
            return response()->json([
                'message' => 'Unauthorized. Your role "'.$user->role.'" does not have access to this resource.',
                'required_roles' => $roles,
                'your_role' => $user->role,
            ], 403);
        }

        // Bind authenticated user to request for downstream use
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
