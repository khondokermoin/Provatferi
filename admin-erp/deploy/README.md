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
2. Upload three files via TUS to `public_html/admin/`:
   - `deploy/remote/release-manager.php` → `release-manager.php`
   - `deploy/config-contract.php` → `_bootstrap_config_contract.php`
   - a real `composer.phar` (download from getcomposer.org) → `_bootstrap_composer.phar`
3. Run once via cron (absolute paths, the proven pattern):
   ```
   /usr/bin/php /home/u951246149/domains/provatferi.org/public_html/admin/release-manager.php install
   ```
4. Delete the cron job immediately after (one-shot). Verify
   `laravel-admin-releases/_tooling/release-manager.php` and
   `composer.phar` exist and the public copies are gone.

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

# 4. Only if every step above reported ok:true —
php release-manager.php switch <releaseId>
php release-manager.php smoke-test-live

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

## Rollback

```
php release-manager.php rollback
```

Reads `laravel-admin-releases/CURRENT_RELEASE.json` (written by the last
successful `switch`) for the previous release's path, renames it back into
`laravel-admin/` (the failed release is kept, not deleted, at
`laravel-admin-releases/_rolled-back-<timestamp>/` for a post-mortem), and
rebuilds config/view caches on the restored tree. Same atomicity guarantee
as forward deploys, same direction in reverse.

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
