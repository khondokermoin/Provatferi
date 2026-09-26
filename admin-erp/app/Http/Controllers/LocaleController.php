<?php

namespace App\Http\Controllers;

use App\Support\AdminLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 3 — switches the admin panel's UI language without changing the URL.
 *
 * Deliberately a POST, not a GET link: this writes a durable preference to
 * the user's row, so it must not be triggerable by a prefetch, a crawler, or
 * an <img src>. CSRF protection therefore applies as it does to every other
 * state-changing admin action.
 *
 * Open to any authenticated user — this is a personal display preference, not
 * a privileged operation, so it carries no `permission:` middleware and
 * cannot alter anyone else's account or any stored content.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(AdminLocale::codes())],
        ]);

        $locale = $data['locale'];

        $request->session()->put(AdminLocale::SESSION_KEY, $locale);

        // Persist to the account so the choice follows this admin to any
        // browser or device, not just this one.
        $user = $request->user();
        if ($user !== null && $user->ui_locale !== $locale) {
            $user->forceFill(['ui_locale' => $locale])->save();
        }

        // `back()` keeps the admin exactly where they were — switching
        // language must never cost them their place or an unsaved filter.
        return back()->withCookie(cookie(
            AdminLocale::COOKIE_NAME,
            $locale,
            AdminLocale::COOKIE_MINUTES,
            secure: $request->isSecure(),
            httpOnly: false,
        ));
    }
}
