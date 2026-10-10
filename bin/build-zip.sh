#!/usr/bin/env bash
# Builds build/pnscripts-price-history-<version>.zip (folder pnscripts-price-history/) without development files.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/pnscripts-price-history.php" | head -1)"
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$ROOT/readme.txt" | head -1)"
if [ "$VERSION" != "$STABLE" ]; then
	echo "Version header ($VERSION) and readme Stable tag ($STABLE) differ." >&2
	exit 1
fi
BUILD="$ROOT/build"
STAGE="$BUILD/pnscripts-price-history"
rm -rf "$STAGE" "$BUILD/pnscripts-price-history-$VERSION.zip"
mkdir -p "$STAGE"
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGE/"
(cd "$BUILD" && zip -qr "pnscripts-price-history-$VERSION.zip" pnscripts-price-history)
echo "$BUILD/pnscripts-price-history-$VERSION.zip"
