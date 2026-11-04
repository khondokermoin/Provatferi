<?php

namespace App\Http\Middleware;

use App\Support\PublicClientContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits how often the SAME claimed identity may submit a public form (docs/RATE_LIMITING_ARCHITECTURE.md).
 *
 * WHY THIS EXISTS ALONGSIDE THE OTHER LIMITS. A per-visitor limit asks "is this browser doing too much"; it cannot
 * answer "is this the same person submitting over and over from everywhere". For a form whose abuse creates work a
 * human has to undo — an application row, an uploaded photograph, a notification — that second question is the one
 * worth asking, and a determined submitter changes address and clears cookies long before they change their e-mail.
 *
 * WHAT IT NEVER DOES. It never stores or logs the e-mail address or the phone number. The value is normalised and
 * then run through a keyed hash (App\Support\PublicClientContext::digest, keyed with the application key), so what
 * reaches the cache is a short opaque digest. A limiter key is readable by anything that can read the cache, which
 * on this host is a database table, so putting a visitor's e-mail there would be a quiet data leak with a long tail.
 *
 * Deliberately generous. Someone who mistypes a field and fixes it is submitting the same identity two or three
 * times in a minute, which is normal and must not be punished; a window of ten minutes with a handful of attempts
 * leaves that alone and still stops a script working through a list.
 *
 *   ->middleware('throttle.identity:public.volunteer.submit')
 */
class ThrottlePublicIdentity
{
    /** Attempts allowed per identity, per window. */
    private const MAX_ATTEMPTS = 5;

    private const WINDOW_SECONDS = 600;

    /**
     * The request fields that name a person, in the order they are tried.
     *
     * Only these. A field that is merely long or merely unique (a cover letter, a name) is not an identity and
     * keying on it would make the limiter's cardinality unbounded — a cache full of one-hit keys, which is its own
     * denial of service.
     */
    private const IDENTITY_FIELDS = ['email', 'phone', 'mobile', 'contact_number'];

    public function handle(Request $request, Closure $next, string $action = 'public.form.submit'): Response
    {
        $domain = (string) ($request->route()?->getDomain() ?? $request->getHost());

        foreach (self::IDENTITY_FIELDS as $field) {
            $value = $request->input($field);
            if (! is_string($value)) {
                continue;
            }
            $normalised = $this->normalise($field, $value);
            if ($normalised === null) {
                continue;
            }

            $key = 'identity:'.$action.':'.$field.':'.PublicClientContext::digest($normalised, $domain);

            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                // The same shape of answer as the route limiter's, so a form has one case to handle. It says
                // nothing about which field matched or what the key was.
                return response()->json([
                    'message' => __('Too many attempts. Please try again later.'),
                ], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
            }

            RateLimiter::hit($key, self::WINDOW_SECONDS);
        }

        return $next($request);
    }

    /**
     * One canonical form per field, so "A@B.com " and "a@b.com" are one identity, and a bounded length so an
     * oversized value cannot become an oversized key.
     */
    private function normalise(string $field, string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 254) {
            return null;
        }

        if ($field === 'email') {
            $value = mb_strtolower($value);

            // Only a plausible address is worth a counter; anything else is a validation error in a moment anyway.
            return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
        }

        // A phone number written four ways is one number: keep the digits, and the last nine of them, so a local
        // and an international spelling of the same line collapse together.
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return mb_strlen($digits) >= 9 ? substr($digits, -9) : null;
    }
}
