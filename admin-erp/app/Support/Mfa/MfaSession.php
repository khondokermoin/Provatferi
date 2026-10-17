<?php

namespace App\Support\Mfa;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The server-side session state of two-step verification (docs/MFA_SECURITY.md, "Login flow" and "Step-up"). Everything
 * here lives in the server-side session; nothing a browser sends (a field, a header, a cookie value) is ever believed.
 *
 *   mfa.pending       after a correct password, BEFORE the second step: whose challenge this is, whether "remember me"
 *                     was ticked, when it started. The person is NOT signed in while this exists (no guard user).
 *   mfa.verified      the account whose second step this session completed. A signed-in session of an account with
 *                     two-step verification that lacks it (a remember-me sign-in, or one opened before it was turned on)
 *                     is sent back through the challenge.
 *   mfa.confirmed_at  when this session last proved possession of the authenticator (sign-in or a step-up check); the
 *                     "recent MFA" that sensitive actions require.
 *
 * Times are Unix seconds from the application clock (now()), the same clock Laravel's own password confirmation uses.
 */
final class MfaSession
{
    private const PENDING = 'mfa.pending';

    private const VERIFIED = 'mfa.verified';

    private const CONFIRMED_AT = 'mfa.confirmed_at';

    /** A password-correct challenge expires after ten minutes: the password must then be entered again. */
    public const PENDING_TTL = 600;

    /** "Recent" for a step-up check: fifteen minutes. */
    public const STEP_UP_TTL = 900;

    public static function startPending(Request $request, User $user, bool $remember): void
    {
        $request->session()->put(self::PENDING, ['user_id' => $user->getKey(), 'remember' => $remember, 'at' => self::now()]);
    }

    public static function hasPending(Request $request): bool
    {
        return self::pendingUser($request) !== null;
    }

    /** The account whose challenge is open — only while it is fresh and the account can still sign in with MFA. */
    public static function pendingUser(Request $request): ?User
    {
        if (! $request->hasSession()) {
            return null;
        }
        $pending = $request->session()->get(self::PENDING);
        if (! is_array($pending) || ! isset($pending['user_id'], $pending['at']) || self::now() - (int) $pending['at'] > self::PENDING_TTL) {
            return null;
        }
        $user = User::query()->find($pending['user_id']);

        return $user !== null && $user->status === 'active' && $user->hasMfaEnabled() ? $user : null;
    }

    public static function pendingRemember(Request $request): bool
    {
        $pending = $request->session()->get(self::PENDING);

        return is_array($pending) && ! empty($pending['remember']);
    }

    public static function clearPending(Request $request): void
    {
        $request->session()->forget(self::PENDING);
    }

    /** The second step is done for this account in this session. */
    public static function markVerified(Request $request, User $user): void
    {
        $request->session()->put(self::VERIFIED, $user->getKey());
        self::touchConfirmed($request);
    }

    public static function isVerified(Request $request, User $user): bool
    {
        return $request->hasSession() && $request->session()->get(self::VERIFIED) === $user->getKey();
    }

    public static function touchConfirmed(Request $request): void
    {
        $request->session()->put(self::CONFIRMED_AT, self::now());
    }

    public static function confirmedRecently(Request $request, int $seconds = self::STEP_UP_TTL): bool
    {
        $at = $request->hasSession() ? $request->session()->get(self::CONFIRMED_AT) : null;

        return is_int($at) && self::now() - $at <= $seconds;
    }

    public static function passwordConfirmedRecently(Request $request, int $seconds = self::STEP_UP_TTL): bool
    {
        $at = $request->hasSession() ? $request->session()->get('auth.password_confirmed_at') : null;

        return is_numeric($at) && self::now() - (int) $at <= $seconds;
    }

    private static function now(): int
    {
        return now()->getTimestamp();
    }
}
