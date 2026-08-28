<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Role-based access control middleware.
     * Usage: ->middleware('role:admin') or ->middleware('role:admin,organizer')
     *
     * ┌──────────────┬──────────────────────────────────────────────────────────────┐
     * │ Role         │ Permissions                                                   │
     * ├──────────────┼──────────────────────────────────────────────────────────────┤
     * │ admin        │ Full access to everything:                                    │
     * │              │ - CRUD users, organizers, venues, categories                  │
     * │              │ - Manage all events, bookings, payments, reviews              │
     * │              │ - Override any action across the system                        │
     * ├──────────────┼──────────────────────────────────────────────────────────────┤
     * │ organizer    │ Event & ticket management for own events only:                │
     * │              │ - Create / update / delete own events                          │
     * │              │ - Manage ticket types for own events                           │
     * │              │ - View bookings for own events                                 │
     * │              │ - Manage own organizer profile                                 │
     * │              │ - Upload event images for own events                           │
     * │              │ - View attendees / check-ins for own events                    │
     * ├──────────────┼──────────────────────────────────────────────────────────────┤
     * │ customer     │ End-user browsing & booking:                                   │
     * │              │ - Browse events, venues, categories (read-only)               │
     * │              │ - Create / view / cancel own bookings                          │
     * │              │ - Make payments for own bookings                               │
     * │              │ - Leave reviews on events                                      │
     * │              │ - Manage own profile                                           │
     * └──────────────┴──────────────────────────────────────────────────────────────┘
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = JWTAuth::parseToken()->authenticate();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (!$user->hasRole(...$roles)) {
            return response()->json([
                'message' => 'Unauthorized. Your role "' . $user->role . '" does not have access to this resource.',
                'required_roles' => $roles,
                'your_role' => $user->role,
            ], 403);
        }

        // Bind authenticated user to request for downstream use
        $request->setUserResolver(fn() => $user);

        return $next($request);
    }
}
