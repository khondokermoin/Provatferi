<?php

namespace Tests\Feature;

use App\Support\CloudflareProxies;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * admin.provatferi.org is behind Cloudflare. These pin what "behind Cloudflare"
 * must mean for the app: the real visitor IP is used — and only when the peer
 * is genuinely Cloudflare. The second half is the security-relevant one: the
 * origin is reachable directly, so a header from any other peer must be ignored.
 */
class CloudflareTrustedProxyTest extends TestCase
{
    private const CLOUDFLARE_EDGE = '172.69.10.20';   // inside 172.64.0.0/13
    private const VISITOR = '203.0.113.77';
    private const NOT_CLOUDFLARE = '198.51.100.9';    // documentation range, not Cloudflare

    private function probe(string $peer, array $headers = []): array
    {
        // Symfony's trusted-proxy list is process-static, and in production every
        // request boots its own copy. A fresh application per probe reproduces that,
        // instead of one probe's trust state leaking into the next assertion.
        $this->refreshApplication();
        Route::middleware('web')->get('/__ip-probe', fn (\Illuminate\Http\Request $r) => response()->json([
            'ip' => $r->ip(), 'secure' => $r->isSecure(),
        ]));

        return $this->withServerVariables(['REMOTE_ADDR' => $peer])
            ->withHeaders($headers)
            ->getJson('/__ip-probe')
            ->assertOk()
            ->json();
    }

    public function test_a_visitor_behind_cloudflare_is_seen_as_the_visitor_not_the_edge(): void
    {
        $seen = $this->probe(self::CLOUDFLARE_EDGE, ['X-Forwarded-For' => self::VISITOR]);

        $this->assertSame(self::VISITOR, $seen['ip']);
    }

    public function test_a_forged_forwarded_for_from_a_non_cloudflare_peer_is_ignored(): void
    {
        // Someone reaching the origin directly, bypassing Cloudflare.
        $seen = $this->probe(self::NOT_CLOUDFLARE, ['X-Forwarded-For' => '1.2.3.4']);

        $this->assertSame(self::NOT_CLOUDFLARE, $seen['ip']);
        $this->assertNotSame('1.2.3.4', $seen['ip']);
    }

    public function test_a_forged_chain_cannot_smuggle_a_trusted_looking_address_from_an_untrusted_peer(): void
    {
        $seen = $this->probe(self::NOT_CLOUDFLARE, ['X-Forwarded-For' => self::VISITOR.', '.self::CLOUDFLARE_EDGE]);

        $this->assertSame(self::NOT_CLOUDFLARE, $seen['ip']);
    }

    public function test_https_is_recognised_behind_cloudflare_so_urls_and_signed_links_stay_https(): void
    {
        $this->assertTrue($this->probe(self::CLOUDFLARE_EDGE, ['X-Forwarded-Proto' => 'https'])['secure']);
    }

    public function test_a_forged_forwarded_proto_from_a_non_cloudflare_peer_is_ignored(): void
    {
        // Same forgery risk as X-Forwarded-For: only Cloudflare may assert the scheme.
        $this->assertFalse($this->probe(self::NOT_CLOUDFLARE, ['X-Forwarded-Proto' => 'https'])['secure']);
    }

    public function test_the_throttle_signature_differs_per_real_visitor_instead_of_per_edge(): void
    {
        // Laravel keys `throttle:N,1` on domain|ip. Two different visitors through the
        // same Cloudflare edge must therefore NOT share one allowance.
        $a = $this->probe(self::CLOUDFLARE_EDGE, ['X-Forwarded-For' => '203.0.113.1'])['ip'];
        $b = $this->probe(self::CLOUDFLARE_EDGE, ['X-Forwarded-For' => '203.0.113.2'])['ip'];

        $this->assertNotSame($a, $b);
    }

    public function test_the_trusted_list_is_cloudflare_ranges_only_and_never_a_wildcard(): void
    {
        $this->assertNotEmpty(CloudflareProxies::RANGES);
        foreach (CloudflareProxies::RANGES as $range) {
            $this->assertNotSame('*', $range);
            $this->assertNotSame('**', $range);
            $this->assertMatchesRegularExpression('#^[0-9a-f:.]+/\d{1,3}$#i', $range, "not a CIDR: {$range}");
            [, $bits] = explode('/', $range);
            // a /0 or /1 would silently trust half the internet
            $this->assertGreaterThanOrEqual(8, (int) $bits, "range too wide to be a proxy: {$range}");
        }
    }

    public function test_the_wildcard_is_not_configured_in_the_application_bootstrap(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString('CloudflareProxies::RANGES', $bootstrap);
        $this->assertDoesNotMatchRegularExpression("/trustProxies\\(\\s*at:\\s*['\"]\\*['\"]/", $bootstrap);
    }
}
