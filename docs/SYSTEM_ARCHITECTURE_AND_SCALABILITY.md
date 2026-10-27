# Provatferi — system architecture, scalability and capacity

Audit date: **2026-10-27**. Everything here was measured against the running production system or against a local
copy of the same schema, except where it is marked **estimate** or **unknown**. No production data was changed.

How to read the labels:

| Label | Meaning |
|---|---|
| **measured** | a number read from production, or from a local run of the same code and schema |
| **estimate** | arithmetic on a measured number (a growth projection), not itself observed |
| **unknown** | this platform does not expose it, and nothing here guesses it |

The tools are in the repository and can be re-run: `admin-erp/deploy/qa/capacity-audit.php` (read-only; `host`, `db`,
`idx`, `fs`, `probe`, `routes`, `plan`, `agg`) and `admin-erp/deploy/qa/volume-sim.php` (local databases only).

---

## 0. Verdict

**The application is well built. The hosting plan is the problem.**

The code holds up to scrutiny better than most: no N+1 anywhere (measured at 0, 100 and 1,000 rows per table), the
composite indexes the queries need already exist and are chosen by the optimiser, the write paths are correct under
50-way concurrency (row-locked counters, unique indexes as the real guard, atomic locks, idempotent generation), the
image and PDF pipelines already defend against decompression attacks, and the public site degrades honestly when the
API is unavailable. The database is 4.65 MB against a 3 GB ceiling and would still be comfortable at a hundred times
today's data.

Three things are genuinely wrong, and only one of them is in the code:

1. **The PHP app cannot serve more than a handful of concurrent requests.** Between 3 and 6 simultaneous requests,
   MySQL connections start being refused by an account-level resource limit and the request becomes a hard 500.
   62 errors accumulated in a single day — and **20 of them at a minute when nothing of this audit was running**, so
   real traffic already hits it. **Nothing reported any of them.** This is the binding constraint on the whole
   platform. (§20)
2. **Every public form submission in the site shares one rate-limit counter**, whose effective ceiling is about five
   per minute. Proved on production with a read-only experiment: 61 fetches of an image endpoint locked a different
   endpoint out with a 429. (§9)
3. **There is no backup of our own** — no database dump, no upload copy, no configuration export anywhere in the
   repository. Private uploads exist once on a disk, plus accidentally inside two retired release trees that
   housekeeping refuses to delete *because* they hold the only other copy. (§17)

Nothing in the measurements argues for Redis, queues, replicas, partitioning, sharding or a rewrite. What they argue
for is **serving fewer PHP requests** (let pages prerender, cache HTML at the edge), **keying the rate limits
properly**, and **taking a backup**. All three are hours of work on the existing stack, with no new bill. The staged
roadmap in §24 says what comes after, and when — and the honest answer to "when do we outgrow shared hosting" is:
*the PHP concurrency ceiling has already been reached, but it can be side-stepped much more cheaply than by moving.*

---

## 1. The system as it actually is

Five surfaces serve Provatferi, all on **one shared Hostinger account** (`u951246149`, server `srv1515`):

| Surface | Host | Platform | Document root | Role |
|---|---|---|---|---|
| Public website | `provatferi.org` | Next.js 16 (Node) | `public_html` | every public page |
| Admin / API | `admin.provatferi.org` | Laravel 12 + PHP 8.3.36 | `public_html/admin` | the ERP, and the only writer of business data |
| Literature CMS | `cms.provatferi.org` | WordPress | `public_html/cms` | Sahittopata's editorial back end |
| Literature site | `sahittopata.provatferi.org` | Next.js (Node) | `public_html/sahittopata` | the literature front end |
| Archive | `archive.provatferi.org` | WordPress | `public_html/archive` | the personal archive |

**The account hosts 20 websites in total** (measured). Fifteen of them are unrelated to Provatferi — a POS, HR and
attendance systems, gym trackers, an ISP manager, four more WordPress sites. They share this account's inode quota,
disk quota, process limits and database connection allowance. Provatferi's headroom is therefore not Provatferi's
alone, and a neighbour's bad day is Provatferi's bad day. This is the single most important structural fact in this
document.

Databases (measured, 16 on the account): `u951246149_admin_erp` (the ERP, **4.65 MB**), `u951246149_LT5uE` (CMS, 3 MB),
`u951246149_archive_wp` (archive, 12 MB). Each database has a **3,072 MB** ceiling set by the platform.

One detail that is easy to get wrong when reading the file tree: the apex docroot `public_html` contains only an
`.htaccess` and the four sub-site folders (`admin`, `cms`, `archive`, `sahittopata`) — **the Next.js app's own files
are not in it**. The platform runs the Node application from `hbuilds/` at the domain root (measured contents:
`config`, `current`, `last-source`, `logs`, `versions` — Hostinger's own deployment layout, 3,204 inodes / 75.9 MB),
with `current` pointing at the live build. The second Node app lives in `sahittopata/` at the domain root
(2,654 inodes / 66.0 MB). So three different deployment mechanisms coexist on one account: the ERP's own
commit-based release pipeline, Hostinger's Node build system for the two Next apps, and WordPress for the other two
sites.

### What happens on one public page view

```
  visitor
    │  HTTPS
    ▼
  Cloudflare ──────── static /_next/static/*  →  HIT, max-age=31536000, immutable   (measured: age 4.2 days)
    │                 HTML                    →  DYNAMIC: NOT CACHED AT THE EDGE    (measured)
    ▼
  Hostinger Node process  (Next.js, one instance)
    │   renders the page on every request — origin sends
    │   `Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate`
    │
    ├── Next data cache (in the Node process / its filesystem)
    │     hit  → no call to Laravel at all
    │     miss → ▼
    ▼
  Laravel  admin.provatferi.org/api/v1/*     (1–4 SQL per endpoint, measured)
    │
    ▼
  MariaDB 11.8.9   (also holds the cache, the sessions and the queue)
```

So the edge protects the **assets**, and Next's data cache protects **Laravel**; nothing protects the **Node renderer**,
which runs React server-rendering for every single page view. That is where public traffic lands.

### What happens when an admin saves

```
  admin browser → Cloudflare → Laravel (admin.provatferi.org)
      every request: 1 session DELETE + 1 session INSERT (database session driver, measured)
      authorisation: user, roles, permissions, locale, active-account and two-step middleware
      the save itself, in a transaction
      then PublicSiteRevalidator → ONE signed POST to provatferi.org/api/revalidate
          HMAC-SHA256 + nonce + replay guard + tag allow-list; 3 s timeout, at most 2 attempts
          failure is logged and NEVER rolls back the save (measured in code)
  Next.js revalidateTag(tag, { expire: 0 }) → the next request rebuilds from fresh data
```

### What happens when a visitor submits a public form

```
  visitor browser
    │  POST multipart to a SAME-ORIGIN Next route handler
    │  (never a Server Action: Cloudflare's managed WAF rule for CVE-2025-55183
    │   refuses ~1 in 9 binary bodies sent that way)
    ▼
  Next route handler  →  server-to-server POST to Laravel
                         headers sent: Accept, Content-Type only — NO client IP is forwarded (measured)
    ▼
  Laravel: validate → store file → insert row → mail after the response (defer/afterResponse)
```

The missing client IP in that last hop is the root of the rate-limit problem in section 9.

---

## 2. Resource baseline (measured 2026-10-27)

### Account quota

| | Measured | Plan limit | Used |
|---|---|---|---|
| Inodes | 214,751 | **600,000** | 35.8 % |
| Disk | 8,100 MB | **50 GiB** | 15.8 % |

The limits are the constants the deploy tooling enforces (`deploy/remote/lib/housekeeping.php`), recorded from hPanel
by the 2026-10-03 hosting audit — they are not read live from an API. The deploy pipeline warns at 70 % and refuses a
non-essential deploy at 80 % of inodes.

Provatferi's own share of those inodes (measured):

| Tree | Inodes | MB |
|---|---|---|
| `laravel-admin-releases` (release history) | 39,746 | 620.9 |
| `public_html` (admin docroot + both WordPress sites) | 19,965 | 361.0 |
| `laravel-admin` (the live ERP) | 10,018 | 141.4 |
| `hbuilds` (Node build output) | 3,204 | 75.9 |
| `sahittopata` (Node app) | 2,654 | 66.0 |
| **Provatferi total** | **≈ 75,600** | **≈ 1,265** |

The other ~139,000 inodes belong to the fifteen unrelated sites.

### PHP (measured from a CLI run)

`8.3.36` · `memory_limit 2048M` · `upload_max_filesize 2048M` · `post_max_size 2048M` · `max_file_uploads 20` ·
OPcache enabled, 256 MB · extensions present include **redis**, **memcached**, imagick, gd, intl, pcntl, posix, curl.

Two cautions. These are the **CLI** values; the web SAPI may differ and this audit could not read the web process's
own `phpinfo` — **unknown**. And a loaded `redis` *extension* is not a running Redis *server*: section 7 reports what
actually answered on the server's own loopback.

### MariaDB (measured)

| | Value | Why it matters |
|---|---|---|
| Version | 11.8.9-MariaDB-log | current |
| **`max_user_connections`** | **75** | the hard ceiling on this account's simultaneous database connections |
| `max_connections` (server) | 2,000 | shared with every account on the server |
| Per-database size cap | 3,072 MB | the ERP uses 4.65 MB of it |
| `innodb_buffer_pool_size` | ≈ 100 GB | buffer-pool hit rate **99.96 %** — the server is not I/O bound |
| `transaction_isolation` | READ-COMMITTED | not MySQL's default; relevant to the concurrency reasoning below |
| `wait_timeout` / `interactive_timeout` | 20 s | a long-lived worker's idle connection is dropped quickly |
| `slow_query_log` | ON, `long_query_time` **3 s** | nothing this application does would ever reach 3 s today, so the slow log is effectively blind to it; the log itself is **unreadable from here** |
| `sql_mode` | includes `ONLY_FULL_GROUP_BY`, `STRICT_TRANS_TABLES` | strict, good |

Server-wide counters, for context only — they belong to every account on the box, not to Provatferi:
uptime 10.5 days, 19,240 questions/second, 29.1 % of temporary tables spilled to disk, 171 deadlocks, and
**`Max_used_connections` 2,002 on 2026-10-20** — the shared server reached its 2,000-connection ceiling a week before
this audit. Provatferi cannot prevent that and would see connection errors if it recurred.

### Host (measured)

64-core AMD EPYC 9354P, 503 GB RAM, 94 GB available, **load average 69.9 / 66.4 / 65.6**. A load of ~70 across 64
cores means the machine is running at roughly its capacity, continuously, for all tenants.

**Unknown, and deliberately not guessed:** this account's CPU and memory entitlement (the CloudLinux LVE extension is
not available to PHP here, so `lve_get_info()` cannot be called), entry-process and concurrent-process limits,
bandwidth allowance, and the coverage of Hostinger's own backups.

---

## 3. Database inventory and growth

61 tables, **906 rows**, **1.81 MB data + 2.84 MB index = 4.65 MB** (measured). Index currently exceeds data because
the tables are nearly empty and every table carries several indexes whose pages have a minimum size; at real volume
data dominates.

Exact row counts today (measured): users 7 · settings 27 · activities 5 · notices 1 · job_postings 1 ·
job_applications 13 · committees 1 · committee_submissions 1 · committee_members 2 · members 1 ·
leadership_profiles 8 · security_events 8 · mail_audit_events 14 · mailboxes 1 · mail_identities 6 · roles 4 ·
permissions 44 · **cache 523** · sessions 3..9 · membership_applications, memberships, membership_dues, payments,
payment_receipts, mail_sent_messages, jobs, failed_jobs: **0**.

The membership machinery is built and live but has not been used yet: there is no member, no due and no payment. Every
projection below is therefore about a system that has not yet carried its intended load.

### Bytes per row (measured locally at 1,000+ rows, so page overhead is amortised)

| Table | Bytes/row | Index share | Growth driver |
|---|---|---|---|
| `activities` | **9,273** | 4 % | one row per event; the widest table (many bilingual text columns + JSON gallery) |
| `leadership_profiles` | **6,494** | 4 % | one row per key person; essentially fixed |
| `notices` | **2,982** | 12 % | one row per notice |
| `committees` | **2,769** | 5 % | one row per committee per term |
| `members` | 2,458 | 33 % | one row per member |
| `public_member_profile_versions` | 1,966 | 17 % | one row per profile edit — append-only |
| `committee_members` | 492 | 56 % | members × committees |
| **`membership_dues`** | **390** | 32 % | **one row per member per month — the fastest-growing table** |

`membership_dues`, `payments`, `payment_receipts`, `security_events`, `mail_audit_events` and `approval_history` are
all narrow ledger tables; the content tables are 7–24× wider per row. **Scan cost follows bytes, not rows**: 1,000
`activities` rows (9.3 MB) cost more to count than 6,006 `membership_dues` rows (2.3 MB) — measured.

### Growth model

Driver assumptions, stated so they can be argued with: a membership that reaches **1,000 members** (10×: 10,000),
**200 applications a year**, **20 activities and 40 notices a year**, one committee a year, dues accruing monthly.

| Table | Today | At 1,000 members / 5 years | At 10,000 members / 10 years |
|---|---|---|---|
| `membership_dues` | 0 | 60,000 rows ≈ **23 MB** | 1,200,000 rows ≈ **470 MB** |
| `payments` | 0 | ~60,000 rows ≈ 25 MB (estimate, row width not yet measurable) | ~600,000 ≈ 250 MB |
| `payment_receipts` | 0 | ~60,000 rows ≈ 25 MB (estimate) | ~600,000 ≈ 250 MB |
| `members` + `memberships` | 1 | 2,000 rows ≈ 5 MB | 20,000 ≈ 50 MB |
| `membership_applications` | 0 | 1,000 ≈ 3 MB | 10,000 ≈ 30 MB |
| `job_applications` | 13 | 1,000 ≈ 7 MB (estimate from 13 rows) | 10,000 ≈ 70 MB |
| `activities` | 5 | 100 ≈ 1 MB | 200 ≈ 2 MB |
| `notices` | 1 | 200 ≈ 0.6 MB | 400 ≈ 1.2 MB |
| `security_events` | 8 | 100,000+ (unbounded by design) | 1,000,000+ |
| `cache` + `sessions` | 526 | bounded by TTL, not by growth | same |
| **Database total** | **4.65 MB** | **≈ 90–120 MB (estimate)** | **≈ 1.1–1.3 GB (estimate)** |

**The database is not the constraint.** Even the 10,000-member, ten-year case stays inside the 3,072 MB per-database
cap, with the caveat that `security_events` and the audit tables are the only ones with no natural ceiling (section 14).

---

## 4. Queries and indexes

Method: every public endpoint was handled through the real HTTP kernel **on production**, each statement captured with
its real bindings, then `EXPLAIN`ed against production statistics. Plans run with substituted placeholder values are
worthless — the optimiser estimates for a row set that does not exist — so this audit uses the application's own
bindings.

**The index design is sound.** The composite `(status, <ordering column>)` indexes that these queries need already
exist and are chosen: `leadership_profiles_status_display_order_index`, `activities_status_start_datetime_index`,
`job_postings_status_published_at_index`, `homepage_carousel_slides_status_sort_order_index`,
`notices_status_published_at_index`, `membership_dues_one_per_month` (UNIQUE), `payments_category_status_index`,
`public_member_profile_versions_member_id_is_current_live_index`.

**Do not add indexes for these.** `settings` (27 rows), `about_page` (1), `activity_types` (4), `membership_types` (5),
`membership_fee_policies` (6), `objectives` (12), `organizational_units` (1) all show `type=ALL`. A full scan of 27 rows
is the correct plan; an index would be slower and would cost write time. They appear in the audit output flagged, and
they are deliberately left alone.

**Referential integrity is explicit and consistent** (105 foreign keys read from `information_schema`). The pattern is
deliberate rather than accidental: `RESTRICT` where deleting the parent would falsify a record (a `membership_type`
that dues reference, a `payment` a receipt was issued for), `SET NULL` for attribution columns (`created_by`,
`reviewed_by`, `approved_by` — a departed staff account must not take the record with it), and `CASCADE` only for
genuinely owned children (`committee_positions` of a committee, `mail_identities` of a mailbox,
`public_member_profile_versions` of a member). Nothing relies on application code to keep the graph honest.

No **redundant** index was found. `leadership_profiles` carries both `(status, display_order)` and
`(status, featured, display_order)`, which looks duplicated but is not — the second serves `?featured=1` and cannot
serve the unfiltered ordering, because `featured` sits between the two columns the unfiltered query needs. Worth
noting for write cost: `payments` has **ten** indexes (82 % of its size), six of them single-column foreign-key
indexes. That is correct — a foreign key needs one — but it means every payment insert maintains ten index entries,
so the ledger tables will stay index-heavy as they grow.

Three findings that are real:

1. **`committees` has no index on `status`** — measured `type=ALL ... Using filesort` on production. Irrelevant today
   (1 row) and for years to come (one committee per term). *Candidate at ~5,000 rows; not now.*
2. **`notices` list ordering cannot use an index.** The query orders by an expression
   (`CASE WHEN is_pinned = 1 AND ... THEN 0 ELSE 1 END`), then `published_at DESC`, then `id DESC`. An expression
   cannot be indexed, so every notices page does a filesort of the whole filtered set, and the type-count aggregate
   also needs `Using temporary`. Measured: 4 queries, 1.77 ms on production (1 notice); 14.9 ms locally at 1,000.
   *Linear in the number of published notices. Revisit past ~10,000 notices — i.e. probably never.*
3. **Notice search is a leading-wildcard LIKE.** `title LIKE '%term%' OR summary LIKE '%term%'` cannot use an index at
   all. The input is correctly bounded (`q` max 100 characters, `%`/`_`/`\` escaped with `addcslashes`) and `per_page`
   is validated `between:1,50`, so this is not an abuse vector — it is simply a full scan per search.
   *A FULLTEXT index becomes worth it past ~20,000 notices. Not now.*

And one that will matter when the membership is real:

4. **`membership_dues` "outstanding" aggregates scan the table.** `WHERE status <> 'paid'` is anti-selective, so no
   index can serve it: measured `type=ALL`, 6,001 rows, **1.5 ms** at 6,006 rows. Linear: ~15 ms at 60,000 rows,
   ~150 ms at 600,000. This is the query a dues dashboard tile would run — see section 16, where the answer is a
   summary table rather than an index.

---

## 5. ORM behaviour and N+1

**There is no N+1 anywhere on the public API, and this was measured rather than reasoned.** Each endpoint was run
against 0 rows, ~100 rows and ~1,000 rows per table. The query count did not move:

| Endpoint | Queries (0 / 100 / 1,000 rows) | Production |
|---|---|---|
| `/api/v1/settings` | 1 / 1 / 1 | 1 |
| `/api/v1/about` | 4 / 4 / 4 | 4 |
| `/api/v1/public/leadership` | 1 / 1 / 1 | 1 |
| `/api/v1/public/notices` | 3 / 3 / 4 | 4 |
| `/api/v1/activities` | 1 / 4 / 4 | 4 |
| `/api/v1/job-postings` | 2 / 2 / 2 | 3 |
| `/api/v1/public/committees` | 3 / 3 / 3 | 3 |
| `/api/v1/public/members` | 1 / 2 / 2 | 1 |
| `/api/v1/public/membership/campaigns/current` | 4 / 4 / 4 | 4 |
| `/api/v1/membership-types` | 4 / 4 / 4 | 4 |

The member directory deserves a note because it looks like the classic trap and is not: it eager-loads the live
profile version with a window function (`row_number() over (partition by member_id)`), which is **2 queries for any
number of members**. The cost is that the derived table scans all profile versions — measured 11.85 ms at 100 versions,
and `public_member_profile_versions` is append-only (one row per edit). *Watch past ~100,000 versions.*

All 47 parameterless admin screens were profiled with a signed-in session (local, same schema): **5 to 21 queries
each**, queries ≈ distinct statements everywhere, so no screen repeats a statement per row. The heaviest is the
dashboard at 21 queries / 43 ms.

Two real issues, neither of them an N+1:

**(a) Two public endpoints return every row, with no limit.** Measured payloads:

| Endpoint | 8 rows (production) | 100 rows | ~1,000 rows |
|---|---|---|---|
| `/api/v1/public/leadership` | 31.4 KB | 678.8 KB | **3,739.6 KB** |
| `/api/v1/public/committees` | 1.1 KB | 213.1 KB | **2,098.4 KB** |

`LeadershipController` has a `MAX_LIMIT = 50`, but it is applied **only when the caller passes `?limit=`**; the public
site does not, so the default is unbounded. `CommitteeController` returns the whole "previous committees" list, which
grows by one every term. `/api/v1/public/members` and `/api/v1/job-postings` are unbounded in the same way.
*None of this hurts today. The trigger is ~200 leadership profiles or ~500 committees/postings/public members.*

**(b) Every admin request writes to the database twice before doing anything.** The session driver is `database`, so
each request issues `DELETE FROM sessions WHERE id = ?` followed by `INSERT INTO sessions ...` — measured as the two
slowest statements on a cheap screen like `/admin/settings`. Cache reads are SQL for the same reason. This is the
coupling that makes `max_user_connections = 75` the real concurrency ceiling.

**(c) The dashboard runs six live `COUNT(*)` queries** — organizational units, activities, pending membership
applications, active memberships, open postings, job applications. Measured: `count(*) from activities where
deleted_at is null` took **33 ms at 1,000 rows**, because those rows are 9.3 KB wide. Section 16.

---

## 6. The public load path

Three facts, all measured:

1. **Cloudflare does not cache any HTML.** Every page returns `cf-cache-status: DYNAMIC` with
   `Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate`. Only `/_next/static/*` is cached
   (`HIT`, `max-age=31536000, immutable`, observed age 4.2 days). The public API also returns `no-cache, private`.
2. **Not one page is prerendered.** The build output settles this rather than inferring it: of 37 routes, exactly
   three are static — `/_not-found`, `/robots.txt`, `/sitemap.xml` — and **every single page route is `ƒ (Dynamic),
   server-rendered on demand**:

   ```
   ○  (Static)   prerendered as static content      →  /_not-found, /robots.txt, /sitemap.xml
   ƒ  (Dynamic)  server-rendered on demand          →  /[locale], /[locale]/about, /[locale]/activities,
                                                        /[locale]/leadership, /[locale]/notices, … all 28 pages
   ƒ  Proxy (Middleware)
   ```

   **Why**, most likely: `proxy.ts` sets an `x-pathname` request header on every request, and
   `app/[locale]/(site)/not-found.tsx` reads `headers()` to recover the browser-visible URL. A `headers()` read
   anywhere in a shared subtree opts that whole subtree out of static rendering. So a single compatibility shim for
   one error page costs the entire public site its static generation — which also costs it the Next full-route cache
   and any possibility of edge-cached HTML. *Stated as the likely cause: it fits the build output and the code, and
   it has not been proved by removing the read.*
3. What *is* cached is the **data** layer: `apiGet` fetches with `next: { revalidate: N, tags: [...] }` — 300 s for
   settings/about/organization/recruitment, 120 s for leadership/members/committees/activities/notices, 15 s for
   membership, 0 (never) for committee registration tokens.
4. **Every API call is fail-soft.** `apiGet` has a 5 s timeout, never throws, and returns a typed error the page
   branches on, falling back to the static copy in `lib/content.ts`.

What that means for the four scenarios the brief asks about:

| Scenario | Effect |
|---|---|
| **The ERP slows down** | Only requests that miss the data cache wait, up to 5 s, then fall back. A cached page is unaffected. Admin screens slow down fully — they have no cache in front of them. |
| **The ERP is unavailable** | Public pages still render: `ok: false` → `lib/content.ts` fallback, with the real content missing but no error page. Forms fail with the generic message. The admin panel is down. |
| **The cache is cold** (a Node restart, a deploy, a tag invalidation) | The first request for each tag calls Laravel; nothing coalesces those calls, so N simultaneous first-visitors cause N calls. The windows are short (15–300 s) and the endpoints cost 1–4 queries, so this is cheap today. |
| **Cloudflare is bypassed** | Static assets then come from the origin too. Since HTML was never edge-cached, HTML traffic is unchanged — the edge is protecting assets, not load. |

---

## 7. Cache architecture

| Layer | Where it lives | What it holds | Invalidated by |
|---|---|---|---|
| Laravel application cache | **MariaDB** (`cache`, `cache_locks` — 523 rows measured) | `setting:<key>` (forever), Mail Center folder and mailbox lists (60 s), send claims, atomic locks | model observers `Cache::forget`, TTL |
| Laravel config/route/view cache | files in the release | compiled config, routes, Blade | rebuilt on every `switch` |
| Next data cache | the Node process / its filesystem | one entry per API fetch, tagged | `revalidate` window, or a signed `revalidateTag(tag, {expire: 0})` |
| Cloudflare | the edge | `/_next/static/*` only | immutable filenames |
| Browser | the visitor | the same immutable assets | filename change |

**No duplicate caching:** the two caches hold different things (Laravel caches rows, Next caches API responses) and
only one of them, the Next one, sits in front of a network hop.

**Invalidation gaps.** The signed revalidation covers the tags on its allow-list (`site-settings`,
`homepage-carousel`, `leadership`, `members`, membership tags). Content that is *not* tagged — activities, notices,
committees, job postings — relies on its 120–300 s window, so an admin's change to a notice is visible to the public
within two minutes rather than immediately. That is a deliberate trade-off, not a defect, but it is worth stating.

**Stampede: measured, real, and currently harmless.** 30 processes asking for the same cold key produced **10
independent builds** — `Cache::remember` on the database store is not atomic. It does not matter today because every
producer behind a `remember` is cheap (a single-row settings query). The one producer that is *not* cheap is the Mail
Center's folder/mailbox list, whose producer is a Hostinger API call — and that is used by one or two staff. The
volunteer intake, where a duplicate really would matter, correctly uses `Cache::lock` instead, which section 8 shows
holds under concurrency.

**Two things are per-process, and that constrains horizontal scaling.** Next's data cache and the revalidation
**replay guard** (`createReplayGuard()`, an in-memory set) both live inside the single Node process. A second Node
instance would have its own cache (so `revalidateTag` would only reach one of them) and its own nonce set (so the
replay guard would stop being a guard). Any move to more than one instance must come with a shared cache handler and
a shared nonce store.

**Redis: NOT YET — and not actually available.** The `redis` and `memcached` PHP *extensions* are loaded, which
invites the assumption that a server is there. It is not: a loopback connect to `127.0.0.1:6379` and
`127.0.0.1:11211` from the account itself returned **`redis-closed memcached-closed`** (measured). So "switch the
cache to Redis" is not a configuration change on this plan — it needs an external managed Redis or a VPS, with the
cost and the operational surface that implies.

That is fine, because nothing needs it. Redis would fix the stampede, take the session and cache writes off MariaDB
and give a shared store to a multi-instance Next app; none of those is a problem today — the cache is 523 rows, the
buffer-pool hit rate is 99.96 %, and there is one Node instance. **Trigger:** sustained admin concurrency above ~30
simultaneous requests, OR the move to a second app instance, OR a cached producer that becomes expensive.

---

## 8. Concurrency

Tested with genuinely parallel OS processes against the same schema, because a single request cannot test row locking
against itself. Production data was never involved.

| What | Processes | Result |
|---|---|---|
| **Number sequence** (`NumberSequence::next`, used for member and application numbers) | 50 | **50 distinct values, 0 duplicates, 0 failures.** Worker p95 37.8 ms, max 70.7 ms |
| **Duplicate guard** (50 processes inserting the *same* logical dues row) | 50 | **exactly 1 row created**; the other 49 refused with `UniqueConstraintViolationException` — a clean refusal, never a silent second row |
| **Atomic lock** (`Cache::lock`, the volunteer double-submit guard) | 10 / 30 / 50 | **exactly 1 holder every time** |
| **Dues generation run concurrently** (`membership:generate-dues` ×4 at once) | 4 | all exit 0; 3 + 0 + 0 + 2 rows created; **0 duplicate (member, year, month) groups** — idempotent under overlap |
| `Cache::remember` on a cold key | 30 | 10 independent builds (section 7) |

One correction worth recording, because it nearly became a false finding: an earlier version of the lock test released
the lock after 150 ms and reported *two* holders at 30 processes. That was the test's fault — a later-starting process
legitimately took the freed lock. With the holder never releasing, the count is exactly 1 at every level.

`membership_dues.outstanding_amount` is a **generated column**, so the outstanding balance cannot drift from
amount/paid/waived even under concurrent writes. Isolation is READ-COMMITTED.

**No race, duplicate row, lost update or deadlock was produced.** The write paths are correct by construction:
row-locked counters, unique indexes as the real guard, atomic locks, and idempotent generation.

---

## 9. Rate limits — the one architectural defect this audit found

Laravel's numeric `throttle:N,1` builds its key from `$route->getDomain().'|'.$request->ip()` (verified in the
framework source, `ThrottleRequests::resolveRequestSignature`). **The route is not part of the key.** Routes without a
domain constraint therefore all share **one counter per IP address** for the whole application.

Current declared limits on `admin.provatferi.org`:

| Route | Limit |
|---|---|
| `POST /api/v1/public/recruitment/{slug}/applications` | **5 / minute** |
| `POST /api/v1/public/membership/applications` | 6 / minute |
| `POST /api/v1/public/committee-submissions` | 6 / minute |
| `POST /api/v1/public/committee-submissions/correction/{token}` | 6 / minute |
| `POST /api/v1/member/auth/login`, `/password/email`, `/password/reset` | 6 / minute each |
| `GET /api/v1/public/notices/{slug}/attachment` | 60 / minute |
| `GET` share images, cover images, settings, job postings | 120 / minute |
| `POST /api/v1/public/recruitment/{slug}/application-success` | 600 / minute, **own bucket** (`application-success:`) |

Two things compound:

1. **The counter is shared**, so hits on any of these routes count against all of them. Once five hits accumulate in a
   minute, the recruitment form starts refusing — even if those five hits were notice-cover fetches.
2. **The IP is the Next.js server's**, not the visitor's, because the proxy hop forwards no client address. The
   trusted-proxy configuration is correct for browser→Cloudflare→Laravel traffic, but public form submissions do not
   take that path: they go browser→Next→Laravel.

### Proved on production, read-only

Reading the framework source establishes the key; an experiment establishes the consequence. From one address:

```
61 × GET /api/v1/public/notices/volunteer-team-call/cover        →  61 × 200   (that route's own limit is 120/min)
 1 × GET /api/v1/public/notices/volunteer-team-call/attachment   →  429, Retry-After: 52
                                                                     (that route's own limit is 60/min,
                                                                      and it had received exactly ONE request)
```

**Sixty-one harmless image fetches locked a visitor out of a different endpoint entirely.** Nothing was written and no
mail was sent; the refused route is a download, and the window expires by itself.

Net effect: **public form submissions are limited to roughly five per minute for the entire site**, because they all
arrive from one address and share their counter with every other throttled route. The `application-success:` prefix
already in the code is the scar from this being hit in production once. A recruitment drive, a membership campaign, or
a class of students applying together will hit it again.

### Proposed multi-key design (design only — not implemented in this audit)

Each limited route gets its **own named limiter**, keyed by the most specific identity available, with the route in the
key:

```
key = <route-name> : <identity>
identity, in order of preference:
    authenticated user / member id
    the forwarded client IP, when the hop is trusted and signed
    the submitted e-mail (hashed) for an intake form
    the session / form key for an anonymous multi-step flow
    the caller IP as the last resort
```

Three parts to make that real:

- **Forward the client address on the server-to-server hop** (`X-Forwarded-For` from the Next route handler) and trust
  it in Laravel only for that hop — which requires a shared secret or an allow-list of the origin's own address, since
  an unauthenticated `X-Forwarded-For` is trivially forged.
- **Give every public write route a named limiter**, so no two routes share a counter.
- **Keep a coarse per-origin backstop** at a level a real campaign will not reach (for example 120/minute for the
  whole site), so a genuine flood is still refused.

Shared-NAT reality in Bangladesh makes the identity choice matter: a university or a corporate network puts hundreds of
people behind one address, so an IP-only key punishes exactly the group an organisation most wants to reach.

---

## 10. Background work and queues

`QUEUE_CONNECTION=database`, `jobs` and `failed_jobs` tables exist and are **empty**, and **no queue worker runs**.
Work that looks asynchronous is actually deferred-but-in-process:

| Work | Today | Should it be queued? |
|---|---|---|
| Application / decision e-mail | `dispatch(...)->afterResponse()` and `defer()` — runs in the same PHP process after the response | **No.** It already keeps the response fast, and the measured win was 3.3 s → 0.6 s. A real queue would add a worker to keep alive for no gain. |
| Public-site revalidation | one signed HTTP call, 3 s timeout, 2 attempts, fail-soft | No. |
| Receipt PDF | rendered on demand per request (mPDF), no cache | Not yet. Queue it only if a bulk export is ever built. |
| Photo derivatives | synchronous on upload, with a decode-pixel ceiling | No — the visitor must know the upload succeeded. |
| Mail Center send | synchronous (API or SMTP) with a claim key | No. |
| Inbound mail webhook | synchronous handler | No. |
| **Future: bulk exports, collection reports, payment-gateway callbacks** | — | **Yes** — these are the first genuine queue workloads. |

**Is a persistent worker viable here?** Partly. `pcntl` and `posix` are available, but there is no process supervisor,
`wait_timeout` is 20 s (so an idle worker's connection drops constantly), and this account's process limits are
**unknown**. The honest answer is that a long-lived `queue:work` is not a supported shape on this plan.

If a queue becomes necessary, in order of preference: **(1) a cron-driven `queue:work --stop-when-empty` every minute**
— no supervisor needed, the database queue already configured, and the existing cron mechanism already proven;
(2) the same on a VPS with a real supervisor; (3) an external queue, which is only worth its operational cost once
there is more than one app instance. There is no reason to adopt any of them today.

---

## 11. Cron and the scheduler

**There is exactly one legitimate scheduled job** (verified against the cron list API):

```
5 18 * * *   php .../laravel-admin/artisan membership:generate-dues
```

Facts: Laravel's scheduler is **not** used at all — `bootstrap/app.php` registers no `withSchedule`, and there is no
`schedule:run` cron. Any `Schedule::` definition added later would silently never run; a future task that needs one
must add the `schedule:run` entry too.

| Property | Status |
|---|---|
| Idempotency | **proven** — 4 concurrent runs, 0 duplicates (section 8) |
| Overlap protection | not needed, because the work is idempotent and the unique index is the guard |
| Duration | milliseconds today; linear in the number of active memberships |
| Failure visibility | **the cron output only.** Nothing alerts, and the job's log disappears when the job is deleted. A silent failure would go unnoticed until someone looked. This is the single biggest observability gap (section 19). |

**The hidden Hostinger scheduler jobs are not fixed and are not claimed to be.** Two minutely jobs (`tHqWoa4E6h`,
`4LOktkSo`/`4mOktkSo` family) exist outside the list API; one was neutralised reversibly through its lock file in
October and the other was left alone. Only Hostinger can remove the crontab lines. Nothing in this audit changed them.

---

## 12. Files, uploads and inodes

Measured today:

| Tree | Files | MB | Mean file |
|---|---|---|---|
| private `applications` (CV + photo) | 19 | 10.66 | 574 KB |
| private `committee-submissions` | 1 | 2.07 | 2,122 KB |
| private `notices`, `job_postings` | 3 | 0.51 | ~170 KB |
| public `leadership` (derivatives) | 16 | 1.33 | 85 KB |
| public `homepage-carousel` | 2 | 2.99 | 1,532 KB |
| public `committee` | 1 | 2.07 | 2,122 KB |
| **Total live uploads** | **42** | **≈ 19.6** | |

Per-unit costs, measured: **one recruitment application ≈ 820 KB and ~1.5 files** (10.66 MB / 13 applications); one
committee photo ≈ 2.07 MB, stored twice (private original + public copy); one leadership profile = 2 derivatives
≈ 170 KB total.

### Growth at scale (estimate, from those measured per-unit costs)

| Applications | Files | Disk | Share of the 50 GiB plan | Inodes | Share of the 600,000 inode plan |
|---|---|---|---|---|---|
| 10,000 | ~15,000 | **8.2 GB** | 16 % | ~15,000 | 2.5 % |
| 100,000 | ~150,000 | **82 GB** | **164 % — exceeds the plan** | ~150,000 | 25 % |
| 1,000,000 | ~1,500,000 | 820 GB | impossible | 1,500,000 | **250 % — exceeds the plan** |

So **disk runs out before inodes do**, and it runs out at roughly **60,000 lifetime applications** (≈ 50 GB), with the
other fifteen sites already consuming 8.1 GB of the same quota. At the organisation's realistic rate (hundreds of
applications a year) that is a decade away — but a single viral recruitment round changes the arithmetic.

**Local or shared-host storage stops being sensible when any of these is true:** total uploads pass ~20 GB (40 % of
plan disk), uploads alone pass ~100,000 files, or a backup of the uploads can no longer be taken in a maintenance
window. None is true today: uploads are 19.6 MB and 42 files.

---

## 13. Object storage decision

**NOT YET.** 19.6 MB in 42 files does not justify the operational cost of an S3-compatible bucket, a credential to
rotate, a CDN origin to configure and a migration of every stored path.

Move when **any one** of these triggers fires:

| Trigger | Threshold | Why |
|---|---|---|
| Upload volume | **> 20 GB** | 40 % of plan disk, shared with 15 other sites |
| Upload file count | **> 100,000 files** | inode pressure and backup time |
| Backup pain | a full upload backup no longer completes in a window | the real operational trigger |
| CDN origin load | image requests dominate origin CPU | offload to the bucket |
| A second app instance | the moment it exists | two instances cannot share a local disk |

---

## 14. Partitioning and pruning

MariaDB partitioning is **not implemented and not recommended now**. Classification, with the reason:

| Table | Verdict | Reasoning |
|---|---|---|
| `security_events` | **Strong future candidate** — partition by month | append-only, time-queried (`created_at >= now() - 30 days`), unbounded by design. Pruning genuinely helps: the common query touches one or two partitions. Candidate at **> 5 M rows or > 2 GB** |
| `mail_audit_events` | Partition-ready schema only | same shape, but bounded by staff activity — it will not reach millions |
| `approval_history` | Partition-ready schema only | append-only audit, grows with decisions, not with traffic |
| `membership_dues` | **No partitioning needed** | the hot queries are `membership_id` (index `one_per_month`) and whole-table aggregates. Partitioning by year would NOT help the aggregate — it reads every partition — and a summary table (section 16) solves that properly |
| `payments` | No partitioning needed | same reasoning; and financial records are queried by member and by period, both indexed |
| `payment_receipts` | No partitioning needed | append-only but small; looked up by receipt number (unique index) |
| `mail_sent_messages` | No partitioning needed | one row per human-sent message — hundreds a year |
| `cache`, `sessions`, `jobs` | No partitioning needed | TTL-bounded, not growth-bounded |
| content tables (`activities`, `notices`, `committees`, `leadership_profiles`) | No partitioning needed | tens to hundreds of rows, ever |

**Where pruning would genuinely improve a query:** only `security_events`, and only for its time-windowed reads. Where
it would not: every aggregate that sums or counts across all time, which is most of what a dashboard wants — those
need pre-aggregation, not partitioning.

---

## 15. Archive and retention

The division that matters is **business record** versus **operational log**.

| Data | Class | Retention |
|---|---|---|
| Members, memberships, applications, dues, payments, receipts | **business record** | **keep for ever.** Never hard-delete; the registry already archives rather than deletes |
| Committee submissions and their photos | business record | keep; the public copy may be regenerated |
| `approval_history` | business record (audit trail) | keep for ever — it is the evidence for a decision |
| `security_events` | operational log | keep 12–24 months hot, then archive or drop. Nothing needs a 2026 failed-login in 2030 |
| `mail_audit_events` | operational log | keep 24 months |
| `mail_sent_messages` | operational record (no content) | keep 24 months |
| `cache`, `cache_locks`, `sessions` | operational | self-expiring; no policy needed |
| Replaced or deleted photos | operational | delete with the record; the derivative is rebuildable from the original |
| Temporary uploads | operational | already cleaned on failure |
| Old release trees | operational | **keep 2** (policy) — section 23 |
| Generated receipt PDFs | not stored | rendered on demand; nothing to retain |
| QA artefacts | operational | removed at the end of each task, by standing practice |

No retention job exists for any of the operational logs. None is needed yet (8 security events, 14 audit events), but
`security_events` is the one that will need one.

---

## 16. The future admin dashboard

The current dashboard already runs **six live `COUNT(*)` queries** per load. Measured: counting 1,000 `activities`
rows took **33 ms**, because those rows are 9.3 KB wide; counting 6,006 narrow `membership_dues` rows took **1.5 ms**.
Extrapolating the dues figure linearly: ~15 ms at 60,000 rows, ~150 ms at 600,000, ~1.5 s at 6 M.

Recommended data architecture, decided now so charts do not get built on raw scans:

| KPI shape | Use | Why |
|---|---|---|
| "how many right now" (active members, pending applications, open postings) | **live query**, cached 60–300 s | the counts are cheap and selective; staleness of a minute is acceptable on a dashboard |
| "this month so far" (dues raised, payments received) | **live query over an indexed period**, cached | one month is a bounded range |
| **any time series** (members per month, dues collected per month, applications per month) | **a monthly summary table**, written once per period and never recomputed | a chart over 60 months must not scan the whole ledger on every page load |
| "all-time totals" | **a daily summary table**, or a counter row | an unbounded `SUM` over a growing ledger is the thing that eventually breaks |

Concretely: one `membership_monthly_summary` table keyed `(period_year, period_month)` holding dues raised, dues paid,
dues outstanding, payments verified, new members and leavers — appended by the same daily cron that already generates
dues, and backfilled once. Every chart then reads tens of rows instead of millions. **This audit does not build it.**

---

## 17. Backup, restore, RPO and RTO

**This is the weakest part of the platform.**

| Asset | Backup today |
|---|---|
| ERP database | **Hostinger platform backups only — coverage, frequency and retention unknown** (no backup endpoint exists on the hosting API; the owner must read it in hPanel) |
| Private uploads (applications, committee photos) | **none of our own.** They exist once on the live disk — plus, accidentally, inside two retired release trees |
| Public uploads (leadership, carousel) | same; derivatives are rebuildable from originals, and the originals are private uploads |
| WordPress databases (CMS, archive) | platform only, **unknown** |
| WordPress media | platform only, **unknown** |
| `.env` / configuration | **not backed up anywhere.** It is not in Git by design (the repository is public), so if the server were lost the configuration would have to be reconstructed |
| Provider configuration (DNS, mail, Cloudflare) | `admin-erp/docs/dns-backups/` holds DNS exports; mail and Cloudflare settings are not exported |

There is **no backup script in the repository** — no database dump, no upload sync, no off-site copy. Nothing verifies
that a platform backup exists or is restorable.

### Restore drill (performed, non-production)

A real dump-and-restore of the same schema, into a scratch database on a local MariaDB, with the synthetic volume from
this audit (25.16 MB, 59 tables, 1,000 notices / 1,000 activities / 1,100 leadership profiles / 6,006 dues rows):

| Step | Measured |
|---|---|
| `mysqldump --single-transaction --quick --routines --triggers --events` | **489 ms**, 16.87 MB of SQL |
| restore into an empty database | **2,272 ms** |
| verification | 59/59 tables, every row count identical, the generated column intact |

Throughput ≈ 7.4 MB of SQL per second. Production's 4.65 MB would dump in well under a second and restore in about
one; even a 2 GB database would restore in roughly 4–5 minutes. **The database is not the restore risk.**

### Realistic RPO and RTO as things stand

| | Database | Uploads | Configuration |
|---|---|---|---|
| **RPO** | unknown — whatever Hostinger's snapshot interval is (assume 24 h until verified) | **unbounded: a deleted upload is gone** unless it happens to sit in a retained release tree | unbounded |
| **RTO** | minutes once a dump is in hand | hours to days, and incomplete | hours, and from memory |

With one's own nightly dump plus an upload sync, **RPO 24 h / RTO under 2 hours** is achievable on this hosting with no
new infrastructure. That is the single highest-value change in this document.

---

## 18. Failure modes

| Failure | Public effect | Admin effect | Data-loss risk | Recovery |
|---|---|---|---|---|
| **MariaDB unavailable** | pages render from the Next data cache; on a miss, the static fallback; forms fail | total outage | none (nothing is written) | automatic when the DB returns |
| **Shared DB hits its 2,000-connection ceiling** (observed 2026-10-20) | as above, intermittently | intermittent errors | none | outside our control; a retry/backoff on the API client would soften it |
| **The account's own resource limit refuses MySQL connections** (**observed, section 20**) | pages whose data cache just expired render empty and `noindex`; nobody is told | 500s on admin screens | none | automatic once concurrency drops; the fix is to need fewer PHP requests (edge-cached HTML, static pages) and to retry on the client |
| **Laravel API unavailable** | pages still render — 18 pages fall back to the static copy in `lib/content.ts`, and a list page (leadership was read to confirm) renders an **empty list and marks itself `noindex, follow`** so a hollow page is never indexed; forms fail with the generic message | total outage | none | automatic |
| **Next app unavailable** | **the public site is down** (no edge HTML cache to serve from) | unaffected | none | restart the Node app |
| **Hostinger Mail API unavailable** | none | Mail Center reading fails with a typed `unavailable`; the panel says so | none | automatic |
| **SMTP unavailable** | a submitted form still succeeds — mail goes out after the response and a failure is logged, not surfaced | alias sending fails | **a notification can be silently lost** | manual resend; nothing retries |
| **Cloudflare unavailable or bypassed** | assets come from the origin; HTML is unchanged | unchanged | none | automatic |
| **Disk full** | uploads fail; Next cache writes fail | uploads and PDF temp files fail | a part-written upload is possible; the deploy tooling refuses at 90 % | free space; housekeeping |
| **Inode limit reached** | same | **deploys refuse at 80 %** by design | none | housekeeping |
| **Dues cron fails** | none | dues are not generated that day | none — the job is idempotent, the next run catches up | automatic next day; **but nobody is told** |
| **Deploy partially fails** | none — the switch is a single atomic rename after every gate passes | none | none; uploads made during the window are merged, and collisions refuse | `rollback` to the retained previous tree |
| **CMS / archive unavailable** | those two sites only | unaffected | none | WordPress-level recovery |

The pattern is good: **the public site degrades rather than breaking**, and the ERP is the only writer so an outage
costs availability, not integrity. The two real exposures are a **silently lost e-mail** and a **silently skipped
cron**.

---

## 19. Observability

What exists today:

| Signal | How it is seen |
|---|---|
| Laravel exceptions | `storage/logs/laravel.log` on the server — read only by looking |
| HTTP 5xx | not collected anywhere |
| Deploy health | `smoke-test-live` after each switch: routes, `APP_DEBUG`, log size, upload sentinel |
| Capacity | `release-manager usage` + `_usage-history.jsonl`, and a capacity gate in the pipeline |
| Cron result | the job's own output, and only until the job is deleted |
| Mail identity | `php artisan mail:identity`, and a `mail_identity` smoke check |
| PDF engine | `php artisan pdf:self-check` |
| Cron inventory | `deploy/qa/cron-guard.php` + `cron-known.json` |
| Suspicious auth | `security_events` rows — queried, never alerted on |
| Slow queries | the server's slow log, **unreadable from here**, and `long_query_time` is 3 s so nothing of ours would qualify |
| Failed webhooks | `mail_webhook_events` rows |
| Uptime | **nothing** |

**The gap is alerting, not instrumentation.** Every one of those signals requires a human to go and look. Nothing
tells anyone that the site is down, that the dues cron stopped, that an e-mail failed, that disk is filling, or that
exceptions are being thrown.

A minimal stack proportionate to this hosting, in order of value per effort:

1. **An external uptime check** on `provatferi.org/` and `admin.provatferi.org/login` (any free monitor), alerting to
   e-mail or Messenger. Catches the failure mode with the worst public effect.
2. **A daily health cron** that reports in one message: dues-cron result, Laravel log size and the last few errors,
   inode and disk percentage, database size, failed-job count, and the count of `security_events` of interest. One
   e-mail a day to `security@`, which already exists and is already read.
3. **Make the dues cron speak up**: let it send a one-line message when it finishes abnormally, so silence means
   success rather than nothing.
4. **Surface the e-mail failures that are currently only logged** — a counter and a daily line in the same report.
5. Only later, and only if the above proves insufficient: a hosted error tracker (Sentry and similar work fine on
   shared PHP), and request-level metrics.

---

## 20. Load testing

### A false result, and why it is recorded here

Measured from a laptop in Bangladesh, the homepage appeared to collapse under concurrency: 5.14 rps at concurrency 1,
7.55 rps at 5, and **2.78 rps with a 6.8 s p95** at 10. That looked like origin saturation. The control disproved it:
in the same run, a **Cloudflare cache HIT** — a response that never reaches the origin at all — was equally slow
(2.97 rps, 6.6 s p95), while the dynamically rendered HTML in that same run managed 14.08 rps. The limit was the
client's uplink, not the server. **Single-client throughput figures over a long-haul link cannot measure origin
capacity**, and the dramatic reading was wrong.

What that measurement *is* good for is the visitor's experience from Bangladesh: **p50 114–139 ms** for a public page,
which is genuinely good, and all 200s with no errors at every level tried.

### Measured from the server itself

`capacity-audit.php probe` runs on the hosting account, so the client network term nearly disappears. Hard ceilings of
60 requests and concurrency 12 keep it a measurement rather than a flood. Two runs, three minutes apart:

| Target | conc 1: rps / p50 / p95 | conc 6: rps / p50 / p95 | statuses at conc 6 |
|---|---|---|---|
| `provatferi.org/` | 3.98 / **145 ms** / 684 ms | 18.41 / 214 ms / 1,040 ms | 24× 200 |
| `provatferi.org/leadership` | 0.53 / **1,476 ms** / 3,956 ms | 27.2 / **161 ms** / 839 ms | 24× 200 |
| `provatferi.org/notices` | 22.89 / **32 ms** / 87 ms | 12.17 / 339 ms / 940 ms | 24× 200 |
| `admin…/api/v1/settings` | 12.96 / 72 ms / 129 ms | 35.25 / 134 ms / 213 ms | **20× 200, 4× 500** |
| `admin…/api/v1/public/leadership` | 14.28 / 71 ms / 98 ms | 42.84 / 116 ms / 261 ms | **6× 200, 18× 500** |
| `admin…/login` | 13.84 / 62 ms / 113 ms | 26.39 / 133 ms / 776 ms | **15× 200, 9× 500** |

Read these with two cautions. The shared server's load average moved between 62 and 81 during the runs, so the
latencies are indicative, not precise. And `/leadership` being **9× faster** in the second run than the first is
almost certainly Next's on-demand **image optimiser warming up**: that page renders eight photographs, the first
requests pay to re-encode them, and later requests are served from the optimiser's cache. Treat 1,476 ms as the
cold-cache cost of a photo-heavy page and ~160 ms as the warm cost.

### The most important finding in this audit: the PHP app cannot open a database connection under concurrency

Every Node target answered 200 at every level tried. Every **PHP** target produced hard 500s:

| Run | Concurrency | `/api/v1/settings` | `/api/v1/public/leadership` | `/login` |
|---|---|---|---|---|
| 15:34 | 1 | 24 × 200 | 24 × 200 | 24 × 200 |
| 15:38 | 6 | 20 × 200, **4 × 500** | 6 × 200, **18 × 500** | 15 × 200, **9 × 500** |
| 19:17 | 3 | 12 × 200 | 12 × 200 | 8 × 200, **4 × 500** |

The cause was read from the Laravel log rather than guessed, because the two candidate explanations have opposite
consequences. It is neither a slow query nor a deadlock nor an application defect:

```
production.ERROR: SQLSTATE[HY000] [2002] Operation not permitted
  (Connection: mysql, Host: localhost, Port: 3306, Database: u951246149_admin_erp,
   SQL: select * from `sessions` where `id` = … limit 1)
```

**The connection is refused before any query runs.** The same error appears for a `sessions` lookup, a
`leadership_profiles` select and a `notices` count — whatever the request happened to ask for first. `SQLSTATE 2002
Operation not permitted` on `Host: localhost` is the Unix socket connect being denied by the kernel, which on
CloudLinux shared hosting is the signature of an **account-level resource limit** (the LVE process/memory
entitlement), not of a database problem:

- it is **not** `max_user_connections` — that is 75, and these runs used about seven connections;
- it is **not** MariaDB refusing a login — the server never saw the attempt;
- it is **not** the application — Laravel surfaced the failure, it did not cause it.

**Which limit exactly is unknown**, and this audit will not guess: the CloudLinux LVE figures are not exposed to PHP
on this plan (`lve_get_info()` is unavailable), so the one number that would name it cannot be read from here. It is
visible to the owner in hPanel's resource-usage screen.

Two honest caveats on the threshold. The probe is itself a PHP process on the same account holding its own database
connection, so the concurrency at which this begins — somewhere between 3 and 6 — **includes the probe's own
footprint**; the real-world threshold is a little higher. And the shared server's load average was 62–81 throughout,
so the exact point will move with the neighbours' behaviour.

### It is not an artefact of the probe

The log was **0 bytes** at 09:44 (the post-switch smoke test), so everything in it accumulated during the day. Its
62 error entries fall in exactly three minutes:

| Minute | Errors | Was a probe running? |
|---|---|---|
| 15:38 | **31** | yes — concurrency 6, and it received exactly **31** 500s. A perfect match |
| **19:05** | **20** | **no. No probe, no request of mine at all** |
| 19:17 | **21** | yes — concurrency 3, but it received only **4** 500s, so ≥17 errors belong to requests that were not mine |

So the condition occurred once with **no synthetic load whatsoever**, and once while affecting traffic besides the
probe's. Whatever reached the site at 19:05 — a crawler, a visitor, or the Next app refreshing several expired cache
tags at once after a quiet period — got 500s. **Real traffic already hits this.** The source cannot be identified from
here because the web access log is not readable through this interface.

(The phrase "Operation not permitted" appears 186 times against 62 error entries: one failing request logs the
message, the exception context and the trace, so error *entries* are the count to use, not phrase occurrences.)

**What it means.** The admin panel and the public API have a **single-digit concurrency ceiling on this plan, and the
failure mode is a hard 500, not a queue or a slow page.** For the public site the consequence is subtle and worse than
it looks: a page whose data cache has just expired calls Laravel, and if several visitors miss at the same moment some
of those calls fail — so the page renders with **empty content and `noindex`** (section 18) instead of an error, and
nobody is told. Which is exactly what happened here: **62 errors accumulated in one day and nothing reported them**
(section 19).

---

## 21. Capacity thresholds

Numbers that are measured, and numbers that are arithmetic on them, kept apart.

| Dimension | Today (measured) | Ceiling | Headroom |
|---|---|---|---|
| ERP database size | 4.65 MB | **3,072 MB** per database | ~660× |
| Account disk | 8,100 MB | **50 GiB** (plan) | 6.3× |
| Account inodes | 214,751 | **600,000** (plan) | 2.8× |
| Simultaneous DB connections | 3–9 sessions | **75** (`max_user_connections`) | the real concurrency ceiling |
| Public API cost | 1–4 queries, ≤ 8 ms per endpoint | — | large |
| Admin screen cost | 5–21 queries, ≤ 43 ms | — | large |
| Uploads | 42 files, 19.6 MB | ~100,000 files / ~20 GB (own trigger) | very large |
| Public form submissions | — | **≈ 5 per minute, site-wide** (section 9) | **a binding limit today** |
| **Concurrent dynamic PHP requests** | 1 is safe; 3 already fails sometimes | **between 3 and 6** before connections are refused with a 500 (section 20) | **the hardest binding limit today** |
| Concurrent Node page renders | 6 tested, all 200 | not reached in testing | — |

**Estimates, derived from the above:** the database stays comfortable to roughly 1,000,000 dues rows; disk runs out at
roughly 60,000 lifetime applications; the shared-hosting concurrency ceiling is governed by `max_user_connections = 75`
rather than by anything in the application, because sessions and the cache are database-backed.

### Triggers

| Change | Trigger |
|---|---|
| **Redis** | sustained admin concurrency > ~30 simultaneous requests, OR a second app instance, OR a cached producer that becomes expensive |
| **Dedicated queue worker** | the first bulk export, collection report, or payment-gateway callback |
| **Object storage** | uploads > 20 GB, or > 100,000 files, or backups stop fitting a window |
| **VPS** | **process limits already refuse work at 3–6 concurrent PHP requests (section 20)** — so this trigger is *already met in principle*. It is listed at Stage 3 rather than now because Stage 1 can remove most of the PHP requests instead, which is far cheaper. Revisit the moment admin concurrency alone (several staff working simultaneously) starts producing 500s |
| **Managed database** | database > ~2 GB, or a real RPO requirement with point-in-time recovery |
| **Read replica** | **not foreseeable** — reads are 1–4 queries behind two caches |
| **CDN change (cache HTML at the edge)** | public page views become CPU-bound on the Node process. This is the cheapest big win available and needs no new infrastructure |

---

## 22. Deployment and release scalability

The pipeline is sound: `stage → build → contract-check → migrate-check → smoke-test-isolated → switch →
smoke-test-live`, with the switch a single atomic rename, every Blade template compiled before a switch may be armed,
uploads made during the window merged three ways, and a capacity gate that refuses a non-essential deploy at 80 %
inodes. Rollback is a rename back to the retained previous tree.

The cost is **inodes**. A deploy writes ~8,800 files / ~141 MB, and the policy keeps the two most recent previous
trees plus 30 small release-history directories.

Measured today — **four** `_previous-*` trees exist, not two:

| Tree | Inodes | MB | Why it is still here |
|---|---|---|---|
| `_previous-20261027-094402` | 10,018 | 141.4 | the current rollback target (policy: keep) |
| `_previous-20261026-200301` | 10,021 | 141.3 | within the newest 2 (policy: keep) |
| `_previous-20261018-195402` | 9,527 | 150.7 | **blocked**: holds **5 private committee photos** the live app does not have |
| `_previous-20261003-194002` | 9,160 | 159.6 | **blocked**: holds **24 private application files** the live app does not have |
| **Total** | **38,726** | **593.0** | **18 % of the account's inodes** |

**What is preventing cleanup, exactly.** Housekeeping is fail-closed: before deleting a retired tree it compares that
tree's `storage/app` against the live one, and refuses if the retired copy holds any file the live application does
not. Those two trees each hold such files — 24 application documents from 2026-10-03 and 5 committee photos from
2026-10-18. The tooling cannot know whether they are irreplaceable records or leftovers from a QA run, so it keeps
them.

**What must be confirmed before they can go** (an owner decision, not a technical one):

1. For `_previous-20261003-194002`: are those 24 files under
   `storage/app/private/uploads/applications/{cv,photos}/` attached to real recruitment applications that are still
   wanted? The live application does not reference them, which suggests the rows were removed (a QA purge) and the
   files were orphaned — but "suggests" is not "confirmed".
2. For `_previous-20261018-195402`: the 5 PNGs under `committee-submissions/` are 2.39 MB each and all dated
   2026-10-18, which matches that day's committee-approval QA run. Again: likely QA leftovers, not proven.
3. Either way, the right order is: **copy both sets off the server first**, then confirm, then let housekeeping run.

Deleting them would recover **≈ 18,700 inodes and ≈ 310 MB** — the largest single hygiene win available.
**This audit did not delete them.**

Also present and worth a glance: four `_tooling-backup-*` / `_tooling_bak_*` directories (≈ 14 inodes and 3.6 MB each),
kept from past tooling updates; `_salvaged-uploads` (39 inodes); `_archived-logs` (17 files, 90-day policy).

---

## 23. Security questions that scale creates

| Concern | Status |
|---|---|
| Rate-limit key design | **the real defect** — section 9 |
| Shared-NAT blocking | follows from the same defect; an IP-only key punishes university and corporate networks |
| Unbounded search | **bounded**: notice search is `q` max 100 chars, LIKE-escaped, `per_page` validated 1–50 |
| Expensive API queries | **bounded** by query count (1–4) but **not by response size** on four endpoints — section 5(a) |
| File-upload amplification | **bounded**: a decode-pixel ceiling before `imagecreatefromstring`, so a decompression bomb is refused rather than decoded |
| PDF denial of service | **bounded**: the renderer checks whether decoding an image fits the remaining `memory_limit`; the member receipt route is throttled 30/minute |
| Image-processing abuse | same ceiling; derivatives are fixed sizes (1200 px / 480 px) |
| Mail abuse | per-account limits (`mail-center-poll` 60/min, `mail-center-write` 30/min, keyed by account not IP), plus per-mailbox limits and one-time form keys |
| Information disclosure | `x-powered-by: PHP/8.3.36` and `x-powered-by: Next.js` are sent on every response. Harmless on its own, but it hands a scanner the exact version. Cheap to remove |

---

## 24. Scaling stages

| Stage | Trigger | Change | Benefit | Operational cost | Needed today? |
|---|---|---|---|---|---|
| **0 — current** | — | Next + Laravel + MariaDB on one shared account, database cache/session/queue, Cloudflare for assets | works; measured headroom of 2.8× inodes, 6.3× disk, ~660× database | one account to watch | **this is where the platform is** |
| **1 — optimise what exists** | now | **(a)** cut the number of PHP requests: let pages prerender again (the `headers()` read in section 6) and cache public HTML at the edge, so most visits never touch Laravel; **(b)** retry-with-backoff in `apiGet` for a refused connection; **(c)** fix the rate-limit keys; **(d)** own nightly DB dump + upload sync off-site; **(e)** an uptime check and a daily health mail; **(f)** cap the four unbounded endpoints; **(g)** release the two blocked trees after confirming their files; **(h)** drop `x-powered-by` | (a) and (b) directly attack the measured single-digit PHP concurrency ceiling; (c) removes the rate-limit defect; (d) turns an unknown RPO into a known one; (e) means failures stop being silent | a few hours of work, no new infrastructure, no new bill | **yes — all of it** |
| **2 — external cache / queue / storage** | DB connections near 75, or uploads > 20 GB, or the first bulk export | Redis for cache+session+locks; a cron-driven queue worker; S3-compatible storage for uploads | takes session and cache writes off MariaDB; makes long work asynchronous; removes the disk ceiling | one or two more services to run, monitor and pay for | **no** |
| **3 — VPS, app separated** | persistent workers needed, or neighbour load visible in p95, or process limits refusing work | move Laravel + Next to a VPS with a real process manager; keep MariaDB managed or on the same box | predictable CPU, real workers, own PHP and Node configuration, no noisy neighbours | the owner becomes the sysadmin: patching, TLS, monitoring, backups | **no** |
| **4 — managed DB, horizontal app** | database > ~2 GB with a real recovery requirement, or one app instance cannot serve the traffic | managed MariaDB with point-in-time recovery; two or more Next instances behind a load balancer with a shared cache handler and shared nonce store | survives a node loss; scales reads and renders | significantly more cost and complexity | **no, and nothing in the measurements points this way** |

---

## 25. Explicitly not needed

On the evidence in this document, **none of the following is justified**, and adopting any of them now would add cost
and failure modes without removing a measured constraint:

Kubernetes · microservices · Kafka or any message bus · database sharding · read replicas · table partitioning ·
Redis (today) · Elasticsearch · a service mesh · multi-region anything · GraphQL · a separate API gateway.

A single well-indexed monolith behind a cache is the right shape for an organisation of this size, and the
measurements say it is nowhere near its limits. The one architectural defect found (rate-limit keying) is a
twenty-line fix, not a platform change.

---

## 26. Unknowns, and what only the owner can decide

**Unknown — this platform does not expose it:**

1. The account's CPU and memory entitlement, and its entry-process and concurrent-process limits (no CloudLinux LVE
   extension available to PHP).
2. The web SAPI's own PHP limits (only the CLI values could be read).
3. Bandwidth allowance and current usage.
4. Hostinger's backup frequency, retention and restorability — **there is no backup endpoint on the hosting API**.
5. The MariaDB slow-query log's contents (and at `long_query_time = 3 s` it would be empty of this application's
   queries anyway).
6. Whether a Redis or Memcached server actually answers on this plan — see section 21's probe result.
7. What the `hbuilds/` tree (3,204 inodes, 75.9 MB) contains in detail, beyond being the Node build output.

**Owner decisions:**

1. **Verify the backups in hPanel** — frequency, retention, and that a restore is actually offered. Everything in
   section 17 hinges on it.
2. **Confirm the 24 application files and 5 committee photos** in the two blocked release trees, after copying them
   off the server, so ~18,700 inodes and ~310 MB can be recovered.
3. **Decide about the fifteen unrelated sites** on this account. They consume ~139,000 of the 214,751 inodes. Moving
   or retiring the dormant ones is the cheapest way to buy Provatferi headroom.
4. **The parked `provatferi.westernwatchbd.com` Node app** (still on Next 16.3.4, with known advisories) — retire or
   patch.
5. Whether the rate-limit fix in section 9 should be the next task.
