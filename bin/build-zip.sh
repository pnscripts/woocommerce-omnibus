#!/usr/bin/env bash
# Builds build/pnscripts-pricetrail-<version>.zip (folder pnscripts-pricetrail/) without development files.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/pnscripts-pricetrail.php" | head -1)"
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$ROOT/readme.txt" | head -1)"
if [ "$VERSION" != "$STABLE" ]; then
	echo "Version header ($VERSION) and readme Stable tag ($STABLE) differ." >&2
	exit 1
fi
BUILD="$ROOT/build"
STAGE="$BUILD/pnscripts-pricetrail"
rm -rf "$STAGE" "$BUILD/pnscripts-pricetrail-$VERSION.zip"
mkdir -p "$STAGE"
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGE/"
(cd "$BUILD" && zip -qr "pnscripts-pricetrail-$VERSION.zip" pnscripts-pricetrail)
echo "$BUILD/pnscripts-pricetrail-$VERSION.zip"
