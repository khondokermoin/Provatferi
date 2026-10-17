<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Writes the account-security audit trail (docs/MFA_SECURITY.md, "Audit"). One row per event: who it is about, who did it,
 * when, from which address and browser when there is a request. `context` may carry counts, role slugs and similar facts;
 * a key that could hold a credential is refused outright, so a careless caller cannot put a code or a secret in the table.
 */
class SecurityEventLogger
{
    /** Context keys that must never be written (a secret, a code, a password, a token or a session identifier). */
    private const FORBIDDEN_KEYS = ['secret', 'code', 'codes', 'recovery_code', 'recovery_codes', 'password', 'token', 'session', 'session_id', 'otp', 'totp', 'uri'];

    /**
     * @param  array<string, scalar|list<scalar>|null>  $context
     */
    public function record(string $event, ?User $user, array $context = [], ?User $actor = null, string $actorType = 'user'): void
    {
        foreach (array_keys($context) as $key) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException("A security event may not carry the field \"{$key}\".");
            }
        }

        $request = self::currentRequest();

        try {
            SecurityEvent::query()->create([
                'user_id' => $user?->getKey(),
                'actor_id' => $actor?->getKey() ?? ($actorType === 'user' ? $user?->getKey() : null),
                'actor_type' => $actorType,
                'event' => $event,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'context' => $context === [] ? null : $context,
            ]);
        } catch (Throwable $e) {
            // The audit trail must never break a sign-in; a failure is reported without any request data.
            Log::error('Security event could not be recorded.', ['event' => $event, 'exception' => $e::class]);
        }
    }

    /** The web request being served, or null on the console (artisan, cron), where no address or browser applies. */
    public static function currentRequest(): ?Request
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        return request();
    }
}
