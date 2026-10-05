# admin-erp production deployment

Replaces hand-picking individual changed files for production. That practice
is what caused the 2026-09-09 outage: commit `59530fa` changed
`AppServiceProvider.php` and `config/mail.php` together — they were
logically dependent, since the provider reads a key the config file defines
— but only `AppServiceProvider.php` made it into that deploy's file list.
`config('mail.reply_to.support')` resolved to `null` in production,
`->replyTo(null)` doesn't fail until Symfony's `Address` constructor turns
it into a real message, and `POST /forgot-password` returned a 500.

Every deploy now ships one exact `git` commit as a whole, and one automated
check — the config contract check — exists specifically to catch this
failure class before anything reaches production.

## Why this is built the way it is

Hostinger shared hosting here has no SSH and no interactive shell.
`disable_functions` in `php.ini` blocks `exec`, `shell_exec`, `system`,
`passthru`, `popen`, `symlink`, and `link` — confirmed by direct probe, not
assumed. Two things are confirmed to work and everything here is built on
them:

- **`proc_open()`** genuinely spawns processes. Composer's own `Process`
  component uses `proc_open` directly rather than the disabled shell
  wrappers, which is why `composer install` can run here at all despite
  `exec` being off.
- **`rename()`** on a directory is a real, atomic filesystem operation
  (measured ~0.4ms for a directory swap) — this is what makes the release
  switch atomic without symlinks, which are also confirmed disabled.

Cron-as-shell (creating a cron job that runs once and is deleted right
after) plus the file browser's TUS upload API are the only two execution
primitives available, exactly as used throughout this project's other
deploys.

## Split-layout architecture (preserved, not changed)

```
PRIVATE (never web-accessible):
  .../provatferi.org/laravel-admin/            <- the live app
  .../provatferi.org/laravel-admin-releases/   <- staged/previous releases, tooling

PUBLIC (the vhost docroot — a fixed path, cannot be renamed):
  .../provatferi.org/public_html/admin/
```

`public_html/admin/index.php` defines `LARAVEL_PUBLIC_PATH_OVERRIDE` and
requires `../../laravel-admin/bootstrap/app.php` — this file is the one
thing that must stay correct for the split layout to work at all. The
build/deploy pipeline **never auto-overwrites it**: `build-release.sh`
records its SHA-256 in the manifest and reports it, but a real change to it
needs a deliberate, separate step — see "index.php" below.

## One-time setup (already done once; re-run only if `laravel-admin-releases/_tooling/` is ever lost)

1. Get a Hostinger upload session for `admin.provatferi.org`
   (`hosting_generateUploadURLV1`), export `HOSTINGER_UPLOAD_URL`,
   `HOSTINGER_AUTH_KEY`, `HOSTINGER_AUTH_REST`.
2. Upload these files via TUS to `public_html/admin/`:
   - `deploy/remote/release-manager.php` → `release-manager.php`
   - every file in `deploy/remote/lib/` → `lib/<same name>`:
     `uploads-persistence.php` (2026-09-25), `public-uploads.php`
     (2026-10-02), `housekeeping.php` and `disk-audit.php` (2026-10-03),
     `uploads-sync.php` (2026-10-05, the stage→switch upload delta-sync).
     `release-manager.php` `require_once`s all of them unconditionally, on
     every action including `install` itself, so each must exist here before
     `install` ever runs or the script fatals before the switch statement
     is reached
   - `deploy/config-contract.php` → `_bootstrap_config_contract.php`
   - a real `composer.phar` (download from getcomposer.org) → `_bootstrap_composer.phar`
3. Run once via cron (absolute paths, the proven pattern):
   ```
   /usr/bin/php /home/u951246149/domains/provatferi.org/public_html/admin/release-manager.php install
   ```
4. Delete the cron job immediately after (one-shot). Verify
   `laravel-admin-releases/_tooling/release-manager.php`,
   `laravel-admin-releases/_tooling/lib/uploads-persistence.php`, and
   `composer.phar` exist and the public copies are gone.

### Updating an already-installed release-manager.php

The same upload (`release-manager.php` as `_rm_update.php`, plus every file in
`deploy/remote/lib/` as `lib/<name>`, all under `public_html/admin/`) followed
by the same `install` action works for updating an existing installation too —
`install` relocates them into `_tooling/` (and `_tooling/lib/`) whether or not
they already exist there, and its output lists a `relocated_lib_*` key per
library: all must be `true`. Always re-run `install` after editing any of
these files in git; the server only ever runs whatever was last relocated into
`_tooling/`, never the git copy directly.

## Deploying a commit

```
# 1. Build — every check in deploy/build-release.sh either passes or the
#    script stops. Produces deploy/releases/<sha>-<timestamp>/ locally.
admin-erp/deploy/build-release.sh <commit-sha>

# 2. Upload the built release to production's staging area.
HOSTINGER_UPLOAD_URL=... HOSTINGER_AUTH_KEY=... HOSTINGER_AUTH_REST=... \
  node admin-erp/deploy/upload-release.mjs deploy/releases/<sha>-<timestamp>

# 3. Run these via cron, IN ORDER — each is a separate one-shot cron entry
#    created then deleted immediately (the pattern used throughout this
#    project). Stop at the first failure; do not proceed to switch.
php release-manager.php stage <releaseId>
php release-manager.php build <releaseId>
php release-manager.php contract-check <releaseId>
php release-manager.php migrate-check <releaseId>
php release-manager.php smoke-test-isolated <releaseId>

#    (`php release-manager.php pipeline <releaseId>` runs stage → … →
#    smoke-test-isolated in one cron, stopping at the first failure, and first
#    checks account capacity — see "Retention, housekeeping and the capacity
#    guard". Append `essential` to deploy anyway at >=80% inodes.)

# 4. Only if every step above reported ok:true —
php release-manager.php switch <releaseId>             # carries uploads made since `stage` (see "Uploads during a deploy")
#    php release-manager.php switch <releaseId> keep-both   # only after a refusal over an upload collision
php release-manager.php smoke-test-live    # also the third uploads sweep; on success it runs housekeeping apply

# 5. Update the local pointer (see "Deployment manifest" below) and commit it.
```

If a commit's migrations need to actually run, review `migrate-check`'s
`pretend_output` first, then as an explicit, separate, differently-named
action:

```
php release-manager.php migrate-apply <releaseId> --i-have-reviewed-the-pretend-output
```

`migrate:fresh` and `db:wipe` are not implemented anywhere in this tooling
and must never be run against production by hand either.

## Retention, housekeeping and the capacity guard

Added 2026-10-03 after a hosting audit. **Why:** every `switch` renames the whole
live Laravel tree — `vendor/` included, ~9,000 files, ~175 MB — to
`laravel-admin-releases/_previous-<ts>`, and nothing ever removed one. The audit
found 29 of them plus a `_rolled-back-*`: 238,540 of the account's 412,873
inodes (57.8%) and 3.2 GB, on a plan capped at 600,000 inodes — a full extra
copy per deploy, so an outage within weeks. The original `cleanup` action could
not be trusted to fix it: it ranked `_rolled-back-*` ahead of `_previous-*`, so a
single rolled-back copy made it delete every real rollback target.

**Policy** (`lib/housekeeping.php`, `hkPolicy()`; pinned by `tests/Feature/Deploy/HousekeepingTest.php`):

| Thing | Kept | Removed when |
|---|---|---|
| `_previous-<ts>` (a retired live app) | the newest 2, **plus** the one `CURRENT_RELEASE.json` names as `previous_path` (the rollback target) | older than that |
| `_rolled-back-<ts>` (a failed release, kept for forensics) | younger than 3 days | older |
| `<releaseId>/` (status.json + public_assets, ~20 inodes: the deploy history) | newest 30, the current release, anything under a day old | older |
| `_release_staging/<releaseId>/` in the docroot | in-flight (touched within the hour); unstaged and under a day old | its release staged OK (the tars are already unlinked), or a day old |
| `.trash` (Hostinger's file-API "delete" bin, which still counts against the quota) | entries under a week old | older |
| `_archived-logs/` | 90 days | older |
| `~/.npm/_cacache`, `~/.composer/cache`, `~/.wp-cli/cache` | while under 1 GiB / 512 MiB / 512 MiB | over the limit (all regenerable) |

**What it will never do:** touch the live app, `CURRENT_RELEASE.json`'s rollback
target, `_tooling/`, or a directory whose name does not parse as
`_previous-/_rolled-back-YYYYMMDD-HHMMSS`; delete anything if `CURRENT_RELEASE.json`
is missing, unreadable, or names a rollback target that is not on disk (it fails
closed — after a manual `rollback` that record is stale until the next `switch`
rewrites it); delete outside the directory it was pointed at or follow a symlink;
run twice at once (`laravel-admin-releases/.housekeeping.lock`). A retired copy that holds a
`storage/app` file the live app does not have (matched by path+size, then by
content hash) is **blocked, not deleted** — that file would be unrecoverable. Run
`salvage <name>` (copies it, hash-verified, to `_salvaged-uploads/<name>/`) and then
`housekeeping apply <name>`, or `housekeeping apply salvage` to do both for every
blocked copy. A copy's logs are gzipped to `_archived-logs/` before it goes.

**Commands** (one per cron; all dry-run unless `apply`):

```
php release-manager.php housekeeping                 # dry run: exact plan, expected inodes/bytes recovered, before/after usage
php release-manager.php housekeeping apply           # carry it out (time-boxed to 240 s; re-run if `incomplete`)
php release-manager.php housekeeping apply salvage   # also preserve-then-delete copies holding unique uploads
php release-manager.php salvage _previous-<ts>[,..]  # preserve a copy's unique uploads only
php release-manager.php usage                        # account-wide inodes + disk against the plan limits
```

It also runs by itself: `smoke-test-live` calls `housekeeping apply` once the live
site has passed its checks (never before — a release that needs rolling back still
has its rollback target) and reports under `housekeeping`; it cannot flip the
deploy's `ok`.

**Capacity guard.** Hostinger's limits cannot be read from the host, so they are
constants (600,000 inodes, 50 GB — what hPanel shows). `pipeline` measures the
whole account first (~35 s): **>=70% inodes or disk warns; >=80% inodes (or >=90%
disk) refuses a non-essential deploy** (`pipeline <releaseId> essential`
overrides). Every housekeeping run appends to `_usage-history.jsonl`, flags a jump
of more than 12,000 inodes since the previous sample, and writes
`CAPACITY_ALERT.json` (shown by `status`) whenever status is not `ok`.

**Audit tool.** `lib/disk-audit.php` is a read-only, CLI-only walk used to
produce the 2026-10-03 numbers: `php disk-audit.php <root> [report-file]` prints
total inodes/disk, a tree, the top 30 directories by inodes and by disk, and
per-category totals (`node_modules`, `.next`, `vendor`, caches, backups, release
copies, tarballs, logs, …). A web request gets a bare 404; delete any docroot copy
as soon as it has run.

## What's in the artifact, and what deliberately isn't

**Included** (from `git archive <sha>`): `app/`, `bootstrap/` (source
files only — `bootstrap/cache/*.php` is excluded, always regenerated fresh
on the server, since those are compiled artifacts tied to absolute vendor
paths), `config/`, `database/`, `resources/`, `routes/`, `composer.json`,
`composer.lock`, `artisan`, and the built frontend (`public/build/`,
`public/brand/`, `favicon.ico`, `robots.txt`) as a **separate** artifact
from the private tree.

**Excluded, always**: `.env`, `.env.*`, anything matching `*credentials*`,
`tests/`, `node_modules/`, `storage/logs/`, `storage/framework/{cache,
sessions, views, testing}`, `vendor/` (rebuilt server-side from the exact
committed `composer.lock`, not shipped), and `deploy/` itself.

**`index.php`**: reported (SHA-256 in the manifest) but never bundled for
automatic application. It is the split-layout front controller — the
single highest-blast-radius file in this whole system — and a change to it
gets applied as its own deliberate, manually-reviewed step, never as a side
effect of a routine content/feature deploy.

## Vendor strategy

`composer install --no-dev --optimize-autoloader --no-interaction` runs
**on the server**, via `proc_open`, against the exact `composer.json` /
`composer.lock` from the shipped commit — this is the "Preferred" approach
from the incident-prevention brief, and it's confirmed to actually work
here, not assumed. `composer check-platform-reqs` runs immediately after;
`build` fails the release if either step fails or reports an unmet
requirement. `composer.json` pins `"platform": {"php": "8.3.0"}`; production
runs PHP 8.3.33, which satisfies the app's `^8.2` requirement.

## Config contract check

`deploy/config-contract.php <app-path>` statically finds every literal
`config('a.b.c')` / `config("a.b.c")` call site under `app/`, `routes/`,
`resources/views/`, `bootstrap/`, then boots the app **from that same
path** and walks each discovered dotted path with `array_key_exists()` at
every segment — not `config('a.b.c') !== null`, since a key that's missing
entirely and a key that exists and is null are indistinguishable through
the `config()` helper alone, and the outage was specifically the former.
A dynamic call site (the argument isn't a single plain string literal —
e.g. this project's own
`config('auth.passwords.'.config('auth.defaults.passwords').'.expire')`)
is reported separately for manual review and never fails the build on its
own.

Verified to actually catch the incident: reproducing the exact broken
`config/mail.php` (the `reply_to` block removed) locally and running this
script against it reports `mail.reply_to.support` as `missing`, pinpoints
`AppServiceProvider.php:78` and the mail Blade template that also
references it, and exits 1. Restoring the real file passes again. This is
not a theoretical claim — it was run both ways.

## Cache strategy

After `switch`, on the now-live tree: `config:clear`, `view:clear`,
`config:cache`, `view:cache` always run, via `Artisan::call()` (not `exec`,
which is disabled) using the proven pattern from the 2026-09-09 fix.
`route:cache` runs **only** if `routes/` actually changed between the
previous and new release (compared by hashing every file under `routes/`)
— otherwise it's explicitly skipped and the skip is recorded in the switch
result, satisfying "only rebuild route cache if routes changed."

**Residual cache risk, stated plainly**: `opcache_reset()` exists on this
host, but calling it from the CLI process running this deploy script does
**not** reach the separate PHP-FPM worker pool serving live web requests —
CLI and FPM are different SAPI processes with independent OPcache memory
here. The actual bound on staleness is `opcache.revalidate_freq` (PHP
default: 2 seconds) — FPM workers re-stat a file and pick up a change
within that window on their own. This pipeline cannot force it lower than
that without an FPM restart capability this hosting plan doesn't expose.
In practice this means: for up to ~2 seconds after `switch`, an in-flight
FPM worker could still be running bytecode compiled from the pre-swap
files. This is a real, bounded, and — compared to the alternative of no
atomic swap at all — small risk, and it's the one most worth watching if
"it worked when I checked but a request right after `switch` looked wrong"
ever comes up.

## Atomicity — what's actually atomic and what isn't

- **The application code swap IS atomic**: `rename(laravel-admin/,
  laravel-admin-releases/_previous-<ts>/)` then
  `rename(laravel-admin-releases/<releaseId>/app/, laravel-admin/)`. Both
  are single filesystem rename operations on the same volume — measured at
  under half a millisecond each. There is no window where `laravel-admin/`
  exists as a partial mix of old and new files; it is either the fully-old
  tree or the fully-new tree, nothing between.
- **The public docroot asset sync is NOT atomic** — `public_html/admin/` is
  the vhost's actual docroot, a fixed path the web server config points at
  permanently, so it can never itself be swapped by rename; new files are
  copied into it in place. This is deliberately the one place this pipeline
  accepts a non-atomic step, and it's low-risk in practice because Vite
  content-hashes built asset filenames — old and new versions coexist under
  different names during the sync window, so a page that loaded
  old-hashed asset references keeps finding them; nothing goes missing
  mid-sync. `index.php`, `.htaccess`, and `favicon.ico` are the only
  un-hashed files touched here, and none of them change on a routine deploy
  (see "index.php" above for why `index.php` specifically is never
  auto-applied at all).
- Symlink-based atomic switching was considered and explicitly **not**
  used — `symlink()`/`link()` are confirmed disabled in `php.ini` on this
  host, not merely untested.

## Uploads during a deploy (the stage → switch window)

**The race (found 2026-10-04, fixed 2026-10-05).** `stage` used to copy the live uploads tree
(`laravel-admin/storage/app/private/uploads`) into the new release exactly once, when it ran, and
checked only a file *count*. The live application keeps accepting uploads until `switch`
(pipeline + the operator's review + a separate switch cron ≈ 10 minutes), and it writes them into the
**old** tree. `switch` then renames `laravel-admin/` to `_previous-<ts>/` and the staged tree into
its place and carried nothing over: every file created in between stayed only in `_previous-<ts>/`.
The database row (shared MySQL) survived, the file did not — a broken admin photo, CV link or PDF
image (`photo=GONE`). A request that was mid-write at the instant of the rename strands the same way
(an open file handle follows its inode, which is now under the retired path), and `rollback` had the
mirror-image hazard. Public uploads were never affected: that disk lives in the vhost docroot
(`public_html/admin/storage`), outside every release directory, and the docroot sync is additive.

**What happens now** (`lib/uploads-sync.php`; the application code swap is still the same two atomic renames):

1. **`stage` takes a verified snapshot.** Every file is copied with an atomic, hash-verified write (a hidden
   `.usync-*.tmp` in the destination directory, verified by size and SHA-256, given the source's mtime and mode,
   then renamed into place), and what was copied is recorded in `laravel-admin-releases/<releaseId>/uploads-base.json`.
2. **`switch` reconciles before it renames.** A three-way merge of *snapshot / live / staged*, repeated until a
   whole pass finds nothing left to do (up to 8 passes or 30 s):

   | path is…                                                   | action |
   |------------------------------------------------------------|--------|
   | in live only (created since the snapshot)                  | **copy** into the staged tree |
   | in both, staged still equals the snapshot, live changed    | **update** (a replaced photo wins over the stale snapshot) |
   | in both, live still equals the snapshot, staged changed    | keep the staged file — never overwritten |
   | in both, **neither** equals the snapshot, bytes differ     | **collision** — see below |
   | in staged only, still equals the snapshot                  | **delete** (the app removed it after the snapshot) — only with proof, never in bulk |

   Deletions are withheld, not forced, if they look like an accident (the live tree is missing/empty, or more
   than 20 % of the snapshot, minimum 10): resurrecting a file is recoverable, deleting user data is not.
   Symlinks are never followed or copied; manifest paths are validated against traversal; a stat-based hash
   shortcut is used only for files untouched since the snapshot and older than it by 2 s.
3. **The rename.** If a request lands in the ~0.4 ms between the two renames and creates a stray
   `laravel-admin/` (Flysystem builds its root on demand) the second rename would fail and leave *no*
   application; the stray is set aside as `_stray-<ts>-<id>` and the rename retried. If the new tree cannot be put
   in place the old one is restored.
4. **After the rename, sweeps.** Whatever the retired tree (or a stray) took after the last pre-rename pass is
   copied into the live tree — additively, hash-verified, never resurrecting a file the new app removed and never
   deleting anything — immediately, again after a 10 s pause (`RM_SWEEP_DELAY`, for requests still running at the
   rename), and a third time in `smoke-test-live`. Each sweep proves "nothing left only in the retired tree";
   a leftover makes `switch` / `smoke-test-live` report `ok:false` and keeps `housekeeping` from running.
   `housekeeping` also still refuses to prune any retired tree that holds a file the live tree lacks.

`switch` reports `uploads_reconcile` (counts, converged, base), `uploads_sweep`, `uploads_notes` and the retired tree's
name in its JSON and in `<releaseDir>/status.json`; the full final file map is `<releaseDir>/uploads-final.json`.
`switch` also refuses (touching nothing) if the release is not a built, staged application — e.g. a second `switch`
of an already-switched release.

### Collisions: same path, different bytes

Neither side is ever silently overwritten.

- **Default — fail safely.** `switch` stops **before any rename**, lists each colliding path with both hashes
  (`uploads_reconcile.conflicts`), the live application is untouched and the release stays staged.
- **`switch <releaseId> keep-both`** — proceeds. The **staged** file keeps the path; the **live** version is preserved
  byte for byte at `laravel-admin-releases/_upload-conflicts/<releaseId>/files/<path>.source-<sha8>`, with a ledger at
  `…/conflicts.json` (path, both hashes and sizes, where it was preserved). Later sweeps honour that decision.
- **Recovering a preserved copy**: compare the two files, then copy the one you want over the other under
  `laravel-admin/storage/app/private/uploads/<path>` (the path is what the database row's `photo_path`/`cv_path`
  points at). Nothing in `_upload-conflicts/` is ever pruned automatically, and housekeeping counts its bytes as
  "not lost" when judging a retired tree.
- In practice a collision should not happen: every upload is written under a `Str::uuid()` name. It exists for
  corruption, a restored backup, or a hand edit.

### Recovering uploads an older release already stranded

```
php release-manager.php reconcile _previous-<ts>                 # dry run: lists what it would copy / refuse, changes nothing
php release-manager.php reconcile _previous-<ts> apply           # copies files the retired tree has and live lacks
php release-manager.php reconcile _previous-<ts> apply keep-both # also preserves (never overwrites) path collisions
```

Works on `_previous-*`, `_rolled-back-*` and `_stray-*`. Additive and hash-verified; it never deletes anything and never
touches the retired tree. With no snapshot to compare against it cannot know what the app deleted on purpose after
the switch, so a file removed since then comes back — read the dry run first.

### What is guaranteed, and what is not

- A file that existed when `stage` ran, or was created or changed before the final pre-rename pass, is in the new
  tree before it becomes live.
- A file written after that pass (a request in flight at the rename, ≤ a few hundred ms in practice) is carried by the
  sweeps within seconds; for that short while the new tree can lack it (a broken image, then healed). It is never lost:
  the retired tree is kept and housekeeping will not prune it while it holds a file live lacks.
- Not covered by the timed sweeps: a request still executing more than ten seconds after the rename is caught by the
  `smoke-test-live` sweep and, failing that, by `reconcile`.
- Tests: `tests/Feature/Deploy/ReleaseSwitchUploadsTest.php` (the real `release-manager.php` stage → switch in a sandbox:
  upload before stage, between stage and switch, immediately after switch, same filename, public, private),
  `ReleaseSwitchUploadsEdgeCasesTest.php` (rollback, `reconcile`, sweeps, a writer that never stops, the stray directory),
  `UploadsSyncTest.php` (the library). `deploy/qa/uploads-race-qa.php` is the production acceptance script.

### Proving it on production (acceptance, repeatable)

Upload `deploy/qa/uploads-race-qa.php` to the admin docroot as `_qa_race.php` (it answers 404 to anything but the CLI and
removes itself after a clean `cleanup`), then, one cron per step (`* * * * *` is safe for the idempotent ones: create,
read the output after ~70 s, delete):

1. `_qa_race.php put-once before-stage` — a disposable private upload (random bytes, through the app's own disk) and a
   public file, with the application directory's inode recorded.
2. Deploy as usual: upload the release, `pipeline <id>`.
3. **Between stage and switch:** `_qa_race.php put-once between-stage-and-switch`; submit one real volunteer application
   in a browser (`institutional/scripts/submit-qa.mjs --scenarios C`) and `_qa_race.php register-application`;
   optionally `_qa_race.php writer 150 200 1000` one minute before the switch cron (a writer that never stops).
4. `switch <id>` — read `uploads_reconcile` (what was carried before the rename) and `uploads_sweep` (what arrived after).
5. `_qa_race.php verify` — every private file in the live tree with the recorded SHA-256, readable through the app's own
   disk, mtime and mode kept; every public file answering HTTP 200 with the same bytes; the registered application's
   photo and CV intact; nothing stranded in the tree this deploy retired (older retired trees are reported, with whether a
   database row needs each file). Then `smoke-test-live` and, in Chrome, `institutional/scripts/admin-upload-check.mjs`
   (the application page's photo decodes, the photo/CV/PDF routes return the same bytes).
6. Optional recovery/collision drill: `_qa_race.php drill-setup`, then `reconcile <retired>` (dry run),
   `reconcile <retired> apply` (refused), `reconcile <retired> apply keep-both`, `_qa_race.php drill-verify`.
7. `_qa_race.php cleanup` (a one-shot cron) removes every file in the live tree, the docroot, every retired tree and
   `_upload-conflicts/`, the registered application rows, the state directory and itself, and proves nothing is left.

## Rollback

```
php release-manager.php rollback
```

Reads `laravel-admin-releases/CURRENT_RELEASE.json` (written by the last
successful `switch`) for the previous release's path, renames it back into
`laravel-admin/` (the failed release is kept, not deleted, at
`laravel-admin-releases/_rolled-back-<timestamp>/` for a post-mortem), and
rebuilds config/view caches on the restored tree. Same atomicity guarantee
as forward deploys, same direction in reverse. Uploads accepted since the
switch are carried back into the restored tree first (and swept after the
rename), exactly as `switch` carries them forward — see "Uploads during a
deploy"; a collision is preserved under `_upload-conflicts/rollback-<ts>/` and
reported rather than refusing the rollback.

**What rollback does not do**: undo a database migration. If a release
that included a forward migration needs to be rolled back, the code
rollback above is safe and instant; the migration itself must be assessed
separately; there is deliberately no automated `migrate:rollback` wired
into this tool — that decision needs a human looking at what the migration
actually did to data, not a script guessing it's safe.

## Deployment manifest

`deploy/build-release.sh` writes `manifest.json` into the release
directory; `release-manager.php` accumulates a matching `status.json` in
the server-side release directory as each stage completes. Together they
cover:

```
DEPLOYED_COMMIT     manifest.json:            commit
PREVIOUS_COMMIT      manifest.json:            previous_commit
ARTIFACT_SHA256      manifest.json:            artifacts.private_tar.sha256 / .public_assets_tar.sha256
MIGRATIONS           manifest.json:            migrations_in_release
                     status.json (migrate_check): pending_migrations_detected, pretend_output
CONFIG_CACHE         status.json (switch):     cache_rebuild["config:cache"].exit
VIEW_CACHE           status.json (switch):     cache_rebuild["view:cache"].exit
DEPLOY_TIME          status.json (switch):     switched_at
HEALTH_CHECK         smoke-test-live output:   routes.*.ok, app_debug
```

After a real deploy, update `deploy/state/last-deployed.json` (tracked in
git — this is the local pointer used for the manifest's `previous_commit`
field on the *next* deploy) and commit it as its own small commit.

## Local validation performed (this session, no production writes)

- `deploy/config-contract.php` run against both the current (fixed) config
  and a locally-reproduced copy of the exact broken state from the
  incident — confirmed it fails the broken state and passes the fixed one.
- Server-side capability probes (read-only, all uploaded scripts deleted
  after): confirmed `disable_functions` blocks exec/shell_exec/system/
  passthru/popen/symlink/link; confirmed `proc_open` actually spawns
  processes including invoking `php -v`; confirmed `PharData` extraction
  round-trips; confirmed directory `rename()` is fast and atomic; confirmed
  `laravel-admin-releases/` can be created as a private sibling of
  `laravel-admin/`.
- `deploy/build-release.sh` run end-to-end locally against the current
  commit (see the session's report for the exact run and result).

## Membership fee policies — what a release that carries them needs

(2026-10-05, Membership Registry task 1; the model and rules are in `docs/MEMBERSHIP_FEE_POLICIES.md`.)

The release adds three **additive** migrations (two nullable columns + a new table + five nullable columns on
`membership_applications`; nothing dropped, the old code ignores all of it) and a one-time data load:

1. `pipeline <id>` as usual (its `migrate-check` shows the three pending migrations).
2. `migrate-apply <id> --i-have-reviewed-the-pretend-output` — safe before the switch.
3. **Load the owner-approved fee schedule from the STAGED release**, before the switch: upload
   `deploy/qa/membership-fees-load.php` to the admin docroot and run it from cron — `php …/public_html/admin/membership-fees-load.php <id>`
   (a dry run that prints the plan and writes nothing), read the plan, then the same with `apply`. It boots
   `laravel-admin-releases/<id>/app` and runs `membership:load-initial-policies`. It is idempotent, so running it again changes
   nothing. Delete the script afterwards (a docroot script answers 404 to anything but the CLI, but there is no reason to leave it).
4. `switch <id>`, `smoke-test-live`.

Until step 3 has run, a membership type with no fee policy is simply not offered on the public site (a price that does
not exist cannot be shown), so doing the load before the switch means the public list never goes empty.
