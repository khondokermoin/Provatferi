<?php

namespace App\Support;

/**
 * The only peers whose forwarded-client-IP headers this app believes.
 *
 * admin.provatferi.org sits behind Cloudflare. Without trusted proxies Laravel
 * takes REMOTE_ADDR — a Cloudflare edge address — as the visitor, so every
 * `throttle:N,1` route (keyed by domain|ip) shares ONE allowance across all
 * visitors that reach the same edge, and audit logs show one IP for everyone.
 *
 * Deliberately NOT '*': the origin is reachable directly on its public address
 * (that is how a request bypasses Cloudflare), and trusting every peer would let
 * such a caller forge X-Forwarded-For to pick its own rate-limit key or hide
 * from the logs. Only Cloudflare's published ranges are trusted.
 *
 * Source: https://api.cloudflare.com/client/v4/ips (etag 38f79d050aa027e3be3865e495dcc9bc,
 * fetched 2026-09-21). Cloudflare changes these rarely; refresh from that URL
 * when it does — CloudflareProxiesTest pins the shape, not the values.
 */
final class CloudflareProxies
{
    /** @var array<int, string> */
    public const RANGES = [
        // IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];
}
