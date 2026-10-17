<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends an account's other sign-ins (docs/MFA_SECURITY.md, "Sessions"): after a password reset, a password change, an
 * administrative two-step reset. Sessions are rows of the `sessions` table here (SESSION_DRIVER=database); with any other
 * driver nothing can be enumerated, so no session is deleted. The remember-me token is rotated as well, so a "remember me"
 * cookie issued before is worthless, and the account's API tokens (Sanctum, POST /api/v1/auth/login) are revoked: they
 * never expire on their own.
 */
final class SessionTerminator
{
    /** @return array{sessions: int, api_tokens: int} */
    public static function endSessionsOf(User $user, ?string $keepSessionId = null): array
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        $tokens = self::revokeApiTokensOf($user);

        $sessions = 0;
        if (config('session.driver') === 'database') {
            $sessions = DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
                ->delete();
        }

        return ['sessions' => $sessions, 'api_tokens' => $tokens];
    }

    /** Every API token of the account. */
    public static function revokeApiTokensOf(User $user): int
    {
        return $user->tokens()->delete();
    }
}
