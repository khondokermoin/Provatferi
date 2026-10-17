<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\SecurityEvent;
use App\Services\Mfa\MfaManager;
use App\Services\SecurityEventLogger;
use App\Support\Mfa\MfaPolicy;
use App\Support\Mfa\MfaThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * Token-based login for API consumers (mobile apps, external integrations). The same rules as the web sign-in
     * (docs/MFA_SECURITY.md, "API tokens"): the same rate limits, an inactive account is refused like a wrong password,
     * an account with two-step verification must also send `code` (a current six-digit code from its authenticator), and
     * an account that must set it up gets no token until it has, in the admin panel.
     */
    public function login(LoginRequest $request, MfaManager $mfa, SecurityEventLogger $events): JsonResponse
    {
        $user = $request->authenticate();

        if (MfaPolicy::mustEnrollNow($user)) {
            return response()->json(['message' => __('admin.mfa.api_enrolment_required')], 403);
        }

        if ($user->hasMfaEnabled()) {
            $code = $request->input('code');
            if (! is_string($code) || trim($code) === '') {
                return response()->json(['message' => __('admin.mfa.api_code_required'), 'mfa_required' => true], 401);
            }

            $account = (string) $user->getKey();
            if (($seconds = MfaThrottle::blockedFor('challenge', $account, $request)) !== null) {
                return response()->json(['message' => __('admin.mfa.throttled', ['minutes' => (int) ceil($seconds / 60)])], 429);
            }
            if (mb_strlen($code) > 20 || ! $mfa->verifyCode($user, $code)) {
                if (MfaThrottle::recordFailure('challenge', $account, $request)) {
                    $events->record(SecurityEvent::MFA_CHALLENGE_LOCKED, $user, ['scope' => 'api'], null, 'system');
                }

                return response()->json(['message' => __('admin.mfa.code_invalid'), 'mfa_required' => true], 422);
            }
            MfaThrottle::clear('challenge', $account, $request);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'data' => ['user' => $user->load('roles'), 'token' => $token],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->load('roles.permissions')]);
    }
}
