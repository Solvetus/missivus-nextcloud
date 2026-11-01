#!/usr/bin/env bash
#
# Build dist/missivus-<version>.tar.gz in the Nextcloud App Store layout:
# a single top-level missivus/ directory holding only what the app needs at
# runtime.
#
# Allowlist, not blocklist: a denylist is one forgotten pattern away from
# shipping a PEM. No tests, no tools, no docs-internal, no composer files,
# no .github, no development configs.

set -euo pipefail
cd "$(dirname "$0")/.."

version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
[ -n "$version" ] || { echo "could not read the version from appinfo/info.xml" >&2; exit 1; }

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/missivus"

# The allowlist.
cp -R appinfo lib templates js css img l10n "$stage/missivus/"
cp LICENSE CHANGELOG.md README.md "$stage/missivus/"

# Belt and braces: nothing hidden, no OS droppings, no credentials-shaped files.
find "$stage" \( -name '.DS_Store' -o -name '*.swp' -o -name '.*' -type f \) -delete
find "$stage" \( -name '*.pem' -o -name '*.key' -o -name '*.pfx' -o -name '*.p12' \
  -o -name '*.crt' -o -name '*.cer' -o -name '.env*' \) -delete

# The vendored transport must be byte-identical to its upstream when the
# upstream checkout is present; a drifted tree must never ship.
upstream="$(cd .. && pwd)/missivus-matomo/libs/Solvetus/Missivus"
if [ -d "$upstream" ]; then
  diff -r "$upstream" "$stage/missivus/lib/Vendor/Solvetus/Missivus" \
    || { echo "vendored transport differs from upstream — refusing to build" >&2; exit 1; }
  echo "vendored transport verified byte-identical to missivus-matomo"
fi

mkdir -p dist
tarball="dist/missivus-$version.tar.gz"
# --no-xattrs/--disable-copyfile keep macOS from adding ._* AppleDouble files.
COPYFILE_DISABLE=1 tar -czf "$tarball" -C "$stage" --no-xattrs missivus 2>/dev/null \
  || COPYFILE_DISABLE=1 tar -czf "$tarball" -C "$stage" missivus

echo "built $tarball"
tar -tzf "$tarball" | head -20
echo "…"
echo "$(tar -tzf "$tarball" | wc -l | tr -d ' ') entries, $(du -h "$tarball" | cut -f1 | tr -d ' ')"
