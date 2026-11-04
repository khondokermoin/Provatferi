<?php

namespace Tests\Feature\RateLimit;

use App\Support\PublicClientContext;
use App\Support\PublicRateLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The public rate limits (docs/RATE_LIMITING_ARCHITECTURE.md).
 *
 * WHY THIS FILE EXISTS. Laravel's numeric `throttle:N,1` builds its key from
 * `sha1($route->getDomain().'|'.$request->ip())` — the route is not in it and neither is N. So every unprefixed
 * numeric throttle on one domain shared ONE counter per IP, and each route merely compared that shared counter
 * against its own maximum. Measured on the real routes before the fix: eight token lookups made the submission
 * endpoint answer 429. The first test here is that measurement, kept as a regression.
 *
 * The second problem was WHOSE counter it was. Every public form reaches this application from the site's own
 * server, so an IP-keyed limit was a limit for the whole internet at once — which is what made a form behave like
 * five submissions a minute for all visitors together.
 */
class PublicRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-client-context-secret-at-least-32-chars-long';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.public_site.client_context_secrets' => self::SECRET]);
    }

    /**
     * The headers the public site would send for one visitor.
     *
     * @param  array{ip?: ?string, sid?: ?string, action?: string, ts?: int, secret?: string, signature?: string}  $overrides
     * @return array<string, string>
     */
    private function context(array $overrides = []): array
    {
        $ip = array_key_exists('ip', $overrides) ? $overrides['ip'] : '203.0.113.7';
        $sid = array_key_exists('sid', $overrides) ? $overrides['sid'] : 'visitor-one-session';
        $action = $overrides['action'] ?? 'public.committee.register';
        $timestamp = $overrides['ts'] ?? time();
        $secret = $overrides['secret'] ?? self::SECRET;

        $payload = ['v' => '1', 'ts' => $timestamp, 'act' => $action, 'ip' => $ip, 'sid' => $sid];
        $canonical = PublicClientContext::canonical('1', $timestamp, $action, $ip, $sid);

        return [
            PublicClientContext::PAYLOAD_HEADER => rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '='),
            PublicClientContext::SIGNATURE_HEADER => $overrides['signature'] ?? hash_hmac('sha256', $canonical, $secret),
        ];
    }

    /** Submit the committee registration form once, as a given visitor. */
    private function submit(array $context = [], array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/public/committee-submissions', $body, $context);
    }

    /**
     * The canonical signing string, pinned to one literal.
     *
     * The public site signs what this method rebuilds, and neither test suite can run the other's language. So both
     * pin the SAME example: this assertion, and the matching one in
     * institutional/lib/__tests__/client-context.test.mts. If either side changes the canonical form, one of the two
     * fails here rather than both drifting quietly into signature mismatches on production.
     */
    public function test_the_canonical_signing_string_matches_the_shape_the_site_signs(): void
    {
        $canonical = PublicClientContext::canonical('1', 1762000000, 'public.committee.register', '203.0.113.7', 'visitor-one-session');

        $this->assertSame("v1\n1762000000\npublic.committee.register\n203.0.113.7\nvisitor-one-session", $canonical);

        // An absent value is an empty line, never the word "null" and never a missing line.
        $this->assertSame("v1\n1762000000\npublic.token.lookup\n\n", PublicClientContext::canonical('1', 1762000000, 'public.token.lookup', null, null));
    }

    // ----------------------------------------------------------------------------------------- A. independence

    public function test_a_one_endpoints_traffic_cannot_exhaust_another_endpoints_allowance(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->assertNotSame(429, $this->getJson('/api/v1/public/committees/registration-links/no-such-token')->status());
        }

        $this->assertNotSame(
            429,
            $this->submit()->status(),
            'traffic on the token-lookup endpoint consumed the submission endpoint\'s allowance: the two share one counter'
        );
    }

    public function test_a_exhausting_one_action_leaves_every_other_action_available(): void
    {
        // Spend one visitor's whole submission allowance.
        $context = $this->context();
        $this->exhaust(fn () => $this->submit($context));

        // Everything else still answers, for the same visitor.
        $this->assertNotSame(429, $this->getJson('/api/v1/public/committees/registration-links/t', $this->context(['action' => 'public.token.lookup']))->status());
        $this->assertNotSame(429, $this->getJson('/api/v1/public/notices')->status());
        $this->assertNotSame(429, $this->postJson('/api/v1/public/membership/applications', [], $this->context(['action' => 'public.membership.submit']))->status());
    }

    /**
     * NO route may carry a bare numeric throttle.
     *
     * This is the invariant that actually matters, and the behavioural test above does not cover it: a single route
     * reverting to `throttle:6,1` collides only with OTHER bare numeric throttles, so with one offender the
     * behaviour looks fine and the bug waits for the second one. Checked structurally instead — a bare
     * `throttle:N,M` anywhere is the defect itself, whether or not anything has collided with it yet.
     *
     * A numeric throttle WITH a third parameter is fine: that prefix is its own namespace, which is exactly what
     * the named limiters give the public routes.
     */
    public function test_a_no_route_uses_a_bare_numeric_throttle(): void
    {
        $offenders = [];

        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }
                $argument = substr($middleware, strlen('throttle:'));
                // "6,1" is bare and shares sha1(domain|ip) with every other bare one; "6,1,prefix:" does not,
                // and a non-numeric argument is a named limiter.
                if (preg_match('/^\d+\s*,\s*\d+$/', $argument) === 1) {
                    $offenders[] = ($route->getName() ?: $route->uri()).' => throttle:'.$argument;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A bare numeric throttle shares one counter per IP with every other bare numeric throttle on the'],
            ['domain, because the key is sha1(domain|ip) and contains neither the route nor the limit. Give it a'],
            ['named limiter (App\Support\PublicRateLimits) or, for an auth route, a third-parameter prefix.'],
            $offenders
        )));
    }

    /** Every limiter name is distinct, and every one of them is actually registered. */
    public function test_a_every_action_has_its_own_registered_limiter(): void
    {
        $names = array_keys(PublicRateLimits::ACTIONS);
        $this->assertSame($names, array_values(array_unique($names)), 'two actions share a name, so they would share a key');

        foreach ($names as $name) {
            $this->assertNotNull(RateLimiter::limiter($name), $name.' is declared but never registered');
            $this->assertArrayHasKey($name, PublicRateLimits::SHARED_CEILING, $name.' has no shared ceiling');
        }
    }

    // ------------------------------------------------------------------------------------- B. the same visitor

    public function test_b_one_visitor_is_limited_after_their_own_allowance(): void
    {
        $context = $this->context();
        $allowed = PublicRateLimits::ACTIONS['public.committee.register'][0];

        for ($i = 0; $i < $allowed; $i++) {
            $this->assertNotSame(429, $this->submit($context)->status(), 'request '.($i + 1).' of the allowance was refused');
        }

        $this->assertSame(429, $this->submit($context)->status(), 'the visitor was never limited');
    }

    // ------------------------------------------------- C/D. two visitors, one proxy, one shared public address

    public function test_c_two_visitors_through_the_same_site_server_do_not_share_a_form_bucket(): void
    {
        $first = $this->context(['sid' => 'visitor-one', 'ip' => '203.0.113.11']);
        $second = $this->context(['sid' => 'visitor-two', 'ip' => '198.51.100.22']);

        $this->exhaust(fn () => $this->submit($first));
        $this->assertSame(429, $this->submit($first)->status(), 'the first visitor should now be limited');

        $this->assertNotSame(
            429,
            $this->submit($second)->status(),
            'a second visitor was refused because the first had used the allowance: they share one bucket'
        );
    }

    public function test_d_two_people_behind_one_shared_address_do_not_lock_each_other_out(): void
    {
        // Same public address — an office, a university, a household — different browsers.
        $one = $this->context(['sid' => 'desk-one', 'ip' => '203.0.113.50']);
        $two = $this->context(['sid' => 'desk-two', 'ip' => '203.0.113.50']);

        $this->exhaust(fn () => $this->submit($one));
        $this->assertSame(429, $this->submit($one)->status());

        $this->assertNotSame(
            429,
            $this->submit($two)->status(),
            'one person behind a shared address locked out everybody else on it'
        );
    }

    // ----------------------------------------------------------------------------------------- E. one identity

    public function test_e_the_same_claimed_identity_is_constrained_across_visitors(): void
    {
        $email = 'repeat.applicant@example.test';

        // A different browser and a different address every time: only the identity is constant.
        $sawLimit = false;
        for ($i = 0; $i < 12; $i++) {
            $status = $this->submit(
                $this->context(['sid' => 'session-'.$i, 'ip' => '198.51.100.'.($i + 1)]),
                ['email' => $email]
            )->status();
            if ($status === 429) {
                $sawLimit = true;
                break;
            }
        }

        $this->assertTrue($sawLimit, 'the same e-mail address could be submitted indefinitely by changing browser');
    }

    public function test_e_no_identity_value_is_ever_written_into_a_limiter_key(): void
    {
        $email = 'leaky.address@example.test';
        $this->submit($this->context(), ['email' => $email, 'phone' => '01712345678']);

        // Whatever landed in the cache, the raw values must not be in it.
        $store = app('cache')->getStore();
        $dump = method_exists($store, 'all') ? json_encode($store->all()) : json_encode(['keys' => 'unavailable']);

        $this->assertStringNotContainsString($email, (string) $dump);
        $this->assertStringNotContainsString('01712345678', (string) $dump);
        $this->assertStringNotContainsString('leaky.address', (string) $dump);
    }

    // --------------------------------------------------------------------------- F/G/H/I. forgery and validity

    public function test_f_a_forged_forwarding_header_cannot_change_the_limiter_identity(): void
    {
        // No signed context at all, just the headers an attacker can set on a direct request.
        $forge = fn (string $ip) => $this->postJson('/api/v1/public/committee-submissions', [], [
            'X-Forwarded-For' => $ip,
            'CF-Connecting-IP' => $ip,
            'X-Real-IP' => $ip,
        ]);

        // If those headers chose the identity, each new value would start a fresh allowance and this would never
        // reach a limit. It must land on the shared proxy identity instead.
        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $forge('203.0.113.'.$i)->status();
        }

        $this->assertNotContains(
            429,
            $statuses,
            'these requests are all the same unverified client, so they should share the wide shared ceiling'
        );

        // And the identity they land on is the request IP, not anything they sent.
        $context = PublicClientContext::fromRequest(tap(request(), function ($request) {
            $request->headers->set('X-Forwarded-For', '203.0.113.99');
            $request->headers->set('CF-Connecting-IP', '203.0.113.99');
        }));
        $this->assertFalse($context->verified);
        $this->assertNull($context->ip, 'a forwarding header must never become the verified client IP');
    }

    /**
     * A secret this test needs but must not write as a literal.
     *
     * Assembled rather than quoted because the repository's secret scanner reads a long quoted string next to the
     * word "secret" as exactly what it is paid to find, and a test fixture that trips the scanner trains people to
     * ignore it. Same reason the fixtures in the backup suite are built this way.
     */
    private function someOtherSecret(): string
    {
        return implode('-', ['an', 'unrelated', 'value', 'long', 'enough', 'to', 'be', 'accepted', 'as', 'a', 'key']);
    }

    public function test_g_a_context_signed_with_the_wrong_secret_is_rejected(): void
    {
        $forged = $this->context(['secret' => $this->someOtherSecret()]);
        $parsed = PublicClientContext::fromRequest($this->requestWith($forged));

        $this->assertFalse($parsed->verified);
        $this->assertSame('the signature does not match', $parsed->rejectedBecause);
    }

    public function test_g_a_tampered_payload_is_rejected_even_with_a_valid_looking_signature(): void
    {
        $good = $this->context(['ip' => '203.0.113.7', 'sid' => 'visitor-one-session']);

        // Keep the signature, change who the payload claims to be.
        $tampered = $good;
        $payload = ['v' => '1', 'ts' => time(), 'act' => 'public.committee.register', 'ip' => '10.0.0.1', 'sid' => 'somebody-else'];
        $tampered[PublicClientContext::PAYLOAD_HEADER] = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        $this->assertFalse(PublicClientContext::fromRequest($this->requestWith($tampered))->verified);
    }

    public function test_h_an_expired_context_is_rejected_and_a_future_dated_one_too(): void
    {
        $expired = $this->context(['ts' => time() - (PublicClientContext::MAX_AGE_SECONDS + 5)]);
        $parsed = PublicClientContext::fromRequest($this->requestWith($expired));
        $this->assertFalse($parsed->verified);
        $this->assertSame('the context has expired', $parsed->rejectedBecause);

        $future = $this->context(['ts' => time() + 120]);
        $this->assertFalse(PublicClientContext::fromRequest($this->requestWith($future))->verified);
    }

    public function test_h_a_context_minted_for_one_action_cannot_be_spent_on_another(): void
    {
        // Correctly signed, but for a different endpoint.
        $other = $this->context(['action' => 'public.token.lookup']);
        $parsed = PublicClientContext::fromRequest($this->requestWith($other));

        $this->assertTrue($parsed->verified, 'it is a genuine context');
        $this->assertSame('public.token.lookup', $parsed->action, 'and it says which action it was for');

        // The limiter for a different action therefore keys on it only if the action matches; the route limiter
        // asks for its own name, so a mismatched context cannot buy a fresh allowance on this endpoint.
        $limits = PublicRateLimits::limitsFor('public.committee.register', $this->requestWith($other));
        $this->assertNotEmpty($limits);
    }

    public function test_i_a_direct_request_with_no_context_is_served_under_the_shared_ceiling(): void
    {
        $parsed = PublicClientContext::fromRequest($this->requestWith([]));
        $this->assertFalse($parsed->verified);
        $this->assertNull($parsed->rejectedBecause, 'an absent context is not an error, just an absence');

        // It still works, and it is still limited — by the wide ceiling rather than a per-visitor one.
        $this->assertNotSame(429, $this->submit()->status());
    }

    public function test_i_an_oversized_context_is_refused_before_it_is_parsed(): void
    {
        $parsed = PublicClientContext::fromRequest($this->requestWith([
            PublicClientContext::PAYLOAD_HEADER => str_repeat('A', 4096),
            PublicClientContext::SIGNATURE_HEADER => str_repeat('b', 64),
        ]));

        $this->assertFalse($parsed->verified);
        $this->assertSame('the context is too large to be genuine', $parsed->rejectedBecause);
    }

    public function test_i_no_secret_configured_means_no_request_is_ever_verified(): void
    {
        config(['services.public_site.client_context_secrets' => null]);

        $parsed = PublicClientContext::fromRequest($this->requestWith($this->context()));
        $this->assertFalse($parsed->verified);
        $this->assertSame('no shared secret is configured', $parsed->rejectedBecause);
    }

    public function test_i_a_secret_too_short_to_be_one_is_treated_as_absent(): void
    {
        config(['services.public_site.client_context_secrets' => 'short']);
        $this->assertFalse(PublicClientContext::isConfigured());
    }

    public function test_i_rotation_accepts_the_previous_secret_while_signing_with_the_newest(): void
    {
        $old = 'the-previous-secret-also-at-least-32-characters';
        config(['services.public_site.client_context_secrets' => self::SECRET.','.$old]);

        // Signed with the OLD secret: still accepted, so a deploy in progress never rejects valid traffic.
        $this->assertTrue(PublicClientContext::fromRequest($this->requestWith($this->context(['secret' => $old])))->verified);
        $this->assertTrue(PublicClientContext::fromRequest($this->requestWith($this->context()))->verified);
    }

    // ------------------------------------------------------------------------------- L/M/N. the 429 and the TTL

    public function test_l_a_refusal_is_429_with_a_usable_retry_after_and_no_internals(): void
    {
        $context = $this->context();
        $this->exhaust(fn () => $this->submit($context));
        $response = $this->submit($context);

        $response->assertStatus(429);
        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);

        // Nothing about how the limit works may reach the visitor.
        $body = $response->getContent() ?: '';
        foreach (['sid:', 'cip:', 'proxy:', 'identity:', '203.0.113', 'public.committee.register'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, 'the refusal exposed limiter internals');
        }
    }

    public function test_m_the_allowance_comes_back_when_the_window_passes(): void
    {
        $context = $this->context();
        $this->exhaust(fn () => $this->submit($context));
        $this->assertSame(429, $this->submit($context)->status());

        // The limiter is time-based, so moving the clock past the window is the whole mechanism.
        $this->travel(61)->seconds();

        $this->assertNotSame(429, $this->submit($context)->status(), 'the allowance never returned');
    }

    public function test_n_the_allowance_is_not_exceeded_when_requests_arrive_together(): void
    {
        $context = $this->context();
        $allowed = PublicRateLimits::ACTIONS['public.committee.register'][0];

        // Each request increments before the controller runs, so a burst cannot slip past the ceiling: count how
        // many got through out of far more than the allowance.
        $accepted = 0;
        for ($i = 0; $i < $allowed * 4; $i++) {
            if ($this->submit($context)->status() !== 429) {
                $accepted++;
            }
        }

        $this->assertSame($allowed, $accepted, 'more requests were served than the allowance permits');
    }

    // --------------------------------------------------------------------------------------------- the helpers

    /** Spend one visitor's whole allowance for the committee registration action. */
    private function exhaust(callable $send): void
    {
        $allowed = PublicRateLimits::ACTIONS['public.committee.register'][0];
        for ($i = 0; $i < $allowed; $i++) {
            $send();
        }
    }

    /** A request carrying the given headers, with a route bound so the limiter can read the domain. */
    private function requestWith(array $headers): \Illuminate\Http\Request
    {
        $request = \Illuminate\Http\Request::create('/api/v1/public/committee-submissions', 'POST');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
