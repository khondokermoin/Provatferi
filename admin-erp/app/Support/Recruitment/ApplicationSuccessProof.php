<?php

namespace App\Support\Recruitment;

use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * The proof that "this browser has just submitted this application" (docs/VOLUNTEER_MESSENGER_GROUP.md, "Success-page
 * gating"). The application endpoint issues one when it accepts an application; the public site keeps it in an HttpOnly
 * cookie and hands it back to the verification endpoint on every load of the confirmation page. The Messenger invite is
 * released only for a proof that verifies.
 *
 * Opaque and tamper-proof: the payload is AUTHENTICATED-ENCRYPTED (AES-256-GCM) under a key derived from APP_KEY for this
 * purpose alone, so nothing readable (no application id, no posting id, no e-mail or phone) travels in the cookie, a
 * single flipped character fails verification, and a blob made for another purpose (a session, an MFA secret) cannot be
 * passed off as one. It carries: the application, the posting it was made for, an expiry and a random nonce.
 *
 * Verification is never "the cookie exists": it decrypts, checks the purpose and version, the expiry, that the posting in
 * the address is the one the proof was made for (a proof for posting A shows nothing on posting B's page), and that the
 * application still exists and is still a live one (an admin who archives, withdraws or declines it within the window
 * revokes the proof).
 *
 * Replays are allowed on purpose, for TTL_SECONDS: a refresh, the back button, a reopened tab. After that the page asks
 * for a new application. It is a short-lived credential in an HttpOnly, path-scoped cookie, never a URL.
 */
final class ApplicationSuccessProof
{
    /** Thirty minutes: long enough for reloads and a trip to the Messenger app, short enough that a left-open device stops granting it. */
    public const TTL_SECONDS = 1800;

    private const PURPOSE = 'volunteer-application-success';

    private const VERSION = 1;

    /**
     * @return array{proof: string, expires_in: int}
     */
    public static function issue(JobApplication $application, ?int $now = null): array
    {
        $now ??= now()->getTimestamp();
        $payload = json_encode([
            'v' => self::VERSION,
            'k' => self::PURPOSE,
            'a' => (int) $application->getKey(),
            'p' => (int) $application->job_posting_id,
            'x' => $now + self::TTL_SECONDS,
            'n' => Str::random(16),
        ], JSON_THROW_ON_ERROR);

        return ['proof' => self::encrypter()->encryptString($payload), 'expires_in' => self::TTL_SECONDS];
    }

    /**
     * The application the proof was issued for, or null when the proof is missing, forged, altered, expired, made for
     * another posting or purpose, or its application is gone or no longer live. The reason is deliberately not returned.
     */
    public static function verify(?string $proof, JobPosting $posting, ?int $now = null): ?JobApplication
    {
        if (! is_string($proof) || $proof === '' || strlen($proof) > 2048) {
            return null;
        }

        try {
            $payload = json_decode(self::encrypter()->decryptString($proof), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['v'] ?? null) !== self::VERSION
            || ($payload['k'] ?? null) !== self::PURPOSE
            || ! is_int($payload['a'] ?? null)
            || ! is_int($payload['p'] ?? null)
            || ! is_int($payload['x'] ?? null)) {
            return null;
        }
        if (($now ?? now()->getTimestamp()) >= $payload['x'] || $payload['p'] !== (int) $posting->getKey()) {
            return null;
        }

        $application = JobApplication::query()->whereKey($payload['a'])->where('job_posting_id', $posting->getKey())->first();
        if ($application === null || in_array($application->status, JobApplication::REAPPLY_ALLOWED_AFTER, true)) {
            return null;
        }

        return $application;
    }

    private static function encrypter(): Encrypter
    {
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new RuntimeException('The application key is not set.');
        }
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        return new Encrypter(hash_hmac('sha256', 'provatferi-volunteer-application-success-v1', $appKey, true), 'AES-256-GCM');
    }
}
