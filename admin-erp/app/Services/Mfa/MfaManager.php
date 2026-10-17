<?php

namespace App\Services\Mfa;

use App\Jobs\SendMfaSecurityNotification;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\SecurityEventLogger;
use App\Support\SessionTerminator;
use Illuminate\Support\Facades\DB;

/**
 * Every write to an account's two-step verification goes through here (docs/MFA_SECURITY.md): enrolment, sign-in codes,
 * recovery codes, turning it off, an administrative reset. Each state change is audited (SecurityEventLogger) and, where it
 * matters to the person, announced by a security e-mail (From no-reply@, Reply-To security@) that never contains a secret
 * or a code.
 *
 * Concurrency: code checks lock the account's row, so two requests carrying the same code (or the same recovery code)
 * cannot both succeed.
 */
class MfaManager
{
    /** An enrolment that is not finished within half an hour must be started again (with a new secret). */
    public const PENDING_ENROLLMENT_TTL = 1800;

    public function __construct(
        private readonly TotpService $totp,
        private readonly RecoveryCodes $codes,
        private readonly SecurityEventLogger $events,
    ) {
    }

    /**
     * Starts (or restarts) an enrolment with a NEW secret, stored encrypted and inactive. An existing, active secret keeps
     * working until the new one is confirmed, so replacing a device can never lock the account out half-way.
     */
    public function startEnrollment(User $user): string
    {
        $secret = $this->totp->generateSecret();
        $user->forceFill(['mfa_pending_secret' => $secret, 'mfa_pending_created_at' => now()])->save();
        $this->events->record(SecurityEvent::MFA_ENROLLMENT_STARTED, $user, ['replacing' => $user->hasMfaEnabled()]);

        return $secret;
    }

    /** The secret of the enrolment in progress, or null when there is none or it expired. */
    public function pendingSecret(User $user): ?string
    {
        if ($user->mfa_pending_secret === null || $user->mfa_pending_created_at === null) {
            return null;
        }
        if ($user->mfa_pending_created_at->diffInSeconds(now()) > self::PENDING_ENROLLMENT_TTL) {
            return null;
        }

        return $user->mfa_pending_secret;
    }

    /**
     * Finishes an enrolment when the code was produced by the NEW secret. Returns the plain recovery codes (to be shown
     * once, never stored) or null when the code is wrong or the enrolment expired.
     *
     * @return list<string>|null
     */
    public function confirmEnrollment(User $user, #[\SensitiveParameter] string $code): ?array
    {
        $plain = null;

        DB::transaction(function () use ($user, $code, &$plain): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $secret = $this->pendingSecret($locked);
            if ($secret === null) {
                return;
            }
            $step = $this->totp->verify($secret, $code, null);
            if ($step === null) {
                return;
            }

            $replacing = $locked->hasMfaEnabled();
            $plain = $this->codes->generate();
            $locked->forceFill([
                'mfa_secret' => $secret,
                'mfa_pending_secret' => null,
                'mfa_pending_created_at' => null,
                'mfa_confirmed_at' => now(),
                'mfa_last_used_step' => $step,
                'mfa_recovery_codes' => $this->codes->hashAll($plain),
                'mfa_enrollment_required_at' => null,
            ])->save();

            // Web sessions opened before this moment are sent through the challenge by EnsureMfa; an API token cannot be,
            // so every token issued before it (with a password alone, or for the old authenticator) is revoked.
            $revoked = SessionTerminator::revokeApiTokensOf($locked);

            $event = $replacing ? SecurityEvent::MFA_REPLACED : SecurityEvent::MFA_ENABLED;
            $this->events->record($event, $locked, ['recovery_codes_issued' => count($plain), 'api_tokens_revoked' => $revoked]);
            SendMfaSecurityNotification::forUser($locked, $replacing ? 'replaced' : 'enabled');
        });

        $user->refresh();

        return $plain;
    }

    /** A sign-in or step-up code from the authenticator. A code is accepted once, and only inside the clock window. */
    public function verifyCode(User $user, #[\SensitiveParameter] string $code): bool
    {
        if (! $user->hasMfaEnabled()) {
            return false;
        }

        $accepted = DB::transaction(function () use ($user, $code): bool {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $secret = $locked->mfa_secret;
            if (! is_string($secret) || $secret === '') {
                return false;
            }
            $step = $this->totp->verify($secret, $code, $locked->mfa_last_used_step);
            if ($step === null) {
                return false;
            }
            $locked->forceFill(['mfa_last_used_step' => $step])->save();

            return true;
        });

        $user->refresh();

        return $accepted;
    }

    /** Uses up one recovery code. The answer is the same for a code that never existed and one already used. */
    public function useRecoveryCode(User $user, #[\SensitiveParameter] string $code): bool
    {
        if (! $user->hasMfaEnabled() || ! $this->codes->looksValid($code)) {
            return false;
        }

        $remaining = null;
        DB::transaction(function () use ($user, $code, &$remaining): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $hashes = array_values(array_filter((array) $locked->mfa_recovery_codes, 'is_string'));
            $index = $this->codes->find($code, $hashes);
            if ($index === null) {
                return;
            }
            array_splice($hashes, $index, 1);
            $locked->forceFill(['mfa_recovery_codes' => $hashes])->save();
            $remaining = count($hashes);
        });

        if ($remaining === null) {
            return false;
        }

        $user->refresh();
        $this->events->record(SecurityEvent::MFA_RECOVERY_CODE_USED, $user, ['remaining' => $remaining]);
        SendMfaSecurityNotification::forUser($user, 'code_used', ['remaining' => $remaining]);

        return true;
    }

    /**
     * New recovery codes; every earlier code stops working at this moment.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $plain = $this->codes->generate();
        $user->forceFill(['mfa_recovery_codes' => $this->codes->hashAll($plain)])->save();
        $this->events->record(SecurityEvent::MFA_RECOVERY_CODES_REGENERATED, $user, ['recovery_codes_issued' => count($plain)]);
        SendMfaSecurityNotification::forUser($user, 'codes_regenerated');

        return $plain;
    }

    /** Turns two-step verification off (the caller has checked the policy, the password and a current code). */
    public function disable(User $user): void
    {
        $this->clear($user);
        $this->events->record(SecurityEvent::MFA_DISABLED, $user);
        SendMfaSecurityNotification::forUser($user, 'disabled');
    }

    /**
     * The break-glass reset (docs/MFA_SECURITY.md, "Recovery when everything is lost"), reachable ONLY from the server's
     * console (php artisan mfa:reset): the secret and every recovery code are destroyed, every session and remember-me
     * cookie of the account ends, and the account must enrol again at its next sign-in.
     */
    public function administrativeReset(User $user, string $reason): void
    {
        $this->clear($user, ['mfa_enrollment_required_at' => now()]);
        $ended = SessionTerminator::endSessionsOf($user);
        $this->events->record(SecurityEvent::MFA_RESET, $user, [
            'reason' => mb_substr($reason, 0, 200),
            'sessions_ended' => $ended['sessions'],
            'api_tokens_revoked' => $ended['api_tokens'],
        ], null, 'console');
        SendMfaSecurityNotification::forUser($user, 'reset');
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count(array_filter((array) $user->mfa_recovery_codes, 'is_string'));
    }

    /** @param array<string, mixed> $extra */
    private function clear(User $user, array $extra = []): void
    {
        $user->forceFill([
            'mfa_secret' => null,
            'mfa_pending_secret' => null,
            'mfa_pending_created_at' => null,
            'mfa_recovery_codes' => null,
            'mfa_confirmed_at' => null,
            'mfa_last_used_step' => null,
        ] + $extra)->save();
    }
}
