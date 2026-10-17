<?php

namespace App\Http\Middleware;

use App\Models\SecurityEvent;
use App\Services\SecurityEventLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated staff account is signed out at its next request (docs/MFA_SECURITY.md, "Current authentication audit":
 * before 2026-10-17 an account switched to "inactive" could still sign in and keep its sessions). Sign-in itself refuses an
 * inactive account (LoginRequest); this ends the sessions that were already open.
 */
class EnsureActiveAccount
{
    public function __construct(private readonly SecurityEventLogger $events)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        // `status` is NOT NULL (default "active") in the table; a model built in memory without it has not been told
        // otherwise, so only an explicit non-active status signs the account out.
        if ($user === null || $user->status === null || $user->status === 'active') {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->events->record(SecurityEvent::INACTIVE_ACCOUNT_SIGNED_OUT, $user, [], null, 'system');

        if ($request->expectsJson()) {
            return response()->json(['message' => __('admin.mfa.account_inactive')], 401);
        }

        return redirect()->route('login')->withErrors(['email' => __('admin.mfa.account_inactive')]);
    }
}
