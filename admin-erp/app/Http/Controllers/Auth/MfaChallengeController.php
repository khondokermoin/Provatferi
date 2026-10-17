<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Mfa\MfaManager;
use App\Services\SecurityEventLogger;
use App\Support\Mfa\MfaSession;
use App\Support\Mfa\MfaThrottle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The second step of signing in (docs/MFA_SECURITY.md, "Login flow"). The password was right, the person is NOT signed in
 * yet: MfaSession holds whose challenge this is for ten minutes. A code from the authenticator, or one recovery code, ends
 * it with a full sign-in (a new session id, "remember me" honoured only now). The error for a wrong code is the same
 * whatever the reason, and never says whether a recovery code existed or was used before.
 */
class MfaChallengeController extends Controller
{
    public function __construct(private readonly MfaManager $mfa, private readonly SecurityEventLogger $events)
    {
    }

    public function show(Request $request): View|RedirectResponse
    {
        $user = MfaSession::pendingUser($request);
        if ($user === null) {
            return $this->expired($request);
        }

        return view('auth.mfa-challenge', [
            'mode' => $request->query('method') === 'recovery' ? 'recovery' : 'code',
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = MfaSession::pendingUser($request);
        if ($user === null) {
            return $this->expired($request);
        }
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        $account = (string) $user->getKey();
        if (($seconds = MfaThrottle::blockedFor('challenge', $account, $request)) !== null) {
            return $this->failed('code', __('admin.mfa.throttled', ['minutes' => (int) ceil($seconds / 60)]));
        }
        if (! $this->mfa->verifyCode($user, (string) $request->input('code'))) {
            $this->countFailure('challenge', $user, $request);

            return $this->failed('code', __('admin.mfa.code_invalid'));
        }

        MfaThrottle::clear('challenge', $account, $request);

        return $this->signIn($request, $user)->intended(route('dashboard', absolute: false));
    }

    public function recovery(Request $request): RedirectResponse
    {
        $user = MfaSession::pendingUser($request);
        if ($user === null) {
            return $this->expired($request);
        }
        $request->validate(['recovery_code' => ['required', 'string', 'max:40']]);

        $account = (string) $user->getKey();
        if (($seconds = MfaThrottle::blockedFor('recovery', $account, $request)) !== null) {
            return $this->failed('recovery_code', __('admin.mfa.throttled', ['minutes' => (int) ceil($seconds / 60)]), 'recovery');
        }
        if (! $this->mfa->useRecoveryCode($user, (string) $request->input('recovery_code'))) {
            $this->countFailure('recovery', $user, $request);

            return $this->failed('recovery_code', __('admin.mfa.recovery_invalid'), 'recovery');
        }

        MfaThrottle::clear('recovery', $account, $request);
        $remaining = $this->mfa->remainingRecoveryCodes($user->fresh());
        $this->signIn($request, $user);

        // Signing in with a recovery code usually means the phone is gone: go straight to where that is put right.
        return redirect()->route('admin.account.security')
            ->with('warning', __('admin.mfa.signed_in_with_recovery', ['remaining' => $remaining]));
    }

    public function cancel(Request $request): RedirectResponse
    {
        MfaSession::clearPending($request);
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function signIn(Request $request, User $user): \Illuminate\Routing\Redirector
    {
        $remember = MfaSession::pendingRemember($request);
        MfaSession::clearPending($request);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        MfaSession::markVerified($request, $user);

        return redirect();
    }

    private function countFailure(string $scope, User $user, Request $request): void
    {
        if (MfaThrottle::recordFailure($scope, (string) $user->getKey(), $request)) {
            // Aggregated: one row when an account's allowance runs out, not one per mistake.
            $this->events->record(SecurityEvent::MFA_CHALLENGE_LOCKED, $user, ['scope' => $scope], null, 'system');
        }
    }

    private function failed(string $field, string $message, string $mode = 'code'): RedirectResponse
    {
        return redirect()->route('mfa.challenge', $mode === 'recovery' ? ['method' => 'recovery'] : [])
            ->withErrors([$field => $message]);
    }

    private function expired(Request $request): RedirectResponse
    {
        MfaSession::clearPending($request);

        return redirect()->route('login')->withErrors(['email' => __('admin.mfa.challenge_expired')]);
    }
}
