<?php

namespace App\Http\Middleware;

use App\Support\AdminLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 3 — resolves the admin panel's UI language for this request.
 *
 * Precedence, highest first:
 *   1. The signed-in user's stored `ui_locale` — a deliberate, durable choice
 *      that follows them to any browser.
 *   2. The session — covers the request cycle right after switching, and the
 *      pre-login pages (login/reset-password) where there is no user yet.
 *   3. The cookie — survives a new session, so a visitor who set English on
 *      the login page before signing in still sees English next week.
 *   4. The application default (bn).
 *
 * The URL is deliberately NOT part of this: the admin panel keeps exactly one
 * set of routes, no /en/admin mirror (Phase 3 requirement 2).
 *
 * A signed-in user's stored preference is mirrored back into the session and
 * cookie so steps 2 and 3 stay warm for their next visit.
 */
class SetAdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $stored = $user?->ui_locale;

        $locale = AdminLocale::isSupported($stored)
            ? $stored
            : ($this->fromSession($request) ?? $this->fromCookie($request) ?? AdminLocale::DEFAULT);

        App::setLocale($locale);

        // Keep the session in step so the very next request resolves without
        // re-reading the user row or the cookie.
        if ($request->session()->get(AdminLocale::SESSION_KEY) !== $locale) {
            $request->session()->put(AdminLocale::SESSION_KEY, $locale);
        }

        $response = $next($request);

        // Refresh the cookie only when it disagrees, so a normal page view
        // doesn't rewrite a Set-Cookie header on every response.
        if ($request->cookie(AdminLocale::COOKIE_NAME) !== $locale) {
            $response->headers->setCookie(cookie(
                AdminLocale::COOKIE_NAME,
                $locale,
                AdminLocale::COOKIE_MINUTES,
                secure: $request->isSecure(),
                httpOnly: false,
            ));
        }

        return $response;
    }

    private function fromSession(Request $request): ?string
    {
        $locale = $request->session()->get(AdminLocale::SESSION_KEY);

        return AdminLocale::isSupported($locale) ? $locale : null;
    }

    private function fromCookie(Request $request): ?string
    {
        $locale = $request->cookie(AdminLocale::COOKIE_NAME);

        return AdminLocale::isSupported($locale) ? $locale : null;
    }
}
