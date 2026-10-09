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
#
# In GitHub Actions every finding is also published as an annotation (file, line, rule — never the value), so a failed run says why
# on the run page and on the pull request without opening the log.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

MODE="${1:-}"
MIN_VERSION="8.25.0"
WORK_DIR="$(mktemp -d)"

on_exit() {
  local rc=$?
  rm -rf "$WORK_DIR"
  if [ "$rc" -ne 0 ] && [ -n "${GITHUB_ACTIONS:-}" ]; then
    echo "::error title=Secret scan::scripts/secret-scan.sh $MODE exited with status $rc (1 = a secret or a forbidden file was found, 2 = scanner missing or too old)"
  fi
}
trap on_exit EXIT

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
  local offenders path
  offenders="$(printf '%s\n' "$1" | grep -E "$FORBIDDEN" | grep -v -E "$TEMPLATE" || true)"
  if [ -n "$offenders" ]; then
    echo "secret-scan: these files must never be committed (see docs/SECRETS.md):" >&2
    printf '%s\n' "$offenders" | while IFS= read -r path; do
      echo "  $path" >&2
      if [ -n "${GITHUB_ACTIONS:-}" ]; then echo "::error file=$path,title=Secret scan - file must never be committed::see docs/SECRETS.md"; fi
    done
    echo "secret-scan: unstage them (git rm --cached <file>) and keep them ignored; templates must be named *.example." >&2
    return 1
  fi
}

# One gitleaks run. In GitHub Actions the JSON report (values redacted) is turned into annotations.
run_gitleaks() {
  local rc=0 report="$WORK_DIR/report.json"
  if [ -n "${GITHUB_ACTIONS:-}" ]; then
    "$GL" "$@" --redact --no-banner -c .gitleaks.toml -v -f json -r "$report" || rc=$?
    if [ -s "$report" ] && command -v jq >/dev/null 2>&1; then
      jq -r '.[] | "::error file=\(.File),line=\(.StartLine),title=Secret scan - \(.RuleID)::\(.Description) (commit \(.Commit[0:7]))"' "$report" || true
    fi
  else
    "$GL" "$@" --redact --no-banner -c .gitleaks.toml -v || rc=$?
  fi
  return "$rc"
}

case "$MODE" in
  staged)
    check_forbidden "$(git diff --cached --name-only --diff-filter=ACMR)"
    run_gitleaks git --staged
    ;;
  history)
    check_forbidden "$(git ls-files)"
    if [ -n "${CI:-}" ]; then LOG_OPTS="--all"; else LOG_OPTS="--branches --tags --remotes HEAD"; fi
    if [ -n "${GITHUB_ACTIONS:-}" ]; then
      echo "::notice title=Secret scan::gitleaks $VERSION, $(git rev-list --all --count) commits reachable from $(git for-each-ref | wc -l | tr -d ' ') refs"
    fi
    run_gitleaks git --log-opts="$LOG_OPTS" .
    ;;
  tree)
    check_forbidden "$(git ls-files --cached --others --exclude-standard)"
    SNAPSHOT="$WORK_DIR/snapshot"
    mkdir -p "$SNAPSHOT"
    # A copy of exactly the files git would commit: ignored files (node_modules, vendor, local .env …) are not in it, and the
    # paths in the report — and in the allow-lists — are repository-relative.
    git ls-files -z --cached --others --exclude-standard | tar --null --ignore-failed-read -T - -cf - 2>/dev/null | tar -xf - -C "$SNAPSHOT"
    cp .gitleaks.toml "$SNAPSHOT/.gitleaks.toml"
    (cd "$SNAPSHOT" && run_gitleaks dir .)
    ;;
  *)
    echo "usage: scripts/secret-scan.sh staged|history|tree" >&2
    exit 64
    ;;
esac
