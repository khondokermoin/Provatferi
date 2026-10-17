<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\Mfa\MfaSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request. A correct password signs the account in only when it has no two-step
     * verification; otherwise the session (with a NEW id) holds a pending challenge and nobody is signed in until a code
     * from the authenticator, or a recovery code, completes it (MfaChallengeController).
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();
        $remember = $request->boolean('remember');

        if ($user->hasMfaEnabled()) {
            $request->session()->regenerate();
            MfaSession::startPending($request, $user, $remember);

            return redirect()->route('mfa.challenge');
        }

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
