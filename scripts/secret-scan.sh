#!/usr/bin/env bash
# Secret scan for this repository. Scanner: gitleaks (https://github.com/gitleaks/gitleaks). Policy: .gitleaks.toml. Rules of the house: docs/SECRETS.md.
#
#   scripts/secret-scan.sh staged    what is about to be committed        (run by .githooks/pre-commit)
#   scripts/secret-scan.sh history   every commit on every branch and tag (run by CI on every push and pull request; run it before you push)
#   scripts/secret-scan.sh tree      the files on disk that git would let you commit — tracked, or untracked and not ignored.
#                                    Finds a secret-bearing file BEFORE anyone runs `git add .`.
#
# Exit status: 0 clean · 1 a secret (or a file that must never be committed) was found — values are redacted in the output ·
#              2 the scanner is missing or too old (run scripts/install-gitleaks.sh). It fails CLOSED: no scanner, no pass.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

MODE="${1:-}"
MIN_VERSION="8.25.0"

find_gitleaks() {
  local candidate
  if [ -n "${GITLEAKS_BIN:-}" ] && [ -x "$GITLEAKS_BIN" ]; then echo "$GITLEAKS_BIN"; return 0; fi
  for candidate in "$HOME/.provatferi-tools/gitleaks/gitleaks" "$HOME/.provatferi-tools/gitleaks/gitleaks.exe"; do
    if [ -x "$candidate" ]; then echo "$candidate"; return 0; fi
  done
  for candidate in gitleaks gitleaks.exe; do
    if command -v "$candidate" >/dev/null 2>&1; then command -v "$candidate"; return 0; fi
  done
  return 1
}

if ! GL="$(find_gitleaks)"; then
  echo "secret-scan: gitleaks is not installed, so nothing can be checked. Run:  bash scripts/install-gitleaks.sh" >&2
  exit 2
fi
VERSION="$("$GL" version 2>/dev/null | tr -d '\r' | sed 's/^v//')"
if [ -z "$VERSION" ] || [ "$(printf '%s\n%s\n' "$MIN_VERSION" "$VERSION" | sort -V | head -n1)" != "$MIN_VERSION" ]; then
  echo "secret-scan: gitleaks '$VERSION' is older than $MIN_VERSION (the policy needs multi-condition allow-lists). Run:  bash scripts/install-gitleaks.sh" >&2
  exit 2
fi

# Files that must never be committed, whatever they contain (a stricter net than the scanner: a secret in a format nobody has a rule for
# is still caught when it sits in a file whose very name says "secret"). Templates (.example/.sample/.template/.dist) are fine.
FORBIDDEN='(^|/)(\.env(\.[^/]+)?|wp-config\.php|\.super-admin-credentials\.txt|[^/]*credentials[^/]*\.txt|id_rsa[^/]*|id_ed25519[^/]*|[^/]*\.(pem|ppk|p12|pfx|jks|keystore)|auth\.json|\.netrc|\.claude/settings(\.local)?\.json)$'
TEMPLATE='\.(example|sample|template|dist)$'

check_forbidden() {
  local offenders
  offenders="$(printf '%s\n' "$1" | grep -E "$FORBIDDEN" | grep -v -E "$TEMPLATE" || true)"
  if [ -n "$offenders" ]; then
    echo "secret-scan: these files must never be committed (see docs/SECRETS.md):" >&2
    printf '  %s\n' $offenders >&2
    echo "secret-scan: unstage them (git rm --cached <file>) and keep them ignored; templates must be named *.example." >&2
    return 1
  fi
}

case "$MODE" in
  staged)
    check_forbidden "$(git diff --cached --name-only --diff-filter=ACMR)"
    "$GL" git --staged --redact --no-banner -c .gitleaks.toml -v
    ;;
  history)
    check_forbidden "$(git ls-files)"
    if [ -n "${CI:-}" ]; then LOG_OPTS="--all"; else LOG_OPTS="--branches --tags --remotes HEAD"; fi
    "$GL" git --redact --no-banner -c .gitleaks.toml -v --log-opts="$LOG_OPTS" .
    ;;
  tree)
    check_forbidden "$(git ls-files --cached --others --exclude-standard)"
    SNAPSHOT="$(mktemp -d)"
    trap 'rm -rf "$SNAPSHOT"' EXIT
    # A copy of exactly the files git would commit: ignored files (node_modules, vendor, local .env …) are not in it, and the
    # paths in the report — and in the allow-lists — are repository-relative.
    git ls-files -z --cached --others --exclude-standard | tar --null --ignore-failed-read -T - -cf - 2>/dev/null | tar -xf - -C "$SNAPSHOT"
    (cd "$SNAPSHOT" && "$GL" dir --redact --no-banner -c .gitleaks.toml -v .)
    ;;
  *)
    echo "usage: scripts/secret-scan.sh staged|history|tree" >&2
    exit 64
    ;;
esac
