# Hosting hygiene + vulnerability audit — 2026-10-03

Record of the audit of the Hostinger plan behind provatferi.org (hPanel showed
Disk 9.49 GB / 50 GB, Inodes 403.74K / 600K, Web Apps 3/5, Vulnerabilities 7).
Everything below was **measured on the server** with `deploy/remote/lib/disk-audit.php`
(a read-only PHP walk run through cron — this host has no SSH and blocks
`exec`/`shell_exec`), not estimated. "Disk" is allocated blocks, which is what a
quota charges and what hPanel reports (±2%); "inodes" is files + directories +
symlinks.

## 1. Whole plan or just provatferi.org?

**Whole plan.** One shared plan hosts 20 websites. provatferi.org was the largest
single tenant — **66% of the inodes, 41% of the disk** — but not all of it:

| Tree | Inodes before | Disk before (MB) | Inodes after | Disk after (MB) |
|---|---:|---:|---:|---:|
| domains/provatferi.org | 273,799 | 4,078 | 52,693 | 1,197 |
| domains/westernwatchbd.com | 52,561 | 851 | 52,561 | 851 |
| domains/nexhomebd.com | 40,863 | 3,064 | 41,196 | 3,117 |
| domains/provatferi.westernwatchbd.com | 26,072 | 797 | 26,081 | 797 |
| domains/waymorebd.com | 15,758 | 430 | 15,762 | 430 |
| home dot-dirs (`.npm .composer .wp-cli .cache .cagefs …`) | 3,820 | 675 | 3,902 | 787 |
| **Total** | **412,873** | **9,895.1** | **192,195** | **7,178.9** |

(apparent size, i.e. sum of file lengths: 7,364.6 MB → 6,003.0 MB.) Only
provatferi.org's tree was cleaned; nothing under the westernwatchbd.com,
nexhomebd.com or waymorebd.com sites was touched (the small increases there, e.g.
nexhomebd.com +333 inodes / +53 MB, are new files on those live sites; the home
dot-dir growth is the npm cache, +112 MB from one Next.js version bump).

"Web Apps 3/5" = three Node.js sites: provatferi.org, sahittopata.provatferi.org and
provatferi.westernwatchbd.com.

## 2. What was eating the quota

Every ERP `switch` renames the whole live Laravel tree — `vendor/` included, ~9,000
files, ~175 MB — to `laravel-admin-releases/_previous-<ts>`, and **nothing ever
removed one**: 29 `_previous-*` plus 1 `_rolled-back-*` held **238,540 inodes (57.8%
of the account) and 3.2 GB**. The existing `cleanup` action could not be trusted to
fix it (it ranked `_rolled-back-*` above `_previous-*`, so one rolled-back copy made
it delete every real rollback target, and it sorted commit-sha release ids as
timestamps), and it had never been run. At the cadence of 2–3 deploys a day the plan
would have run out of inodes within weeks.

Other findings (none urgent):

| Item | Size | Classification |
|---|---|---|
| `.trash` ×2 under the ERP (Hostinger's file-API "delete" bin — still counts against the quota) incl. old diagnostic scripts and two plaintext-credential files from September | 162 inodes, 4.2 MB | **deleted** |
| `_release_staging/<id>/manifest.json` ×19 (left behind by every `stage`) | 38 inodes | **deleted**; `stage` now cleans its own |
| 2 failed, never-switched release dirs from 2026-09-10 | 666 inodes, 7 MB | **deleted** |
| `.npm/_cacache` | 531 MB, 2.4K inodes — grows ~110 MB per Next.js version | KEEP, auto-cleared above 1 GiB |
| `.composer/cache`, `.wp-cli/cache`, `.cache` | 54 MB, 159 MB, 33 MB | KEEP (regenerable, bounded) |
| `provatferi.westernwatchbd.com/hbuilds/source/repository` (a full GitHub clone with node_modules, rebuilt on every push; all ~119 builds fail; the site is behind a 301) | 24.3K inodes, 722 MB | **UNKNOWN — DO NOT TOUCH** (owner decision) |
| `nexhomebd.com/wp-content/updraft` + `wpvividbackups` (backup zips, inside the web root) | 1.49 GB, 32 files | **UNKNOWN — DO NOT TOUCH** (owner decision; also worth checking they are not downloadable) |
| archive.provatferi.org: 3 inactive Hostinger plugins | 5.4K inodes, 107 MB | KEEP (owner decision) |
| cms.provatferi.org: 2 inactive default themes | 182 inodes, 6.6 MB | KEEP (owner decision) |

Neither WordPress site (cms, archive — WordPress 7.1.2) has a backup plugin, cache
directory, debug/error log, PHP in `uploads/`, or stray file; both report 0 known
vulnerabilities (Hostinger's plugin feed). Pending updates are all Hostinger's own
plugins.

Laravel runtime: sessions, cache and queue are in the **database** (`SESSION_DRIVER`,
`CACHE_STORE`, `QUEUE_CONNECTION` = `database`), so they cost no inodes: live
`storage/framework/{cache,sessions}` hold 3 files, `views` 119, `bootstrap/cache` 4.
`LOG_STACK=single`, `LOG_LEVEL=error`: one small `laravel.log` per release (the live
one does not exist yet — no errors since the last deploy). Safer alternative if logs
ever matter: `LOG_STACK=daily` + `LOG_DAILY_DAYS=14`; not changed.

## 3. Data found during the cleanup

Three recruitment applications (ids 10, 12, 15 — created 2026-09-20/23) had their CV
and photo **only inside old release copies**: those uploads predate the 2026-09-24 fix
that carries uploads forward at `stage`, so the admin had been showing broken
attachments for them. The housekeeping tool refused to delete those copies; the six
files were restored to the live uploads disk (hash-verified, no overwrite) and confirmed
in real Chrome on the production admin (photo decodes, CV serves as application/pdf).
Eleven further files in three copies had no database row (QA fixtures and a test
submission); they are preserved, hash-verified, under
`laravel-admin-releases/_salvaged-uploads/` for the owner to keep or purge.

## 4. Cleanup result (all measured by the tool itself)

| | Inodes | Disk (MB) |
|---|---:|---:|
| Audit start (2026-10-02 19:25 UTC) | 412,873 | 9,895.1 |
| Immediately before cleanup (2026-10-03 13:39) | 413,260 | 10,052.6 |
| After retention + staging + trash | 230,836 | 7,639.5 |
| After salvaging and removing the five copies that held unique uploads | **192,141** | **7,170.9** |
| Independent full re-audit (14:24) | **192,195** | **7,178.9** |
| Plan capacity | 32.0% of 600K (was 68.8%) | 14.0% of 50 GB (was 19.6%) |

Removed: 27 `_previous-*`, 1 `_rolled-back-*`, 2 failed release dirs, 19 staging dirs, both
`.trash` bins — 221,178 inodes and 3.0 GB (2.8 GiB). Kept: the live app, the rollback target
`_previous-20261002-115801` and one more (`_previous-20261002-100502`). Nine logs (six in
the first pass, three in the second) were gzipped to `_archived-logs/` first.

## 5. Top directories (subtree totals, ancestors included, depth ≤ 4)

**By inodes — before**

| Inodes | MB | Path |
|---:|---:|---|
| 273,799 | 4,078 | domains/provatferi.org |
| 239,858 | 3,252 | domains/provatferi.org/laravel-admin-releases |
| 52,561 | 851 | domains/westernwatchbd.com |
| 40,863 | 3,064 | domains/nexhomebd.com |
| 37,106 | 2,950 | domains/nexhomebd.com/public_html/wp-content |
| 26,072 | 797 | domains/provatferi.westernwatchbd.com |
| 24,344 | 722 | domains/provatferi.westernwatchbd.com/hbuilds/source |
| 20,798 | 320 | domains/westernwatchbd.com/public_html/pos |
| 19,995 | 477 | domains/provatferi.org/public_html |
| 15,758 | 430 | domains/waymorebd.com |
| 11,998 | 316 | domains/waymorebd.com/public_html/wp-content |
| 11,161 | 246 | domains/westernwatchbd.com/public_html/wp-content |
| 10,100 | 244 | domains/provatferi.org/public_html/cms |
| 9,752 | 230 | domains/provatferi.org/public_html/archive |
| 9,130 | 180 | domains/provatferi.org/laravel-admin (the live app) |
| 9,118 | 179 | laravel-admin-releases/_previous-20261002-081201 |
| 9,118 | 91 | domains/westernwatchbd.com/public_html/fileserver |
| 9,115 | 179 | laravel-admin-releases/_previous-20260930-193702 |
| 9,114 | 179 | laravel-admin-releases/_previous-20261002-100502 |
| 9,114 | 179 | laravel-admin-releases/_previous-20261002-115801 |
| 9,101 | 179 | laravel-admin-releases/_previous-20260930-133601 |
| 8,525 | 172 | domains/provatferi.org/laravel-admin/vendor |
| 8,368 | 172 | laravel-admin-releases/_previous-20260924-074402 |
| 8,368 | 172 | laravel-admin-releases/_previous-20260924-193202 |
| 8,365 | 172 | laravel-admin-releases/_previous-20260926-180502 |
| 8,365 | 172 | laravel-admin-releases/_previous-20260925-185201 |
| 8,350 | 172 | laravel-admin-releases/_previous-20260924-205402 |
| 7,606 | 75 | laravel-admin-releases/_previous-20260923-200402 |
| 7,604 | 74 | laravel-admin-releases/_previous-20260923-093602 |
| 7,598 | 77 | laravel-admin-releases/_previous-20260921-081302 |

(The remaining ~20 `_previous-*` copies are 7.1–7.6K inodes each. Counting directories
by the files sitting *directly* in them instead, the before top-30 was dominated by
25 copies of one folder, `vendor/nesbot/carbon/src/Carbon/Lang` — 824 files each, one
per retained release.)

**By disk — before**

| MB | Inodes | Path |
|---:|---:|---|
| 4,078 | 273,799 | domains/provatferi.org |
| 3,252 | 239,858 | domains/provatferi.org/laravel-admin-releases |
| 3,064 | 40,863 | domains/nexhomebd.com |
| 2,950 | 37,106 | domains/nexhomebd.com/public_html/wp-content |
| 1,230 | 19 | domains/nexhomebd.com/public_html/wp-content/updraft (backup zips) |
| 851 | 52,561 | domains/westernwatchbd.com |
| 843 | 5,933 | domains/nexhomebd.com/public_html/wp-content/uploads |
| 797 | 26,072 | domains/provatferi.westernwatchbd.com |
| 722 | 24,344 | domains/provatferi.westernwatchbd.com/hbuilds/source |
| 585 | 30,399 | domains/nexhomebd.com/public_html/wp-content/plugins |
| 477 | 19,995 | domains/provatferi.org/public_html |
| 430 | 15,758 | domains/waymorebd.com |
| 419 | 2,348 | .npm/_cacache |
| 320 | 20,798 | domains/westernwatchbd.com/public_html/pos |
| 316 | 11,998 | domains/waymorebd.com/public_html/wp-content |
| 260 | 13 | domains/nexhomebd.com/public_html/wp-content/wpvividbackups |
| 246 | 11,161 | domains/westernwatchbd.com/public_html/wp-content |
| 244 | 10,100 | domains/provatferi.org/public_html/cms |
| 230 | 9,752 | domains/provatferi.org/public_html/archive |
| 180 | 9,130 | domains/provatferi.org/laravel-admin |
| 179 | 9,118 | laravel-admin-releases/_previous-20261002-081201 |
| 179 | 9,115 | laravel-admin-releases/_previous-20260930-193702 |
| 179 | 9,101 | laravel-admin-releases/_previous-20260930-133601 |
| 179 | 9,114 | laravel-admin-releases/_previous-20261002-100502 |
| 179 | 9,114 | laravel-admin-releases/_previous-20261002-115801 |
| 172 | 8,368 | laravel-admin-releases/_previous-20260924-074402 |
| 172 | 8,368 | laravel-admin-releases/_previous-20260924-193202 |
| 172 | 8,365 | laravel-admin-releases/_previous-20260926-180502 |
| 172 | 8,365 | laravel-admin-releases/_previous-20260925-185201 |
| 159 | 28 | .wp-cli/cache |

**By inodes / disk — after** (top 12 of each; the rest are third-party WordPress trees)

| Inodes | MB | Path |
|---:|---:|---|
| 52,693 | 1,197 | domains/provatferi.org |
| 52,561 | 851 | domains/westernwatchbd.com |
| 41,196 | 3,117 | domains/nexhomebd.com |
| 26,081 | 797 | domains/provatferi.westernwatchbd.com |
| 24,352 | 722 | domains/provatferi.westernwatchbd.com/hbuilds/source |
| 20,798 | 320 | domains/westernwatchbd.com/public_html/pos |
| 19,916 | 477 | domains/provatferi.org/public_html |
| 18,933 | 371 | domains/provatferi.org/laravel-admin-releases (the 2 kept copies + salvage + history) |
| 15,762 | 430 | domains/waymorebd.com |
| 10,100 | 244 | domains/provatferi.org/public_html/cms |
| 9,752 | 230 | domains/provatferi.org/public_html/archive |
| 9,136 | 183 | domains/provatferi.org/laravel-admin |

## 5b. Server-side tracking on this plan — assessment only (nothing implemented)

Measured on the host: PHP 8.3 (`exec`/`shell_exec`/`popen`/`symlink` disabled, `proc_open`
allowed), no SSH, no persistent worker processes (cron's finest granularity is one minute),
a Redis PHP extension but no Redis server evidence, MySQL on the same box, and a **shared
server at load average ≈ 63 on 64 cores** (503 GB RAM, 159 GB free) when sampled — i.e.
other tenants already keep it near saturation. Account-level CloudLinux limits (CPU %,
entry processes, IO) are not visible through the API and must be read in hPanel.
Everything quantitative below is an estimate from stated assumptions, not a measurement.

| Question | Estimate |
|---|---|
| Requests/day | 5K–50K pageviews × 3–6 events (page_view, scroll, click, lead…) = **15K–300K events/day**, 0.2–3.5/s average, ~10× peaks (up to ~35/s) |
| Outbound fan-out | Meta CAPI + GA4 Measurement Protocol + TikTok Events API = 3 HTTPS calls/event → 45K–900K/day, 80–300 ms each. Done synchronously that pins a worker 0.3–1 s per event: 1–3 busy workers on average, 10–35 at peak — past what a shared plan allows. |
| Required shape | request → validate → **enqueue and answer 204 in a few ms** → a one-minute cron drains the queue in batches → platforms → delete on success. Latency up to ~60 s is acceptable (Meta/GA4 accept delayed events with the original timestamp and dedupe on `event_id`). |
| Database | queue rows ~0.4–1 KB → 6–300 MB/day if kept; with delete-on-delivery and a 48 h cap on pending/failed (7 days dead-letter) the steady state is tens of MB up to a few hundred. The ERP's whole schema is 3.3 MB today, so this would dwarf it. |
| Inodes | **≈ 0** as long as events are rows, never files (an InnoDB table is a fixed handful of files). Access logs: 12–75 MB/day, rotated; confirm Hostinger's rotation before relying on it. A file-per-event design would burn the 600K quota in days — it is ruled out. |
| Cache / sessions | the tracking route must be stateless: no session, no cache write per event. |
| CPU / RAM | Laravel bootstrap ≈ 20–50 ms CPU/request even with route+config cache and OPcache (a minimal Node or plain-PHP endpoint ≈ 2–5 ms). 300K events × 30 ms ≈ 2.5 CPU-hours/day (~0.1 core average) — fine on average, but peaks and the noisy neighbours are the risk. Memory per request is small (<30 MB). |
| Blast radius | the endpoint would run in the **same account, so under the same CloudLinux limits, as the ERP and the public site**: a bot-driven surge can throttle the admin and the public pages. |

**Verdict — not suitable as the primary tagging server.** It could carry a small,
queue-based, fail-open collector (≲ 10K events/day) as a stopgap, but Stape.io, a Cloud
Run service (scale-to-zero, cents per day at this volume) or a small VPS remains the
safer home — it isolates the tracking load from the ERP and gives real concurrency and
retries. If it is moved in-house later, the non-negotiables are: asynchronous
enqueue-and-drain, no per-event files, delete-on-delivery with a short retention cap,
SHA-256 hashing of email/phone before anything is stored or sent, platform tokens only
in server env (never committed or logged), consent gating, and rate limiting plus an
origin/HMAC check so the open endpoint cannot be used to burn the quota.

## 6. Retention policy now in force

See `deploy/README.md` → "Retention, housekeeping and the capacity guard". In short:
live app + rollback target + newest 2 retired copies; 30 release-id dirs; fail closed
on a bad `CURRENT_RELEASE.json`; never delete a copy holding a `storage/app` file the
live app lacks (salvage first); logs archived first; runs by itself at the end of
`smoke-test-live`; capacity guard in `pipeline` (≥70% warn, ≥80% inodes / ≥90% disk
refuse non-essential deploys); 24 tests.

## 7. Vulnerabilities

The seven findings Hostinger reported (identical for provatferi.org, sahittopata and
provatferi.westernwatchbd.com — `hosting_nodejs_list-vulnerabilities`):

| # | Package | Installed | Advisory | Severity | Scope | Fixed in | Exploitable here? |
|---|---|---|---|---|---|---|---|
| 1 | next | 16.3.4 | GHSA-vcvr-r3jv-pc5j / CVE-2026-94545 — RCE in `next/og` `ImageResponse` | **Critical 9.5** | production, direct | **16.3.6** | **No** — neither app imports `next/og` or defines a metadata-image route |
| 2 | brace-expansion | 1.1.18 | GHSA-qhr7-859c-m2p7 / CVE-2026-102278 — nested-brace recursion (CWE-400) | High 7.5 | dev (eslint→minimatch) | 1.1.20 | No — never loaded by the server |
| 3 | brace-expansion | 5.0.9 | same | High 7.5 | dev (typescript-eslint→minimatch) | 5.0.11 | No |
| 4 | brace-expansion | 1.1.18 | GHSA-6j4f-fj2g-mc7p / CVE-2026-102276 — parseCommaParts recursion | High 7.5 | dev | 1.1.19 | No |
| 5 | brace-expansion | 5.0.9 | same | High 7.5 | dev | 5.0.10 | No |
| 6 | brace-expansion | 1.1.18 | GHSA-q2hr-2g5m-vwhr / CVE-2026-102277 — quadratic `{a},b}` expansion | Moderate 5.3 | dev | 1.1.21 | No |
| 7 | brace-expansion | 5.0.9 | same | Moderate 5.3 | dev | 5.0.12 | No |

**Fix:** `next` and `eslint-config-next` 16.3.4 → 16.3.6 (exact pins, a patch release) and
`npm update brace-expansion` (1.1.18 → 1.1.21, 5.0.9 → 5.0.12, inside the ranges
minimatch already declares). No `--force`, no override, no major bump. The lockfile
diff is exactly 14 package entries, none added or removed. `npm audit` 0, `npm audit
--omit=dev` 0, institutional: 126 tests, webpack build, real-Chrome QA on production
(request-storm 6/6, nav 13/13, carousel 14/14 ×2); `frontend/`: lint, both bundlers.

**Result on Hostinger:** provatferi.org `[]` (was 7); sahittopata `[]` (was 7, redeployed);
provatferi.westernwatchbd.com **still 7** — deliberately not redeployed (see below).

**New since the first scan:** GHSA-vfj7-8cjw-p6xm / CVE-2026-93687 — `braces` ≤ 3.0.3,
High 8.7 (CWE-674 uncontrolled recursion), published 2026-09-18, **no patched version
exists** (3.0.3 is the latest release). Five `npm audit` entries, one root cause, reached
only through `eslint-config-next → @next/eslint-plugin-next → fast-glob → micromatch →
braces` — dev tooling that is not in the standalone production output. Not exploitable here
(it needs attacker-controlled glob patterns). It cannot be fixed by an upgrade;
`npm audit`'s suggested fix is to downgrade `eslint-config-next` to 14.2.35, a breaking
change, and was not applied. Options: wait for a `braces` release (recommended), or drop
the Next ESLint plugin (loses the Next-specific lint rules).
