<?php

namespace App\Http\Middleware;

use App\Services\PublicSiteRevalidator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the queued public-site invalidations BEFORE the admin's browser is told its save worked.
 *
 * PublicSiteRevalidator normally flushes from a terminating callback, i.e. after the response has gone out. For the
 * membership screens that leaves a window of one HTTP round trip (typically 100-300 ms) in which the admin has seen
 * "saved", yet the public site still holds the old data — a script, or a fast reload, that asks the public page in that
 * window gets the old page. Flushing here, once the controller has finished and committed, closes that window: by the
 * time the redirect reaches the browser the site has already dropped the affected cache entries, so the very next
 * public request is built from fresh data.
 *
 * Failure semantics are unchanged: the call is bounded (3 s, one fast retry), every failure is caught and logged inside
 * PublicSiteRevalidator, and nothing here can undo or fail the save that already happened. When nothing was queued
 * (every read-only page) this does nothing at all.
 */
class FlushPublicSiteRevalidations
{
    public function __construct(private readonly PublicSiteRevalidator $revalidator)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->revalidator->flush();

        return $response;
    }
}
