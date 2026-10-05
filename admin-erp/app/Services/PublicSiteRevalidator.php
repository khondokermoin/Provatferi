<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the public Next.js site to drop cached data the moment an admin changes it, so a change shows on the live site
 * in seconds rather than after the fetch cache's window (the homepage carousel since 2026-10-02, the membership page
 * since 2026-10-05).
 *
 * The call is a signed POST to {PUBLIC_SITE_URL}/api/revalidate carrying EVERY pending tag at once:
 *
 *     X-Revalidate-Timestamp: <unix seconds>
 *     X-Revalidate-Nonce:     <random, different for every call>
 *     X-Revalidate-Signature: HMAC-SHA256( "v2.<timestamp>.<nonce>.<tag1,tag2,…>" ) with the shared secret
 *     body: {"tags": ["membership-seasons", …]}
 *
 * The secret itself is never transmitted. The Next.js side (lib/revalidate-auth.ts, lib/revalidate-request.ts)
 * recomputes the HMAC in constant time, accepts only tags on its allow-list, and remembers every nonce it has accepted,
 * so a captured request is useless as a credential, is replayable neither inside the 5-minute timestamp window nor
 * after it, and can only ever purge the tags it was signed for.
 *
 * Properties that matter more than the mechanics:
 *
 *  1. A failure NEVER breaks or rolls back an admin's save. Every failure is caught and logged (tags, status, URL —
 *     never the secret), with tight timeouts. The cache's short time-to-live and the data's own `valid_until` remain as
 *     safety nets, so a lost call degrades to "updates within seconds to a minute", not to a stale site.
 *
 *  2. Several model saves in one request (a reorder is two, a new type plus its first fee policy is two) cost ONE HTTP
 *     call — hence the per-request de-duplication, which only works because this is bound as a singleton in
 *     AppServiceProvider.
 *
 *  3. WHEN the call is made. queue() only records the tag; the call goes out either from
 *     App\Http\Middleware\FlushPublicSiteRevalidations (membership screens: BEFORE the admin's browser is told the save
 *     worked, so the first public request after the admin sees "saved" can never beat the invalidation) or, for
 *     everything else, from a terminating callback (after the response has gone out, so it adds no latency at all).
 */
class PublicSiteRevalidator
{
    /** The Next.js fetch-cache tag for the homepage carousel (lib/api/carousel.ts). */
    public const CAROUSEL_TAG = 'homepage-carousel';

    /** Every membership lookup carries this tag as well: "purge all membership data". Not sent by any observer. */
    public const MEMBERSHIP_TAG = 'membership';

    /** Season created / edited / opened / closed / deleted, or the types it offers changed. */
    public const MEMBERSHIP_SEASONS_TAG = 'membership-seasons';

    /** A membership type created / edited / enabled / hidden / reordered / deleted. */
    public const MEMBERSHIP_TYPES_TAG = 'membership-types';

    /** A fee policy created or cancelled (or its end date re-derived). */
    public const MEMBERSHIP_FEES_TAG = 'membership-fees';

    /** @var array<string, true> */
    private array $pending = [];

    private bool $flushRegistered = false;

    public function queue(string ...$tags): void
    {
        if ($this->secret() === null) {
            return;
        }

        foreach ($tags as $tag) {
            $this->pending[$tag] = true;
        }

        if (! $this->flushRegistered) {
            $this->flushRegistered = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    /** Sends everything queued so far as ONE call. Safe to call at any time; does nothing when nothing is pending. */
    public function flush(): void
    {
        $tags = array_keys($this->pending);
        $this->pending = [];

        if ($tags === []) {
            return;
        }

        sort($tags);
        $this->send($tags);
    }

    /** @param  list<string>  $tags */
    private function send(array $tags): void
    {
        $secret = $this->secret();
        if ($secret === null) {
            return;
        }

        $url = rtrim((string) config('services.public_site.url'), '/').'/api/revalidate';

        // Two attempts at most, and only when the first one failed FAST (connection refused or reset): a timeout means
        // the site is slow, not absent, and doubling the wait would stall the admin's save. Each attempt has its own
        // nonce, so a retry after a lost response is never mistaken for a replay.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $started = microtime(true);
            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(16));

            try {
                $response = Http::timeout(3)
                    ->connectTimeout(2)
                    ->acceptJson()
                    ->withHeaders([
                        'X-Revalidate-Timestamp' => $timestamp,
                        'X-Revalidate-Nonce' => $nonce,
                        'X-Revalidate-Signature' => hash_hmac('sha256', 'v2.'.$timestamp.'.'.$nonce.'.'.implode(',', $tags), $secret),
                    ])
                    ->post($url, ['tags' => $tags]);

                if ($response->successful()) {
                    return;
                }

                if ($attempt === 1 && in_array($response->status(), [502, 503, 504], true)) {
                    usleep(250_000);

                    continue;
                }

                Log::warning('Public-site revalidation was rejected.', ['tags' => $tags, 'status' => $response->status(), 'url' => $url]);

                return;
            } catch (ConnectionException $e) {
                if ($attempt === 1 && (microtime(true) - $started) < 1.0) {
                    usleep(250_000);

                    continue;
                }

                Log::warning('Public-site revalidation failed to send.', ['tags' => $tags, 'url' => $url, 'error' => $e->getMessage()]);

                return;
            } catch (Throwable $e) {
                Log::warning('Public-site revalidation failed to send.', ['tags' => $tags, 'url' => $url, 'error' => $e->getMessage()]);

                return;
            }
        }
    }

    private function secret(): ?string
    {
        $secret = config('services.public_site.revalidate_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
