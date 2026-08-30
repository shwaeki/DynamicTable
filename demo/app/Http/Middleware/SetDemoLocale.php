<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the locale chosen with the header switcher.
 *
 * This has to be middleware rather than a service provider: the session is not
 * started when providers boot.
 */
class SetDemoLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('demo_locale');

        if (is_string($locale) && in_array($locale, ['en', 'ar', 'he', 'ru'], true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
