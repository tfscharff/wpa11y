#!/usr/bin/env bash
# Builds the plugin zip and publishes a GitHub release. Run after the release commit is pushed.
# Usage: bin/release.sh X.Y.Z "Release notes" [--prerelease]
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="$1"; NOTES="$2"; shift 2
grep -Eq "Version:[[:space:]]+${VERSION}([[:space:]]|$)" wpa11y.php || { echo "wpa11y.php header is not $VERSION" >&2; exit 1; }
grep -q "define( 'WPA11Y_VERSION', '${VERSION}' );" wpa11y.php || { echo "WPA11Y_VERSION is not $VERSION" >&2; exit 1; }
grep -q -- "- \*\*${VERSION}\*\*" README.md || { echo "README.md has no changelog entry for $VERSION" >&2; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo "Commit first: the working tree has changes" >&2; exit 1; }
[ "$(git rev-parse HEAD)" = "$(git rev-parse '@{u}')" ] || { echo "Push first" >&2; exit 1; }
rm -rf build && mkdir -p build/wpa11y
cp wpa11y.php README.md build/wpa11y/
if [ -d assets ]; then cp -r assets build/wpa11y/; fi
(cd build && /c/Windows/System32/tar.exe -a -cf "wpa11y-${VERSION}.zip" wpa11y)
gh release create "v${VERSION}" "build/wpa11y-${VERSION}.zip" --title "v${VERSION}" --notes "$NOTES" "$@"
