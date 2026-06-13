#!/usr/bin/env bash
#
# build-zip.sh — package bext-wp into a WordPress-installable ZIP whose top-level
# folder is `bext-wp/` (so WP installs/updates it into wp-content/plugins/bext-wp/).
# Used to attach the asset to a GitHub release (the updater's download_url points
# at releases/latest/download/bext-wp.zip).
#
#   bin/build-zip.sh [output.zip]     # default: ./bext-wp.zip
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/bext-wp.zip}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/bext-wp"

# Runtime files + the docs users want; no dev tooling.
rsync -a \
	--exclude '.git' \
	--exclude '.github' \
	--exclude 'tests' \
	--exclude 'bin' \
	--exclude 'docs' \
	--exclude 'node_modules' \
	--exclude 'vendor' \
	--exclude 'composer.json' \
	--exclude 'composer.lock' \
	--exclude 'phpcs.xml' \
	--exclude '.gitignore' \
	--exclude 'mu-loader.php' \
	--exclude '*.zip' \
	"$REPO"/ "$STAGE/bext-wp/"

rm -f "$OUT"
( cd "$STAGE" && zip -qr "$OUT" bext-wp )

VER="$(grep -m1 "Version:" "$REPO/bext-wp.php" | tr -dc '0-9.')"
echo "Built $OUT (bext-wp v$VER)"
unzip -l "$OUT" | tail -n +2 | head -12
