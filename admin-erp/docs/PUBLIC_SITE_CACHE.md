# How the public site's cache follows admin changes (and the clock)

The public site (`institutional/`, Next.js, provatferi.org) builds its pages from this app's public API and keeps the
answers in Next's data cache. This document is about the two places where a stale answer is a real bug: the **homepage
carousel** and the **membership page** (registration seasons, membership types, fees, the application form). Written
2026-10-05, when the membership page was found showing a season as closed for up to a minute after an admin had opened
it.

## What went wrong (measured on production, 2026-10-05)

`lib/api/membership.ts` fetched both membership lookups with a plain time-to-live (`next: { revalidate: 60 }` for the
open seasons, `300` for the types and fees) and no cache tag, and nothing told the site when an admin changed them.

* Laravel answered correctly the moment the admin saved (`cf-cache-status: DYNAMIC`, `Cache-Control: no-cache, private`),
  and the HTML was never cached by Cloudflare (`DYNAMIC`, no `Age`, `Cache-Control: private, no-store`) — the stale
  layer was Next's server-side data cache, nothing else. A unique query string on the page changed nothing.
* After opening a season the page stayed stale for **62.9 s** (both languages); after closing it, **61.7 s**.
* Worse — the incident: after a quiet period, the **first** request following an admin's change was stale even though
  the cache entry had long expired, because a time-to-live is *stale-while-revalidate*: the first request after expiry is
  served the old copy while the refresh runs in the background. The next request was right. No time-to-live, however
  short, can make the first request after a change correct.

## The design

Three layers, each covering what the others cannot:

1. **Admin changes are announced, immediately.** Every save or delete of a `MembershipSeason`, `MembershipType` or
   `MembershipFeePolicy` — whatever screen, command or script does it — queues a cache tag in
   `App\Services\PublicSiteRevalidator` (`App\Observers\MembershipPublicSiteObserver`; a season's offered-types pivot,
   which fires no model event, goes through `MembershipSeason::syncTypes()`). One request = one signed call carrying all
   its tags. On the membership screens the call is made **before the admin's browser is told the save worked**
   (`App\Http\Middleware\FlushPublicSiteRevalidations`, route middleware `revalidate.public`), so the first public
   request after "saved" cannot beat it. The site expires the tags with `revalidateTag(tag, { expire: 0 })` — no stale
   copy is served while it refreshes.

   | tag | sent when | carried by |
   |---|---|---|
   | `membership-seasons` | a season is created, edited, opened, closed, deleted or restored; its types change | the open-seasons lookup |
   | `membership-types` | a type is created, edited (names, visibility, self-apply, status, order, code), reordered, deleted | both lookups |
   | `membership-fees` | a fee policy is created or cancelled (or its end date re-derived) | both lookups |
   | `membership` | never by an observer — an umbrella every membership lookup carries ("purge all membership data") | both lookups |
   | `homepage-carousel` | a carousel slide changes (unchanged since 2026-10-02) | the carousel lookup |

2. **Time changes are announced by the data itself.** A season opens and closes by its dates, and a fee policy starts at
   midnight in Dhaka, without anyone saving anything. So both public membership responses carry
   `meta.valid_until` — the first instant the answer can change by itself (`App\Services\MembershipPublicState`) — and
   the site never uses a cached answer past that instant: it asks Laravel again, uncached, and renders that
   (`lib/api/membership.ts`). No cron, no polling, no scheduler. Erring early is safe (one extra request), erring late
   would be the bug, so it errs early (any active future policy counts).

3. **A short safety net.** The cached copy also expires after 15 s, so a lost revalidation call heals within seconds.

And underneath all three, **Laravel is the only authority** on whether anyone may apply: the application endpoint
requires a season and checks, at that moment, that it is open and offers the chosen type (the same clock check the
public list is built from). If a season closes while a visitor has the form open, Laravel refuses and the form says so in
the visitor's language (`data-testid="season-closed"`), instead of the silent nothing it showed before. A season-less
application, which used to be accepted at any time, is refused.

## The signed call (`POST https://provatferi.org/api/revalidate`)

```
X-Revalidate-Timestamp: <unix seconds>
X-Revalidate-Nonce:     <32 random hex characters, new for every attempt>
X-Revalidate-Signature: hex HMAC-SHA256 of "v2.<timestamp>.<nonce>.<tags sorted and unique, joined by ','>"
body: {"tags": ["membership-fees", "membership-types"]}
```

* The secret (`REVALIDATE_SECRET`, in this app's `.env` and in the Node environment on Hostinger — see
  `deploy/state/last-deployed.json` → `revalidate_secret` for rotation) is never transmitted, never logged, and never
  reaches a browser.
* The site (`lib/revalidate-request.ts`, `lib/revalidate-auth.ts`) checks, in this order: secret configured (else 503);
  body shape, 1-8 string tags (400); signature in constant time and timestamp within ±300 s (401); the nonce not seen
  before (401 — the site remembers accepted nonces for the whole window, so a captured request cannot be replayed); every
  tag on the allow-list (400 — one unknown tag refuses the whole call). Every failure is a bare status code.
* The legacy single-tag form (`{"tag": …}`, HMAC of `"<timestamp>.<tag>"`, no nonce) is still accepted so the two servers
  can be deployed in either order; this app no longer sends it.
* Failure never breaks or slows a save beyond the bounded call: 3 s timeout, one retry only after a FAST failure
  (connection refused/reset or 502/503/504), every failure logged as a warning with the tags, status and URL.

## Proving it (QA kit)

* `institutional/scripts/membership-cache-qa.mjs` — real Chrome, real Admin UI. `--mode repro` reproduces the old bug;
  `--mode cases --phase core|boundary|fee-boundary` runs the acceptance cases (season open/close, type visibility,
  self-apply, enable/disable, today's fee, a future fee, a visitor applying, a form left open across a close, the end
  date, the start/end boundary with no admin action, a fee policy taking effect at midnight in Dhaka). Every "first
  request" is a fresh browser asking for `/membership` and `/en/membership` at the same time.
* `deploy/qa/membership-cache-qa.php` — `snapshot` (fingerprints of every real row, before and after), `seed-type` (one
  hidden disposable type, code `QZ`, whose first policy is dated yesterday so the Admin can be asked to change *today's*
  fee), `cleanup` (removes exactly the rows marked `QA CACHE TEST`, then tells the site).

## Traps

* A time-to-live alone can never be right on the first request after a change (stale-while-revalidate). Use tags for
  what people change and `valid_until` for what the clock changes.
* `revalidateTag()` cannot be called while a page renders, so a page cannot "fix" an outdated cache entry itself — it can
  only refuse to use it (the `valid_until` guard). The entry is replaced when the 15-second window lapses.
* `Http::fake()` only appends stubs (the first match wins) but does clear the recorder. Tests now run with
  `Http::preventStrayRequests()` (`tests/TestCase.php`): until 2026-10-05 one carousel test flushed its revalidation
  before faking HTTP and sent a real request to production on every run of the suite.
