<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Mfa\MfaPolicy;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two-step rule for staff API tokens (docs/MFA_SECURITY.md, "API tokens"), on every auth:sanctum staff route except
 * logout. EnsureMfa guards the web session; a token never passes through it, so:
 *
 *  - a token of an account with two-step verification must be younger than its activation (POST /api/v1/auth/login asks
 *    for a code from then on, and turning it on revokes older tokens — this refuses any that escaped, and deletes it);
 *  - an account that must use two-step verification but has not set it up is refused, exactly like on the web.
 */
class EnsureApiMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        if ($user->hasMfaEnabled() && $token instanceof PersonalAccessToken
            && ($token->created_at === null || $token->created_at->lt($user->mfa_confirmed_at))) {
            $token->delete();

            return response()->json(['message' => __('admin.mfa.api_token_outdated')], 401);
        }

        if (MfaPolicy::mustEnrollNow($user)) {
            return response()->json(['message' => __('admin.mfa.api_enrolment_required')], 403);
        }

        return $next($request);
    }
}
