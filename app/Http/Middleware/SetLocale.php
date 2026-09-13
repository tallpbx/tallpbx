<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetLocale
 *
 * Detects and applies the user's language preference via three mechanisms
 * in priority order:
 *
 * 1. URL segment (e.g. /en/dashboard, /es/store) — SEO-friendly locale routing.
 * 2. Session value — persisted from a previous visit or the language switcher.
 * 3. App default — fallback when no preference is available.
 *
 * If the user is authenticated (web or admin guard), their language
 * preference is also persisted to the database for transactional email
 * targeting via HasLocalePreference.
 */
class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * Detects the locale from the first URL segment, session, or app default,
     * applies it to the application, and persists it for authenticated users.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->segment(1);

        if (in_array($locale, ['en', 'es', 'fr'])) {
            app()->setLocale($locale);
            session()->put('locale', $locale);

            if (Auth::guard('web')->check()) {
                Auth::guard('web')->user()->update(['language' => $locale]);
            }

            if (Auth::guard('admin')->check()) {
                Auth::guard('admin')->user()->update(['language' => $locale]);
            }
        } elseif (session()->has('locale')) {
            app()->setLocale(session()->get('locale'));
        }

        return $next($request);
    }
}
