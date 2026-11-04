<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The public rate limits, one named limiter per action (docs/RATE_LIMITING_ARCHITECTURE.md).
 *
 * WHY NAMED LIMITERS AND NOT `throttle:N,1`. Laravel's numeric throttle builds its key from
 * `sha1($route->getDomain().'|'.$request->ip())`. The route is not in it and neither is N, so every unprefixed
 * numeric throttle on one domain shares ONE counter per IP and each route merely compares that shared counter
 * against its own maximum. Measured on the real routes: eight token lookups made the submission endpoint answer 429.
 * A named limiter's key is `md5($limiterName.$limit->key)`, so the action is part of the key by construction and
 * two actions can no longer spend each other's budget.
 *
 * WHY EVERY LIMIT IS LAYERED. A public form reaches this application from the site's own server, so the request IP
 * is the same for every visitor (see App\Support\PublicClientContext). Keying on it alone is a limit for the whole
 * internet. Each public limiter therefore returns SEVERAL limits at once — Laravel checks all of them — so the
 * narrow, per-visitor limit does the real work and the wide one is only a backstop against a flood that defeats
 * per-visitor identification entirely.
 *
 * THE NAT RULE. A university, an office or a household behind one address must not be able to lock each other out.
 * So wherever a per-visitor limit exists it is keyed by the visitor's own session first and the shared address
 * second, and the shared-address allowance is set well above what one person does — generous enough that a building
 * full of people is fine, tight enough that a script from one address is not.
 */
final class PublicRateLimits
{
    /**
     * Every public limiter: name => [max per minute, what it protects].
     *
     * The numbers come from what the action COSTS and what abusing it would achieve, not from what was there before.
     * A submission writes a row, stores an upload and sends mail, so it is expensive and its abuse is spam that a
     * human has to clean up. A cover image is a file read that a browser may legitimately ask for many times while a
     * page renders. Those two should never have had comparable budgets.
     */
    public const ACTIONS = [
        // ---- writes: expensive, and abuse means rows and files a person must clean up -------------------------
        // Three a minute per visitor is already far more than anyone fills in a form with an upload; a second
        // attempt after a validation error is the normal case and stays well inside it.
        'public.volunteer.submit' => [3, 'volunteer application submissions'],
        'public.membership.submit' => [3, 'membership application submissions'],
        'public.committee.register' => [3, 'committee registration submissions'],
        'public.committee.correct' => [3, 'committee correction submissions'],

        // ---- token lookups: cheap reads, but a token is a secret worth guessing ------------------------------
        // A real visitor opens their link, maybe reloads it. Thirty a minute per visitor is invisible to them and
        // useless for enumeration, which the token's own length already defeats.
        'public.token.lookup' => [30, 'registration and correction token lookups'],

        // ---- media reads: a browser may ask for many of these to render one page -----------------------------
        'public.attachment.read' => [60, 'notice attachment downloads'],
        'public.image.read' => [240, 'cover and share images'],

        // ---- content reads: every page render asks for several ------------------------------------------------
        'public.content.read' => [300, 'public content endpoints'],

        // ---- the confirmation-page proof: one page load asks once, refreshes ask again -----------------------
        'public.application.success' => [30, 'volunteer confirmation proof lookups'],

        // ---- member portal credentials -----------------------------------------------------------------------
        // These reach this application through the site's server exactly like the public forms, so they had the
        // same site-wide bucket: six member logins a minute for every member at once. They are credential
        // endpoints, so the per-visitor number stays deliberately small — small enough to stop guessing, large
        // enough for someone who mistypes a password twice and then uses the reset link.
        'member.auth.login' => [6, 'member portal sign-in attempts'],
        'member.auth.password.email' => [4, 'member password-reset requests'],
        'member.auth.password.reset' => [6, 'member password-reset completions'],

        // ---- inbound third-party callback --------------------------------------------------------------------
        // Not a browser endpoint and not behind the site: a handful of mailboxes occasionally get mail. Keyed by
        // the caller's own address, and present only as a backstop against a replaying sender.
        'webhook.mail' => [120, 'inbound Hostinger mail webhooks'],
    ];

    /**
     * What the whole site may do per minute from one proxy identity, per action.
     *
     * This is the backstop, not the limit that should ever bite. It exists for the case where per-visitor identity
     * is unavailable — no signed context, or a flood of fresh sessions — and it is sized so that a busy day of real
     * traffic stays far below it. A number this large is deliberate: the per-visitor limit is the real control, and
     * a tight shared ceiling is exactly the global coupling this work exists to remove.
     */
    public const SHARED_CEILING = [
        'public.volunteer.submit' => 60,
        'public.membership.submit' => 60,
        'public.committee.register' => 60,
        'public.committee.correct' => 60,
        'public.token.lookup' => 600,
        'public.attachment.read' => 1200,
        'public.image.read' => 3000,
        'public.content.read' => 6000,
        'public.application.success' => 600,
        'member.auth.login' => 120,
        'member.auth.password.email' => 60,
        'member.auth.password.reset' => 120,
        // The webhook has no second identity to fall back on, so its shared ceiling IS its limit.
        'webhook.mail' => 120,
    ];

    /** Register every public limiter. Called once from AppServiceProvider::boot(). */
    public static function register(): void
    {
        foreach (array_keys(self::ACTIONS) as $action) {
            RateLimiter::for($action, function (Request $request) use ($action) {
                $limits = self::limitsFor($action, $request);

                // One line per refusal, and only when a refusal happens — not on every request, which would fill
                // the log with the ordinary case. It records the action and a digest, never an address, never an
                // e-mail, never the key itself. Enough to answer "is this one abuser or everybody", which is the
                // only question worth asking from a log line here.
                foreach ($limits as $limit) {
                    $limit->responseCallback = static function (Request $request, array $headers) use ($action) {
                        Log::info('rate limit reached', [
                            'action' => $action,
                            'client' => self::observedClass($request),
                            'retry_after' => $headers['Retry-After'] ?? null,
                        ]);

                        return response()->json([
                            'message' => __('Too many attempts. Please try again later.'),
                        ], 429, $headers);
                    };
                }

                return $limits;
            });
        }
    }

    /**
     * How the request was identified, as a category rather than a value.
     *
     * "verified-visitor" or "shared-proxy" is what an operator actually needs when a limit is being hit: it says
     * whether one person is being noisy or whether the site-wide backstop is engaging, which are different
     * problems with different answers. Neither tells anyone who the visitor was.
     */
    private static function observedClass(Request $request): string
    {
        $context = PublicClientContext::fromRequest($request);

        if ($context->verified && $context->sessionId !== null) {
            return 'verified-visitor';
        }
        if ($context->verified) {
            return 'verified-address-only';
        }

        return $context->rejectedBecause === null ? 'shared-proxy' : 'rejected-context';
    }

    /**
     * The limits for one action on one request.
     *
     * @return list<Limit>
     */
    public static function limitsFor(string $action, Request $request): array
    {
        [$perVisitor] = self::ACTIONS[$action] ?? [30, ''];
        $shared = self::SHARED_CEILING[$action] ?? 600;

        $context = PublicClientContext::fromRequest($request);
        $domain = (string) ($request->route()?->getDomain() ?? $request->getHost());
        $limits = [];

        // 1. THE VISITOR. Their own session first — two people behind one office address are then independent, which
        //    is the whole point — and their own address second, so clearing cookies does not reset the allowance.
        if ($context->verified && $context->sessionId !== null) {
            $limits[] = Limit::perMinute($perVisitor)->by('sid:'.PublicClientContext::digest($context->sessionId, $domain));
        }
        if ($context->verified && $context->ip !== null) {
            // A shared address gets a multiple of one person's allowance rather than one person's: a household or a
            // small office is several visitors, and they must not be able to exhaust each other.
            $limits[] = Limit::perMinute($perVisitor * 10)->by('cip:'.PublicClientContext::digest($context->ip, $domain));
        }

        // 2. THE BACKSTOP. Always present. When a context verified, this is a wide ceiling that real traffic never
        //    reaches; when it did not, it is the only limit there is — and it is honest about being site-wide.
        $limits[] = Limit::perMinute($shared)->by('proxy:'.PublicClientContext::digest((string) $request->ip(), $domain));

        return $limits;
    }

    /**
     * An extra limit on the identity a submission claims, applied inside the controller AFTER validation.
     *
     * Rate limiting by IP cannot answer "is this the same person submitting over and over", and for a form that
     * creates work for a human that is the question worth asking. The value is HMAC'd before it is used, so no
     * e-mail address or phone number ever becomes a cache key or a log line.
     */
    public static function identityKey(string $action, string $kind, string $value, string $domain): string
    {
        return $action.':'.$kind.':'.PublicClientContext::digest(mb_strtolower(trim($value)), $domain);
    }
}
