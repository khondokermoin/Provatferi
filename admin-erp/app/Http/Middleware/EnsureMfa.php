<?php

namespace App\Http\Middleware;

use App\Support\Mfa\MfaPolicy;
use App\Support\Mfa\MfaSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two-step verification gate of every web request (docs/MFA_SECURITY.md, "Login flow"). Global on the web group, so a
 * new route cannot forget it.
 *
 *  - A signed-in session of an account WITH two-step verification that has not completed the second step in THIS session
 *    (a "remember me" sign-in, or a session opened before it was turned on) is taken back to the challenge: it is signed
 *    out of this device (other devices' remember-me cookies are untouched), its session id is renewed, and the challenge
 *    is opened for the same account. A password alone, or a remember-me cookie alone, never reaches an admin page.
 *  - An account that must use it (MfaPolicy) but has not set it up yet can reach the enrolment pages only (and sign out,
 *    confirm its password, change the language); every other page sends it to the Security page.
 */
class EnsureMfa
{
    /** What an account that must enrol may still reach. */
    private const ENROLMENT_ROUTES = [
        'admin.account.security',
        'admin.account.security.setup',
        'admin.account.security.restart',
        'admin.account.security.enable',
        'password.confirm',
        'password.confirm.store',
        'logout',
        'locale.update',
        'verification.notice',
        'verification.verify',
        'verification.send',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if ($user === null) {
            return $next($request);
        }

        if ($user->hasMfaEnabled()) {
            if (MfaSession::isVerified($request, $user)) {
                return $next($request);
            }

            $remember = Auth::guard('web')->viaRemember();
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->migrate(true);
            MfaSession::startPending($request, $user, $remember);
            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return $request->expectsJson()
                ? response()->json(['message' => __('admin.mfa.challenge_required')], 401)
                : redirect()->route('mfa.challenge');
        }

        if (MfaPolicy::mustEnrollNow($user) && ! in_array($request->route()?->getName(), self::ENROLMENT_ROUTES, true)) {
            return $request->expectsJson()
                ? response()->json(['message' => __('admin.mfa.enrolment_required')], 403)
                : redirect()->route('admin.account.security')->with('warning', __('admin.mfa.enrolment_required'));
        }

        return $next($request);
    }
}
