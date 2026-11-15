#!/bin/sh
# Builds the release zip from the committed tree (dev files are excluded by .gitattributes).
set -e
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: *//p' k-integrity-guard.php)
stable=$(sed -n 's/^Stable tag: *//p' readme.txt)
[ "$version" = "$stable" ] || { echo "Version $version does not match Stable tag $stable" >&2; exit 1; }
git diff --quiet HEAD || echo "Warning: uncommitted changes are not included." >&2
mkdir -p dist
git archive --format=zip --prefix=k-integrity-guard/ -o "dist/k-integrity-guard-$version.zip" HEAD
echo "dist/k-integrity-guard-$version.zip"
