#!/usr/bin/env bash
#
# Builds a deterministic, checksummed deployment artifact for admin-erp from
# one exact git commit. Every check in this script can abort the build; a
# release that reaches deploy/releases/<sha>/ passed all of them.
#
# Usage: deploy/build-release.sh <commit-sha>
#
# Replaces hand-picking individual changed files for production, which is
# what let AppServiceProvider.php ship without config/mail.php on 2026-09-09
# (commit 59530fa) — those two files were logically dependent, but only one
# was in the file list someone typed. git archive <sha> cannot make that
# mistake: it is either the whole committed tree at that SHA, or it fails.
set -euo pipefail

if [ $# -ne 1 ]; then
  echo "Usage: $0 <commit-sha>" >&2
  exit 2
fi

TARGET_SHA_INPUT="$1"
ADMIN_ERP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$ADMIN_ERP_DIR/.." && pwd)"
DEPLOY_DIR="$ADMIN_ERP_DIR/deploy"
cd "$REPO_ROOT"

fail() { echo "STOP: $1" >&2; exit 1; }
step() { echo; echo "=== $1 ==="; }

# Every `php` invocation on at least this dev machine duplicates a startup
# warning (a double-loaded openssl module) onto stdout as well as stderr —
# it fires during PHP's own bootstrap, before any script code runs, so no
# in-script fix can stop it. A bare `$(php -r '...')` capture would silently
# pick up that warning text ahead of the real value. This wraps any such
# one-liner so only text after a unique marker survives, making the result
# immune to whatever a given environment's php.ini prints on startup.
php_json_field() {
  local expr="$1"; shift
  local out
  out="$(php -r "echo 'RESULT_MARKER:'.($expr);" "$@" 2>/dev/null)"
  echo "${out##*RESULT_MARKER:}"
}

# --- resolve and validate the commit -----------------------------------
step "Resolving commit"
TARGET_SHA="$(git rev-parse --verify "${TARGET_SHA_INPUT}^{commit}" 2>/dev/null)" || fail "not a valid commit: ${TARGET_SHA_INPUT}"
SHORT_SHA="${TARGET_SHA:0:12}"
echo "target commit: $TARGET_SHA"

step "Commit exists on origin/main"
git fetch origin main --quiet
git merge-base --is-ancestor "$TARGET_SHA" origin/main \
  || fail "commit $TARGET_SHA is not an ancestor of origin/main — refusing to deploy code that isn't on the shared branch"
echo "confirmed: $TARGET_SHA is on origin/main"

step "Local working tree is clean (admin-erp/ only — this repo is a monorepo; other apps' untracked scratch files are not this pipeline's concern)"
[ -z "$(git status --porcelain admin-erp/)" ] || fail "admin-erp/ has uncommitted changes — commit or stash first"
echo "clean"

# --- extract the EXACT commit tree to an isolated temp dir --------------
# Every check from here on runs against this extraction, never the live
# working directory — this is what "artifact must represent one exact Git
# tree" actually means: what's tested is byte-identical to what ships.
step "Extracting admin-erp/ at $SHORT_SHA via git archive (isolated from working tree)"
# Created under deploy/ itself, not bash's default /tmp — on this Git-Bash-
# on-Windows setup /tmp is an MSYS mount whose translation for bash-internal
# I/O and for a spawned child process's argv resolve to two DIFFERENT real
# Windows locations (Git's own install tree vs %TEMP%, observed directly:
# a path built from /tmp/... written by bash redirection was not the same
# file php.exe saw when given that identical string as $argv[1]). A path
# under this project directory has no such ambiguity for any tool.
WORK="$(mktemp -d --tmpdir="$DEPLOY_DIR")"
trap 'rm -rf "$WORK"' EXIT
EXTRACT="$WORK/tree"
mkdir -p "$EXTRACT"
git archive "$TARGET_SHA" -- admin-erp | tar -x -C "$EXTRACT"
APP="$EXTRACT/admin-erp"
[ -f "$APP/artisan" ] || fail "extracted tree has no artisan — admin-erp/ wasn't in this commit?"
echo "extracted to: $APP"

# --- secret scan ----------------------------------------------------------
step "Secret scan"
SECRET_HITS="$(grep -rInE "(password|secret|api[_-]?key|private[_-]?key)\s*[:=]\s*['\"][^'\"]{6,}" \
  "$APP/app" "$APP/config" "$APP/routes" "$APP/database" 2>/dev/null \
  | grep -viE "env\(|config\(|\\\$this->|function |//|\* " || true)"
if [ -n "$SECRET_HITS" ]; then
  echo "$SECRET_HITS" >&2
  fail "possible hardcoded secret found — see lines above"
fi
[ -f "$APP/.env" ] && fail ".env present in the archived tree (should be impossible — it's gitignored; check for a tracking mistake)"
echo "clean"

# --- PHP syntax check on every file in the artifact ----------------------
step "PHP syntax check"
SYNTAX_FAIL=0
while IFS= read -r -d '' f; do
  php -l "$f" >/tmp/lint_out_$$.txt 2>&1 || { cat /tmp/lint_out_$$.txt >&2; SYNTAX_FAIL=1; }
  rm -f /tmp/lint_out_$$.txt
done < <(find "$APP/app" "$APP/config" "$APP/database" "$APP/routes" "$APP/bootstrap" -name '*.php' -print0)
[ "$SYNTAX_FAIL" -eq 0 ] || fail "PHP syntax errors found — see above"
echo "all files parse"

# --- composer checks (against the exact committed composer.lock) --------
step "Composer validate + platform requirements"
(cd "$APP" && composer validate --no-check-all --strict) || fail "composer validate failed"
(cd "$APP" && composer check-platform-reqs 2>&1) | tee "$WORK/platform-reqs.txt"
grep -qi "not satisfied" "$WORK/platform-reqs.txt" && fail "composer check-platform-reqs found an unmet requirement"
echo "composer checks passed"

# --- config contract check (needs a real vendor/ to boot the app) -------
step "Installing dependencies into the isolated tree (for testing + contract check only — this vendor/ is not shipped)"
(cd "$APP" && composer install --no-interaction --quiet) || fail "composer install failed in isolated tree"

# artisan needs *some* .env to boot for config:contract purposes. Never the
# real one — a minimal disposable one scoped to this temp tree only.
cp "$APP/.env.example" "$APP/.env"
(cd "$APP" && php artisan key:generate --force --quiet)

# Built here, BEFORE the test suite runs — several views (guest.blade.php's
# @vite directive, pulled in by anything using the auth layout) need
# public/build/manifest.json to exist or every test touching those routes
# fails with ViteManifestNotFoundException, not because of an app bug.
# Caught by actually running this pipeline: the first version built this
# only at packaging time, after the tests had already failed on it.
step "Building frontend assets (needed before tests run, and packaged as public-assets.tar afterward)"
(cd "$APP" && npm ci --silent && npm run build --silent) || fail "frontend asset build failed"

step "Config contract check"
php "$DEPLOY_DIR/config-contract.php" "$APP" "$WORK/config-contract.json"
CONTRACT_PASS="$(php_json_field 'json_decode(file_get_contents($argv[1]))->pass ? "true" : "false"' "$WORK/config-contract.json")"
[ "$CONTRACT_PASS" = "true" ] || fail "config contract check failed — a key referenced by code is missing from the deployed config tree (see above). This is the exact failure class from the 2026-09-09 incident."
echo "contract OK"

# --- MariaDB test suite ---------------------------------------------------
step "MariaDB test suite"
(cd "$APP" && php artisan test) || fail "test suite failed"
echo "tests passed"

# --- detect pending migrations (informational at this stage; see deploy docs) ---
step "Migration detection"
MIGRATION_COUNT="$(find "$APP/database/migrations" -name '*.php' | wc -l | tr -d ' ')"
echo "migrations in this commit: $MIGRATION_COUNT (compared against production during remote migrate-check — see deploy/README.md)"

# --- clean the isolated vendor/.env before packaging: NOT shipped --------
rm -rf "$APP/vendor" "$APP/.env" "$APP/.env.testing.bak" "$APP/bootstrap/cache/"*.php

# --- package: split private tree vs public assets ------------------------
step "Packaging private application tree"
RELEASE_DIR="$DEPLOY_DIR/releases/${SHORT_SHA}-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$RELEASE_DIR"

PRIVATE_TAR="$RELEASE_DIR/private.tar"
tar -cf "$PRIVATE_TAR" -C "$EXTRACT" \
  --exclude='admin-erp/vendor' \
  --exclude='admin-erp/node_modules' \
  --exclude='admin-erp/tests' \
  --exclude='admin-erp/.env*' \
  --exclude='admin-erp/*credentials*' \
  --exclude='admin-erp/storage/logs' \
  --exclude='admin-erp/storage/framework/cache' \
  --exclude='admin-erp/storage/framework/sessions' \
  --exclude='admin-erp/storage/framework/views' \
  --exclude='admin-erp/storage/framework/testing' \
  --exclude='admin-erp/bootstrap/cache' \
  --exclude='admin-erp/public' \
  --exclude='admin-erp/deploy' \
  admin-erp
echo "private.tar: $(du -h "$PRIVATE_TAR" | cut -f1)"

step "Packaging public docroot assets (built frontend, brand assets — NOT index.php)"
[ -d "$APP/public/build" ] || fail "public/build/ missing at packaging time — frontend build step above should have created it"
PUBLIC_TAR="$RELEASE_DIR/public-assets.tar"
tar -cf "$PUBLIC_TAR" -C "$APP/public" \
  --exclude='index.php' \
  --exclude='.htaccess' \
  build brand favicon.ico robots.txt 2>/dev/null || true
echo "public-assets.tar: $(du -h "$PUBLIC_TAR" | cut -f1)"

# index.php is deliberately reported, never silently bundled for auto-apply —
# it's the split-layout front controller; see deploy/README.md "index.php".
INDEX_PHP_SHA256="$(sha256sum "$APP/public/index.php" | cut -d' ' -f1)"

# --- checksums + manifest -------------------------------------------------
step "Writing checksums and manifest"
PRIVATE_SHA256="$(sha256sum "$PRIVATE_TAR" | cut -d' ' -f1)"
PUBLIC_SHA256="$(sha256sum "$PUBLIC_TAR" | cut -d' ' -f1)"
COMPOSER_LOCK_HASH="$(php_json_field 'json_decode(file_get_contents($argv[1]))->{"content-hash"}' "$APP/composer.lock")"
PREVIOUS_SHA="$(php_json_field '($p=$argv[1]) && is_file($p) ? (json_decode(file_get_contents($p))->commit ?? "none") : "none"' "$DEPLOY_DIR/state/last-deployed.json")"

cat > "$RELEASE_DIR/manifest.json" <<EOF
{
  "commit": "$TARGET_SHA",
  "commit_short": "$SHORT_SHA",
  "previous_commit": "$PREVIOUS_SHA",
  "built_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "composer_lock_content_hash": "$COMPOSER_LOCK_HASH",
  "artifacts": {
    "private_tar": { "file": "private.tar", "sha256": "$PRIVATE_SHA256" },
    "public_assets_tar": { "file": "public-assets.tar", "sha256": "$PUBLIC_SHA256" }
  },
  "public_index_php_sha256": "$INDEX_PHP_SHA256",
  "checks_passed": {
    "commit_on_origin_main": true,
    "clean_working_tree": true,
    "secret_scan": true,
    "php_syntax": true,
    "composer_validate": true,
    "composer_platform_reqs": true,
    "config_contract": true,
    "mariadb_test_suite": true
  },
  "migrations_in_release": $MIGRATION_COUNT
}
EOF

cat "$RELEASE_DIR/manifest.json"
echo
echo "Release built: $RELEASE_DIR"
echo "Next: upload with deploy/upload-release.sh \"$RELEASE_DIR\""
