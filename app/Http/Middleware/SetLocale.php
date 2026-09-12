<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetLocale
 *
 * Applies the actively-selected application locale (en/km) to every request.
 *
 * Resolution order:
 *   1. `X-Locale` request header (used by the SPA to pin its own language)
 *   2. `locale` request query parameter (`?locale=km`)
 *   3. the `locale` session value (written by GET /language/{locale})
 *   4. the `Accept-Language` header
 *   5. the application default (`app.locale`)
 *
 * Any unknown value silently falls back to the default locale.
 */
class SetLocale
{
    protected const SUPPORTED = ['en', 'km'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('X-Locale')
            ?? $request->query('locale');

        // API requests are usually stateless — reading the session only
        // works when the request actually has a session store attached.
        if ($locale === null && $request->hasSession()) {
            $locale = $request->session()->get('locale');
        }

        $locale ??= $request->getPreferredLanguage(self::SUPPORTED);
        $locale ??= config('app.locale', 'en');

        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}