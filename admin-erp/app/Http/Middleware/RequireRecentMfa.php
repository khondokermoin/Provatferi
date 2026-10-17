<?php

namespace App\Http\Middleware;

use App\Support\Mfa\MfaSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up authentication (docs/MFA_SECURITY.md, "Step-up"): the action needs a code from the authenticator entered within
 * the last fifteen minutes in THIS session. The time is the server's own record (MfaSession::confirmedRecently); no field,
 * header or cookie the browser sends can stand in for it.
 *
 *   ->middleware('mfa.recent')          the account must have two-step verification AND have used it recently
 *   ->middleware('mfa.recent:lenient')  an account without two-step verification passes (the action is then protected by
 *                                       whatever else the route requires, e.g. a recent password)
 *
 * Without a recent confirmation the person is sent to the confirmation page and brought back afterwards (a GET to the same
 * page; after a form, to the page the form was on).
 */
class RequireRecentMfa
{
    public function handle(Request $request, Closure $next, string $mode = 'strict'): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        if (! $user->hasMfaEnabled()) {
            if ($mode === 'lenient') {
                return $next($request);
            }

            return $request->expectsJson()
                ? response()->json(['message' => __('admin.mfa.step_up_needs_mfa')], 403)
                : redirect()->route('admin.account.security')->with('warning', __('admin.mfa.step_up_needs_mfa'));
        }

        if (MfaSession::confirmedRecently($request)) {
            return $next($request);
        }

        $request->session()->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : url()->previous());

        return $request->expectsJson()
            ? response()->json(['message' => __('admin.mfa.step_up_required')], 423)
            : redirect()->route('admin.account.security.step-up');
    }
}
