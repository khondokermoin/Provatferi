<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the public Next.js site to drop cached data the moment an admin
 * changes it, so a carousel edit shows on the live homepage in seconds rather
 * than after the fetch cache's 120-second window.
 *
 * The call is a signed POST to {PUBLIC_SITE_URL}/api/revalidate. The body
 * carries only the cache tag; the secret itself is never transmitted — only an
 * HMAC-SHA256 over "<unix-timestamp>.<tag>", which the Next.js side
 * (lib/revalidate-auth.ts) recomputes and compares in constant time. A
 * captured request is therefore useless as a credential and is replayable only
 * inside a 5-minute window, for the one tag it was signed for.
 *
 * Two properties matter more than the mechanics:
 *
 *  1. It must NEVER break or slow an admin's save. Calls are queued and sent
 *     from a terminating callback, i.e. after the response has gone out, with
 *     tight timeouts, and every failure is caught and logged. The 120-second
 *     fetch window on the Next.js side remains as the safety net, so a lost
 *     call degrades to "updates within two minutes", not to a stale site.
 *
 *  2. Several model saves in one request (a reorder is two saves) must cost
 *     one HTTP call, not N — hence the per-request de-duplication, which only
 *     works because this is bound as a singleton in AppServiceProvider.
 */
class PublicSiteRevalidator
{
    /** The Next.js fetch-cache tag for the homepage carousel (lib/api/carousel.ts). */
    public const CAROUSEL_TAG = 'homepage-carousel';

    /** @var array<string, true> */
    private array $pending = [];

    private bool $flushRegistered = false;

    public function queue(string $tag): void
    {
        if ($this->secret() === null) {
            return;
        }

        $this->pending[$tag] = true;

        if (! $this->flushRegistered) {
            $this->flushRegistered = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    public function flush(): void
    {
        $tags = array_keys($this->pending);
        $this->pending = [];

        foreach ($tags as $tag) {
            $this->send($tag);
        }
    }

    private function send(string $tag): void
    {
        $secret = $this->secret();
        if ($secret === null) {
            return;
        }

        $timestamp = (string) time();
        $url = rtrim((string) config('services.public_site.url'), '/').'/api/revalidate';

        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->acceptJson()
                ->withHeaders([
                    'X-Revalidate-Timestamp' => $timestamp,
                    'X-Revalidate-Signature' => hash_hmac('sha256', $timestamp.'.'.$tag, $secret),
                ])
                ->post($url, ['tag' => $tag]);

            if (! $response->successful()) {
                Log::warning('Public-site revalidation was rejected.', ['tag' => $tag, 'status' => $response->status(), 'url' => $url]);
            }
        } catch (Throwable $e) {
            Log::warning('Public-site revalidation failed to send.', ['tag' => $tag, 'url' => $url, 'error' => $e->getMessage()]);
        }
    }

    private function secret(): ?string
    {
        $secret = config('services.public_site.revalidate_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
