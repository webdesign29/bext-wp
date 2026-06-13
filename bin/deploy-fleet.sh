#!/usr/bin/env bash
#
# deploy-fleet.sh — install/update bext-wp into every WordPress site on this host.
#
# Because each Ploi site runs under a strict open_basedir
# (/home/<user>/<domain>/public/:/tmp/), a shared symlinked mu-plugin would be
# BLOCKED. So we COPY the package into each site's own tree and chown it to the
# site user.
#
# Layout written per site:
#   wp-content/mu-plugins/bext.php       <- loader stub (auto-loaded by WP)
#   wp-content/mu-plugins/bext-wp/...    <- the package
#
# Usage (run with sudo — writes into other users' dirs + chowns):
#   sudo bin/deploy-fleet.sh --list                 # list discovered WP sites
#   sudo bin/deploy-fleet.sh --site=tribunedes...   # deploy to matching site(s) only (canary)
#   sudo bin/deploy-fleet.sh --dry-run              # show what would happen, change nothing
#   sudo bin/deploy-fleet.sh                        # deploy to ALL WP sites
#   sudo bin/deploy-fleet.sh --remove --site=foo    # remove the plugin from matching site(s)
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DRY=0
LIST=0
REMOVE=0
ALL=0
FILTER=""

for arg in "$@"; do
	case "$arg" in
		--dry-run) DRY=1 ;;
		--list) LIST=1 ;;
		--remove) REMOVE=1 ;;
		--all) ALL=1 ;;
		--site=*) FILTER="${arg#*=}" ;;
		-h|--help) sed -n '2,30p' "$0"; exit 0 ;;
		*) echo "unknown arg: $arg" >&2; exit 2 ;;
	esac
done

# Guard against an accidental fleet-wide removal: require --site or --all.
if [ "$REMOVE" -eq 1 ] && [ -z "$FILTER" ] && [ "$ALL" -eq 0 ] && [ "$DRY" -eq 0 ]; then
	echo "Refusing to remove bext-wp from ALL sites without --site=<match> or --all." >&2
	exit 2
fi

# Discover WP sites: any */public/wp-config.php under /home.
mapfile -t PUBDIRS < <(ls -d /home/*/*/public/wp-config.php 2>/dev/null | xargs -r -n1 dirname | sort -u)

if [ "${#PUBDIRS[@]}" -eq 0 ]; then
	echo "No WordPress sites found under /home/*/*/public/" >&2
	exit 1
fi

deployed=0
skipped=0

for pub in "${PUBDIRS[@]}"; do
	if [ -n "$FILTER" ] && [[ "$pub" != *"$FILTER"* ]]; then
		skipped=$((skipped + 1))
		continue
	fi

	owner="$(stat -c '%U:%G' "$pub")"
	mu="$pub/wp-content/mu-plugins"

	if [ "$LIST" -eq 1 ]; then
		printf '  %-55s owner=%s\n' "$pub" "$owner"
		continue
	fi

	echo "==> $pub  (owner=$owner)"

	if [ "$REMOVE" -eq 1 ]; then
		if [ "$DRY" -eq 1 ]; then
			echo "    [dry-run] would remove $mu/bext.php and $mu/bext-wp/"
		else
			rm -f "$mu/bext.php"
			rm -rf "$mu/bext-wp"
			echo "    removed"
		fi
		deployed=$((deployed + 1))
		continue
	fi

	if [ "$DRY" -eq 1 ]; then
		echo "    [dry-run] would rsync package -> $mu/bext-wp/ + loader -> $mu/bext.php (chown $owner)"
		deployed=$((deployed + 1))
		continue
	fi

	mkdir -p "$mu/bext-wp"

	# Copy only runtime files (no .git, tests, dev tooling, docs). LICENSE is kept.
	rsync -a --delete \
		--exclude '.git' \
		--exclude '.github' \
		--exclude 'tests' \
		--exclude 'bin' \
		--exclude 'docs' \
		--exclude 'node_modules' \
		--exclude 'vendor' \
		--exclude 'mu-loader.php' \
		--exclude 'composer.json' \
		--exclude 'composer.lock' \
		--exclude 'phpcs.xml' \
		--exclude '.gitignore' \
		--exclude 'CONTRIBUTING.md' \
		--exclude 'CHANGELOG.md' \
		--exclude 'README.md' \
		--exclude '*.dist' \
		"$REPO"/ "$mu/bext-wp/"

	# Loader stub at the top level of mu-plugins.
	cp "$REPO/mu-loader.php" "$mu/bext.php"

	chown -R "$owner" "$mu/bext-wp" "$mu/bext.php"

	deployed=$((deployed + 1))
	echo "    installed $(cat "$REPO"/bext-wp.php | grep -m1 "Version:" | tr -dc '0-9.')"
done

echo
echo "Done. ${deployed} site(s) processed, ${skipped} skipped."
[ "$LIST" -eq 1 ] && exit 0
[ "$DRY" -eq 1 ] && echo "(dry-run — nothing changed)"
exit 0
