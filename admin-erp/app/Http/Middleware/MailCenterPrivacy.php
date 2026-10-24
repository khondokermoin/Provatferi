<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers on every Mail Center response (docs/MAIL_CENTER.md, "Privacy"): never stored by a browser, proxy or CDN, never
 * indexed, never framed by another site, no content sniffing, and no referrer — a link in a message opened from the Mail
 * Center must not tell its target which mailbox URL it was read in.
 *
 * Attachment responses set their own stricter policy (a sandbox CSP) on top.
 */
class MailCenterPrivacy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }
}
