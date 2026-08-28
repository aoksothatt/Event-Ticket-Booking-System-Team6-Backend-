<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class PermissionMiddleware
{
    /**
     * Handle an incoming request by checking the user's permissions.
     *
     * Usage: ->middleware('permission:create_bookings')
     *        ->middleware('permission:create_bookings,make_payments')  (ANY of them passes)
     *
     * The permission list for each role lives in config/permissions.php.
     * Admin bypasses every check (super role).
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = JWTAuth::parseToken()->authenticate();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($user->hasAnyPermission(...$permissions)) {
            $request->setUserResolver(fn () => $user);

            return $next($request);
        }

        return response()->json([
            'message' => 'Unauthorized. You do not have the required permission.',
            'required_permissions' => $permissions,
            'your_role' => $user->role,
        ], 403);
    }
}