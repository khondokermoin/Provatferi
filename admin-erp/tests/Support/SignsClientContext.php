<?php

namespace Tests\Support;

use App\Support\PublicClientContext;

/**
 * Builds the signed client context the public site sends, for tests that need to look like a real visitor.
 *
 * Shared rather than copied: the public rate limits are per-visitor now, so several suites need to say "this is one
 * particular person" — and a second hand-rolled copy of the signing rules is a second thing to get wrong when the
 * canonical form changes. See docs/RATE_LIMITING_ARCHITECTURE.md.
 */
trait SignsClientContext
{
    protected string $clientContextSecret = 'test-client-context-secret-at-least-32-chars-long';

    /** Make the application accept contexts signed by this trait. Call from setUp(). */
    protected function withClientContextSecret(): void
    {
        config(['services.public_site.client_context_secrets' => $this->clientContextSecret]);
    }

    /**
     * Headers for one visitor performing one action.
     *
     * @return array<string, string>
     */
    protected function clientContext(string $action, string $sessionId = 'test-visitor', string $ip = '203.0.113.7'): array
    {
        $timestamp = time();
        $payload = ['v' => '1', 'ts' => $timestamp, 'act' => $action, 'ip' => $ip, 'sid' => $sessionId];

        return [
            PublicClientContext::PAYLOAD_HEADER => rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '='),
            PublicClientContext::SIGNATURE_HEADER => hash_hmac(
                'sha256',
                PublicClientContext::canonical('1', $timestamp, $action, $ip, $sessionId),
                $this->clientContextSecret
            ),
        ];
    }
}
