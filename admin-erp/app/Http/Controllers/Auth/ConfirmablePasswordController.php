<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Mfa\MfaThrottle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password view.
     */
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    /**
     * Confirm the user's password. Rate-limited per account and address (docs/MFA_SECURITY.md, "Rate limiting"): this
     * confirmation opens two-step enrolment and new recovery codes.
     */
    public function store(Request $request): RedirectResponse
    {
        $account = (string) $request->user()->getKey();
        if (($seconds = MfaThrottle::blockedFor('password-confirm', $account, $request)) !== null) {
            throw ValidationException::withMessages([
                'password' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ]);
        }

        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->password,
        ])) {
            MfaThrottle::recordFailure('password-confirm', $account, $request);

            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        MfaThrottle::clear('password-confirm', $account, $request);
        $request->session()->put('auth.password_confirmed_at', now()->getTimestamp());

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
