<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PlatformMaintenance
{
    /**
     * When system.maintenance_mode is enabled, reject every API request
     * except those the platform still needs to function while closed:
     *   - the /api/admin/* routes (administrators keep full access, including
     *     the Settings screen used to turn maintenance back off);
     *   - the public settings endpoint (so the SPA can fetch the maintenance
     *     state/message and surface it to users).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Setting::value('system.maintenance_mode', false)) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        if (str_starts_with($path, 'api/admin')
            || $path === 'api/settings/public') {
            return $next($request);
        }

        $message = Setting::value(
            'system.maintenance_message',
            "We're currently performing maintenance. Please come back later.",
        );

        return response()->json([
            'success' => false,
            'message' => (string) $message,
            'maintenance' => true,
        ], 503);
    }
}