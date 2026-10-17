<?php

namespace App\Support\Mfa;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Temporary cool-downs for the sign-in and two-step endpoints (docs/MFA_SECURITY.md, "Rate limiting"). Only FAILURES count;
 * nothing ever bans an account: every limit decays on its own.
 *
 * Never keyed on the IP address alone — many people in Bangladesh share one address behind their ISP's NAT — but on the
 * ACCOUNT (stops a slow, distributed guess at one account), the SESSION (one browser hammering), and only then the IP with a
 * much larger allowance (one address hammering many accounts). A request is held back when ANY of its keys is exhausted.
 *
 * With six digits and a one-step window, an account allows at most 5 wrong codes per 5 minutes and 20 per hour: with the
 * password already required first, guessing a code inside the window this way is not practical.
 */
final class MfaThrottle
{
    /** scope => list of [key part, max failures, decay seconds] */
    private const LIMITS = [
        'login' => [['account', 20, 900], ['ip', 60, 600]], // plus the original 5/minute per account+IP (LoginRequest)
        'challenge' => [['account', 5, 300], ['account-hour', 20, 3600], ['session', 5, 300], ['ip', 40, 600]],
        'recovery' => [['account', 5, 900], ['session', 5, 900], ['ip', 40, 900]],
        'enroll' => [['account', 6, 600], ['ip', 40, 600]],
        'manage' => [['account', 5, 900], ['ip', 40, 900]],
        'step-up' => [['account', 5, 300], ['ip', 40, 600]],
        // "Confirm your password" guards enrolment and the recovery-code page; without a limit a borrowed session could
        // guess the password there.
        'password-confirm' => [['account', 10, 900], ['ip', 60, 900]],
    ];

    /** Seconds until the request may be tried again, or null when it may be tried now. */
    public static function blockedFor(string $scope, string $account, Request $request): ?int
    {
        $longest = null;
        foreach (self::keys($scope, $account, $request) as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $longest = max($longest ?? 0, RateLimiter::availableIn($key));
            }
        }

        return $longest;
    }

    /** Counts one failure; true when this failure exhausted the account's allowance (a lock-out worth an audit row). */
    public static function recordFailure(string $scope, string $account, Request $request): bool
    {
        $lockedNow = false;
        foreach (self::keys($scope, $account, $request) as [$key, $max, $decay, $part]) {
            $attempts = RateLimiter::hit($key, $decay);
            if ($attempts === $max && str_starts_with($part, 'account')) {
                $lockedNow = true;
            }
        }

        return $lockedNow;
    }

    /** A success clears the account's and the session's counters (the IP counter decays on its own). */
    public static function clear(string $scope, string $account, Request $request): void
    {
        foreach (self::keys($scope, $account, $request) as [$key, , , $part]) {
            if ($part !== 'ip') {
                RateLimiter::clear($key);
            }
        }
    }

    /** @return list<array{0: string, 1: int, 2: int, 3: string}> */
    private static function keys(string $scope, string $account, Request $request): array
    {
        $keys = [];
        foreach (self::LIMITS[$scope] as [$part, $max, $decay]) {
            // A token request (the API) has no session: it is held by the account and IP keys, which it shares with the
            // web forms, never by one "no session" key that every API caller would share.
            if ($part === 'session' && ! $request->hasSession()) {
                continue;
            }
            $identity = match ($part) {
                'account', 'account-hour' => 'a:'.strtolower($account),
                'session' => 's:'.hash('sha256', $request->session()->getId()),
                'ip' => 'i:'.$request->ip(),
            };
            $keys[] = ['mfa|'.$scope.'|'.$part.'|'.sha1($identity), $max, $decay, $part];
        }

        return $keys;
    }
}
