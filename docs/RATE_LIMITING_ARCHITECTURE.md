# Provatferi — public rate limiting

Rewritten 2026-11-04, after production measurements showed two separate defects: every public route on a domain
shared **one** rate-limit counter per IP, and every public form arrived from the public site's own server, so that
one counter was effectively a limit for the whole internet.

---

## 1. What was wrong, measured

### 1a. One counter for every route

Laravel's numeric `throttle:N,M` middleware builds its key like this
(`vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php`):

```php
protected function resolveRequestSignature($request)
{
    if ($user = $request->user()) {
        return $this->formatIdentifier($user->getAuthIdentifier());
    } elseif ($route = $request->route()) {
        return $this->formatIdentifier($route->getDomain().'|'.$request->ip());
    }
}
```

For an unauthenticated request that is `sha1($domain.'|'.$ip)`. **The route is not in it, and neither is N.** So
every unprefixed numeric throttle on one domain shared a single counter per visitor, and each route merely compared
that shared counter against its own maximum. The smallest limit on the domain therefore governed everything.

Proved on the real routes before any change was made — `tests/Feature/RateLimit/PublicRateLimitTest.php`, the two
tests that failed first:

| What was done | What happened |
|---|---|
| 8 requests to `GET /public/committees/registration-links/{token}` (its own limit: 20) | each accepted |
| then 1 request to `POST /public/committee-submissions` (its own limit: 6, no traffic of its own) | **429** |
| exhausting `GET /public/committee-submissions/correction/{token}` | the registration-link lookup **also** 429'd |

The project had already met this once: `POST /recruitment/{slug}/application-success` carried a hand-written third
parameter, `throttle:600,1,application-success:`, with a comment explaining that without the prefix "every
confirmation page load would count against the 5 a minute of the application form above". That was a correct fix for
one route. Every other route still shared the default bucket.

### 1b. One counter for every visitor

The request path for a public form is:

```
browser → Cloudflare → Next.js (provatferi.org) → Cloudflare → Laravel (admin.provatferi.org)
```

The form is submitted to the Next server, which forwards it. Laravel's client is therefore **the site's own
server**, and `request()->ip()` is the same value for every visitor on earth. A per-IP limit of 5 a minute was five
submissions a minute for the entire site, which is what production behaved like.

This is not a trusted-proxy misconfiguration and must not be "fixed" as one. `bootstrap/app.php` trusts forwarded
headers **only** from `App\Support\CloudflareProxies::RANGES`, never `*`, and that is correct: it is what stops an
attacker choosing their own identity. Cloudflare faithfully reports the client of the request it forwarded — and the
client of that request genuinely *is* the Next server.

---

## 2. The client context

The only party that knows the visitor is the Next server, because it received the browser's request. So it says so,
and signs it.

| | |
|---|---|
| Headers | `X-Client-Context` (base64url JSON), `X-Client-Context-Signature` (HMAC-SHA256, hex) |
| Signed string | `"v1\n" + timestamp + "\n" + action + "\n" + ip + "\n" + sessionId` — an absent value is an empty line |
| Payload | `{v, ts, act, ip, sid}` and nothing else |
| Secret | `PUBLIC_CLIENT_CONTEXT_SECRETS`, comma-separated, newest first. Server-side in **both** apps |
| Validity | 60 seconds, plus 10 seconds of tolerance for clock skew between the two hosts |
| Comparison | `hash_equals()` on the PHP side, `timingSafeEqual()` on the Node side |

Built by `institutional/lib/client-context.ts`, verified by `admin-erp/app/Support/PublicClientContext.php`.

**Why a browser cannot forge one.** The secret exists only in the two servers' environments. It is never sent to a
browser, never embedded in a page, and the module that holds it is `server-only`, so it cannot reach a client
bundle. A browser posting straight to `admin.provatferi.org` has nothing to sign with, and an unsigned request is
simply a request with no verified context — there is no fall-back-to-the-header path.

**Why the action is signed.** A context minted for one endpoint cannot be replayed against another to buy it a fresh
allowance.

**Why `X-Forwarded-For`, `CF-Connecting-IP` and `X-Real-IP` are ignored on a public Laravel request.** On a direct
request to the origin, whoever sets them is the attacker. They are meaningful only through the trusted-proxy layer,
which already handles them. `PublicClientContext` never reads them.

**Replay.** The window is 60 seconds and the context never reaches a browser, so replaying one requires having
captured a server-to-server request already. The consequence of a replay is narrow — it would spend the *victim's*
own allowance, not bypass the attacker's — so no nonce store is kept, which on this host would mean a cache write
per request. This is a deliberate trade, written down rather than assumed.

**Rotation.** Add the new secret to the front of the list in both apps and deploy; remove the old one later. Signing
uses the first, verification accepts any, so there is no window in which valid traffic is rejected. A secret shorter
than 32 characters is treated as absent — a weak HMAC key is worse than none, because it looks like protection.

### The visitor id

`pf_vid`: a random 16-byte value in a first-party, `HttpOnly`, `SameSite=Lax`, `Secure` cookie with a 12-hour life,
set by the Next server. It is not analytics and not advertising; its only job is to tell two people behind one office
address apart so they cannot exhaust each other's form allowance. It is hashed before it reaches a limiter key.

A visitor with cookies disabled has no id and is limited by address instead — a weaker key, never a wrong one.

---

## 3. The limiters

One named limiter per action. A named limiter's key is `md5($limiterName.$limit->key)`, so **the action is part of
the key by construction** and two actions can no longer spend each other's budget.

`app/Support/PublicRateLimits.php` holds the whole inventory. Each limiter returns **several** limits at once, which
Laravel checks together.

| Dimension | Key | Limit |
|---|---|---|
| the visitor's browser | `sid:<digest>` | the action's per-visitor number |
| the visitor's address | `cip:<digest>` | **ten times** the per-visitor number |
| the shared proxy identity | `proxy:<digest>` | the action's wide ceiling |

The address dimension is a multiple on purpose: a household or small office is several people, and they must not be
able to exhaust one another. The proxy ceiling is the backstop — when a context verified it is a number real traffic
never reaches; when none verified it is the only limit there is, and it is honest about being site-wide.

Every digest is `hash_hmac('sha256', domain.':'.value, config('app.key'))` truncated to 20 characters. No address,
e-mail or session id is ever written into a cache key or a log line.

### Inventory and limits

| Action | Route | Per visitor /min | Shared ceiling /min |
|---|---|---|---|
| `public.volunteer.submit` | `POST /public/recruitment/{slug}/applications` | 3 | 60 |
| `public.membership.submit` | `POST /public/membership/applications` | 3 | 60 |
| `public.committee.register` | `POST /public/committee-submissions` | 3 | 60 |
| `public.committee.correct` | `POST /public/committee-submissions/correction/{token}` | 3 | 60 |
| `public.token.lookup` | `GET` registration-link and correction lookups | 30 | 600 |
| `public.attachment.read` | `GET /public/notices/{slug}/attachment` | 60 | 1200 |
| `public.image.read` | notice cover, notice share-image, job-posting share-image | 240 | 3000 |
| `public.content.read` | reserved for public content endpoints | 300 | 6000 |
| `public.application.success` | `POST /public/recruitment/{slug}/application-success` | 30 | 600 |
| `member.auth.login` | `POST /member/auth/login` | 6 | 120 |
| `member.auth.password.email` | `POST /member/auth/password/email` | 4 | 60 |
| `member.auth.password.reset` | `POST /member/auth/password/reset` | 6 | 120 |
| `webhook.mail` | `POST /webhooks/hostinger-mail` | — | 120 |

**Why these numbers.** They follow what the action costs and what abusing it achieves, not what was there before.

- A **submission** writes a row, stores an upload and sends mail, and its abuse is spam a person has to clean up.
  Three a minute per visitor is far more than anyone fills in a form with a photograph; resubmitting after a
  validation error is the normal case and sits well inside it.
- A **token lookup** is a cheap read, but a token is a secret worth guessing. Thirty a minute is invisible to someone
  opening their own link and useless for enumeration, which the token's length already defeats.
- An **image** may legitimately be requested many times while one page renders, so it gets the largest allowance of
  the reads. A flood of cover images can no longer touch a form: that is the whole point of §1a being fixed.
- **Member credentials** stay small — small enough to stop guessing, large enough for someone who mistypes a
  password twice and then uses the reset link.
- The **webhook** is not a browser endpoint and has no second identity, so its shared ceiling *is* its limit.

### The identity limit

`app/Http/Middleware/ThrottlePublicIdentity.php`, aliased `throttle.identity`, on the four submit routes.

A per-visitor limit asks "is this browser doing too much"; it cannot ask "is this the same person submitting from
everywhere". For a form whose abuse creates work a human must undo, that second question matters, and a determined
submitter changes address and clears cookies long before they change their e-mail.

**5 attempts per identity per 10 minutes.** Fields: `email`, `phone`, `mobile`, `contact_number` — only these. A
field that is merely long or merely unique is not an identity, and keying on one would make the limiter's
cardinality unbounded, which is its own denial of service. An e-mail is lower-cased and must look like an address; a
phone number keeps its last nine digits, so a local and an international spelling of one line collapse together.

The value is HMAC'd before use. Nothing readable reaches the cache — which on this host is a **database table**, so
an e-mail address there would be a quiet leak with a long tail.

---

## 4. Shared networks, and what a visitor sees

| Situation | What happens |
|---|---|
| One person, one browser, filling in a form | never notices a limit |
| One person resubmitting after a validation error | never notices a limit |
| Two people in one office, different browsers | independent: different `sid` |
| A building behind one address | the address dimension is ten times one person's allowance |
| One browser scripting a form | limited at 3/min, then by identity at 5 per 10 minutes |
| A bot rotating address and cookies, same e-mail | limited by identity |
| A bot rotating everything | limited by the action's shared ceiling, and only for that action |
| A flood of cover images | limited on `public.image.read` alone; forms are untouched |

---

## 5. The 429 contract

Laravel answers `429` with `Retry-After`, and a body of exactly one translated sentence:
`{"message": "Too many attempts. Please try again later."}`.

It never contains a limiter key, a digest, an e-mail, a phone number, an address, an action name or anything about
the proxy. A test asserts that (`test_l_a_refusal_is_429_with_a_usable_retry_after_and_no_internals`).

The public site maps it to its own reason rather than a generic failure, because this is the one refusal a visitor
can act on:

- `lib/api/client.ts` returns `{ error: "rate_limited", retryAfterSeconds }`, reading `Retry-After` and dropping a
  missing or absurd value to `null` rather than inventing a number;
- `lib/upload-route.ts` turns that into the form's ordinary error state, so no form needed changing;
- `lib/form-messages.ts` writes the sentence in Bangla or English, with the wait rounded up to whole minutes, and
  says "in a moment" when there is no figure.

Both the JavaScript submit and the no-JavaScript Server Action go through the same upstream functions, so both are
limited and both show the same message. That is why the context is attached in the shared API client rather than in
the five route handlers.

---

## 6. Store, failure behaviour and cost

The limiter uses Laravel's cache, and production's `CACHE_STORE` is **`database`** (no Redis on this plan, and none
is being introduced). `increment` on the cache table is atomic, and the limiter writes one row per key per window
with a TTL, so expiry is automatic and a counter cannot leak.

Cost: one read and one write per limited request per dimension — three for a submission. The submit routes are
low-traffic by nature; the read routes have wide ceilings, so their counters are touched often but are single-row
updates on a primary key.

**If the store fails**, the limiter's `tooManyAttempts` raises and the request fails as a 500 rather than being
silently allowed or silently blocked. There is no permanent block to get stuck in: every counter is TTL-based, so
recovery needs no intervention. The store is the same database the application already requires for every request,
so a store failure is not a separate failure mode from an outage — it is one.

---

## 7. Troubleshooting

**A visitor reports being blocked.** Look for `rate limit reached` in the Laravel log. One line per refusal, with
the action, a `client` class (`verified-visitor`, `verified-address-only`, `shared-proxy`, `rejected-context`) and
the `retry_after`. No PII, deliberately. `verified-visitor` means one browser is noisy; `shared-proxy` means the
site-wide backstop engaged, which is a different problem.

**Everyone is being blocked on one form.** Check whether contexts are verifying at all: a `client` class of
`shared-proxy` or `rejected-context` on every line means the secret does not match between the two apps, or is
missing from one. Nothing breaks when it is missing — the limits simply collapse back to site-wide ceilings — so
this can go unnoticed, which is why the log line names the class.

**A context is being rejected.** `PublicClientContext::fromRequest()` returns why in `rejectedBecause`: a signature
mismatch (the secrets differ), an expired context (more than 60 seconds old — check the clocks), or an unusable
action name.

**After rotating the secret**, both apps must be deployed before the old secret is removed from the list.

---

## 8. What this does not do

1. **It does not identify people.** `pf_vid` is a random value with a 12-hour life, used only to separate visitors
   inside a rate limiter, and hashed before it is used. It is not linked to an account or an application.
2. **It does not protect against a large distributed botnet** with a fresh address and cookie per request. That
   traffic falls to the action's shared ceiling, which is wide. Narrowing it further would start blocking real
   visitors, and the right answer at that point is at the edge, not in the application.
3. **It adds no per-visitor limits to content reads.** Attaching a context to an SSR read would mean reading a
   cookie during a page render, which opts that page out of static rendering — and how the public site caches pages
   is deliberately not this work's business. Content reads keep a wide shared ceiling.
4. **Member profile updates and receipt downloads are not covered by it**, and do not need to be: those requests
   are authenticated, and Laravel's own signature resolves an authenticated request to the account rather than the
   address, which is already per-member.
