#!/usr/bin/env bash
# Installs the pinned, checksum-verified gitleaks release into ~/.provatferi-tools/gitleaks/ (no sudo, no PATH change needed:
# scripts/secret-scan.sh looks there). Used once per machine by developers and by .github/workflows/secret-scan.yml.
#
# To upgrade: change VERSION and the six checksums below in ONE commit, taking the checksums from
#   https://github.com/gitleaks/gitleaks/releases/download/v<VERSION>/gitleaks_<VERSION>_checksums.txt
# and re-run `scripts/secret-scan.sh history` before pushing (new releases can add or tighten default rules).
set -euo pipefail

VERSION="8.30.1"
DEST="${GITLEAKS_HOME:-$HOME/.provatferi-tools/gitleaks}"

case "$(uname -s)-$(uname -m)" in
  Linux-x86_64)               ASSET="linux_x64.tar.gz";    SHA256="551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb" ;;
  Linux-aarch64|Linux-arm64)  ASSET="linux_arm64.tar.gz";  SHA256="e4a487ee7ccd7d3a7f7ec08657610aa3606637dab924210b3aee62570fb4b080" ;;
  Darwin-x86_64)              ASSET="darwin_x64.tar.gz";   SHA256="dfe101a4db2255fc85120ac7f3d25e4342c3c20cf749f2c20a18081af1952709" ;;
  Darwin-arm64)               ASSET="darwin_arm64.tar.gz"; SHA256="b40ab0ae55c505963e365f271a8d3846efbc170aa17f2607f13df610a9aeb6a5" ;;
  MINGW*-x86_64|MSYS*-x86_64|CYGWIN*-x86_64)
                              ASSET="windows_x64.zip";     SHA256="d29144deff3a68aa93ced33dddf84b7fdc26070add4aa0f4513094c8332afc4e" ;;
  MINGW*-aarch64|MSYS*-aarch64|MINGW*-arm64)
                              ASSET="windows_arm64.zip";   SHA256="b95f5e4f5c425cedca7ee203d9afd29597e692c4924a12ed42f970537c72cc0f" ;;
  *) echo "install-gitleaks: unsupported platform '$(uname -s)-$(uname -m)' — install gitleaks $VERSION yourself and set GITLEAKS_BIN." >&2; exit 1 ;;
esac

BIN="gitleaks"
case "$ASSET" in *.zip) BIN="gitleaks.exe" ;; esac

if [ -x "$DEST/$BIN" ] && [ "$("$DEST/$BIN" version 2>/dev/null | tr -d '\r')" = "$VERSION" ]; then
  echo "gitleaks $VERSION is already installed at $DEST/$BIN"
  exit 0
fi

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

URL="https://github.com/gitleaks/gitleaks/releases/download/v${VERSION}/gitleaks_${VERSION}_${ASSET}"
echo "Downloading $URL"
curl -fsSL --retry 3 --retry-delay 2 -o "$tmp/$ASSET" "$URL"

actual="$(sha256_of "$tmp/$ASSET")"
if [ "$actual" != "$SHA256" ]; then
  echo "install-gitleaks: CHECKSUM MISMATCH for $ASSET (expected $SHA256, got $actual) — refusing to install." >&2
  exit 1
fi

mkdir -p "$DEST"
case "$ASSET" in
  *.tar.gz) tar -xzf "$tmp/$ASSET" -C "$tmp" "$BIN" ;;
  *.zip)
    if command -v unzip >/dev/null 2>&1; then unzip -o -q "$tmp/$ASSET" "$BIN" -d "$tmp"
    else powershell.exe -NoProfile -Command "Expand-Archive -LiteralPath '$(cygpath -w "$tmp/$ASSET")' -DestinationPath '$(cygpath -w "$tmp")' -Force"; fi ;;
esac
install -m 0755 "$tmp/$BIN" "$DEST/$BIN" 2>/dev/null || { cp "$tmp/$BIN" "$DEST/$BIN"; chmod +x "$DEST/$BIN"; }

echo "Installed gitleaks $("$DEST/$BIN" version | tr -d '\r') at $DEST/$BIN (checksum verified)."
