<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Who the visitor behind a public request actually is (docs/RATE_LIMITING_ARCHITECTURE.md).
 *
 * THE PROBLEM THIS SOLVES. Every public form on provatferi.org is submitted to the Next.js server, which forwards it
 * to this application. Laravel's client is therefore the public site's own server, not the person filling in the
 * form — so `request()->ip()` is the same value for every visitor on earth, and an IP-keyed rate limit is a limit
 * for the whole internet at once. Production behaved exactly like that: roughly five or six submissions a minute for
 * the entire site.
 *
 * No proxy configuration can fix this, and it must not be attempted: the trusted-proxy list is restricted to
 * Cloudflare's ranges precisely so that a forwarding header from anywhere else is ignored, and the site's server is
 * not Cloudflare. The only honest answer is for the one party that *does* know the visitor — the Next server — to
 * say so in a way this application can verify and a browser cannot forge.
 *
 * SO: the site server signs a tiny payload with a shared secret and sends it alongside the request. This class
 * verifies it. The rules it enforces, each for a reason:
 *
 *   - HMAC-SHA256 over a CANONICAL string, compared with hash_equals(). A length-safe, constant-time comparison,
 *     and a canonical form so two different payloads can never produce the same signing input;
 *   - a short validity window (default 60s), so a captured context cannot be replayed tomorrow;
 *   - MULTIPLE accepted secrets, verified in turn. Rotation is then: add the new secret to both apps, deploy, remove
 *     the old one — with no window where valid requests are rejected;
 *   - the action name is part of the signed payload, so a context minted for one endpoint cannot be replayed against
 *     another to spend its budget;
 *   - nothing is trusted when verification fails. There is no "fall back to the header" path: an unsigned or badly
 *     signed request is simply a request with no client context, and the limiter treats it as the shared proxy
 *     identity it really is.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not look at `X-Forwarded-For`, `CF-Connecting-IP` or `X-Real-IP` on a
 * public request. Those are set by whoever is talking to us, and on a direct request to this origin that is the
 * attacker. They are only meaningful through the trusted-proxy layer, which handles them already.
 *
 * It also carries no personal data: an IP and an opaque session id, both hashed before they ever reach a rate-limit
 * key or a log line.
 */
final class PublicClientContext
{
    /** How long a signed context stays acceptable. Long enough for a slow upload, short enough to make replay dull. */
    public const MAX_AGE_SECONDS = 60;

    /** Rejected if a context claims to be from further in the future than this (clock skew between two hosts). */
    private const MAX_SKEW_SECONDS = 10;

    public const PAYLOAD_HEADER = 'X-Client-Context';

    public const SIGNATURE_HEADER = 'X-Client-Context-Signature';

    private function __construct(
        public readonly bool $verified,
        public readonly ?string $ip,
        public readonly ?string $sessionId,
        public readonly ?string $action,
        public readonly ?string $rejectedBecause,
    ) {
    }

    public static function unverified(?string $because = null): self
    {
        return new self(false, null, null, null, $because);
    }

    /**
     * Read and verify the context on a request. Never throws: an absent or invalid context is simply not verified.
     */
    public static function fromRequest(Request $request): self
    {
        $payload = (string) $request->headers->get(self::PAYLOAD_HEADER, '');
        $signature = (string) $request->headers->get(self::SIGNATURE_HEADER, '');

        if ($payload === '' || $signature === '') {
            return self::unverified($payload === '' && $signature === '' ? null : 'half of the context is missing');
        }
        // A ceiling before any parsing: an attacker should not be able to make us hash a megabyte.
        if (strlen($payload) > 2048 || strlen($signature) > 128) {
            return self::unverified('the context is too large to be genuine');
        }

        $secrets = self::secrets();
        if ($secrets === []) {
            return self::unverified('no shared secret is configured');
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($decoded === false) {
            return self::unverified('the context is not valid base64');
        }
        $data = json_decode($decoded, true);
        if (! is_array($data)) {
            return self::unverified('the context is not valid JSON');
        }

        $version = (string) ($data['v'] ?? '');
        $timestamp = (int) ($data['ts'] ?? 0);
        $action = (string) ($data['act'] ?? '');
        $ip = isset($data['ip']) && is_string($data['ip']) && $data['ip'] !== '' ? $data['ip'] : null;
        $sessionId = isset($data['sid']) && is_string($data['sid']) && $data['sid'] !== '' ? $data['sid'] : null;

        if ($version !== '1') {
            return self::unverified('unsupported context version');
        }
        if ($action === '' || preg_match('/^[a-z0-9.\-]{3,64}$/', $action) !== 1) {
            return self::unverified('the context names no usable action');
        }

        // The signature is checked against the CANONICAL string, not the transmitted payload, so a re-ordered or
        // re-encoded payload cannot verify. Every accepted secret is tried, and the comparison is constant-time.
        $canonical = self::canonical($version, $timestamp, $action, $ip, $sessionId);
        $matched = false;
        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
                $matched = true;
                break;
            }
        }
        if (! $matched) {
            return self::unverified('the signature does not match');
        }

        // Age is checked AFTER the signature, so an unsigned request learns nothing about our clock.
        $age = time() - $timestamp;
        if ($age > self::MAX_AGE_SECONDS) {
            return self::unverified('the context has expired');
        }
        if ($age < -self::MAX_SKEW_SECONDS) {
            return self::unverified('the context is dated in the future');
        }

        // An IP that does not parse is dropped rather than failing the whole context: the session id is still useful.
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = null;
        }
        if ($sessionId !== null && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $sessionId) !== 1) {
            $sessionId = null;
        }

        return new self(true, $ip, $sessionId, $action, null);
    }

    /** The exact string that is signed. Both sides build it this way and nothing else is ever signed. */
    public static function canonical(string $version, int $timestamp, string $action, ?string $ip, ?string $sessionId): string
    {
        return implode("\n", ['v'.$version, (string) $timestamp, $action, $ip ?? '', $sessionId ?? '']);
    }

    /**
     * The accepted secrets, current first.
     *
     * @return list<string>
     */
    public static function secrets(): array
    {
        $configured = config('services.public_site.client_context_secrets');
        $list = is_array($configured) ? $configured : preg_split('/\s*,\s*/', (string) $configured, -1, PREG_SPLIT_NO_EMPTY);

        // A secret too short to be one is treated as absent rather than used: a weak HMAC key is worse than none,
        // because it looks like protection.
        return array_values(array_filter(
            array_map(static fn ($s): string => is_string($s) ? trim($s) : '', $list ?: []),
            static fn (string $s): bool => strlen($s) >= 32
        ));
    }

    public static function isConfigured(): bool
    {
        return self::secrets() !== [];
    }

    /**
     * A short, stable, non-reversible identifier for a limiter key or a log line.
     *
     * Keyed with the application key so the digest cannot be computed by anyone who merely guesses the input, and
     * truncated because a limiter key needs to be short, not unique across the universe. Never the raw value: an IP
     * is personal data and a session id is a tracking handle.
     */
    public static function digest(string $value, string $domain): string
    {
        return substr(hash_hmac('sha256', $domain.':'.$value, (string) config('app.key')), 0, 20);
    }
}
