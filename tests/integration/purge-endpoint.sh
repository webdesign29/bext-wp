#!/usr/bin/env bash
#
# purge-endpoint.sh — validate the bext cache-purge contract the Cache module
# depends on. Run on a host where bext is serving (uses the loopback purge port).
#
#   tests/integration/purge-endpoint.sh [host]
#
# Exits non-zero on contract mismatch.
#
set -euo pipefail

HOST="${1:-__bext_wp_probe__.invalid}"

# Resolve the purge port the way the plugin does (param isn't available here, so
# fall back to the discovery file, then 8444).
PORT=""
for f in /run/bext/cache-purge.port /tmp/bext-cache-purge.port; do
	if [ -r "$f" ]; then PORT="$(tr -dc '0-9' < "$f")"; [ -n "$PORT" ] && break; fi
done
PORT="${PORT:-8444}"

URL="http://127.0.0.1:${PORT}/nginx-cache/purge-site"
echo "POST $URL  host=$HOST"

RESP="$(curl -s -X POST "$URL" \
	-H 'Content-Type: application/json' \
	-d "{\"host\":\"${HOST}\",\"paths\":[\"/\"],\"prefixes\":[]}" \
	-w '\n%{http_code}')"

CODE="$(echo "$RESP" | tail -n1)"
BODY="$(echo "$RESP" | sed '$d')"

echo "  HTTP $CODE"
echo "  $BODY"

[ "$CODE" = "200" ] || { echo "FAIL: expected HTTP 200"; exit 1; }
echo "$BODY" | grep -q '"success"' || { echo "FAIL: response missing \"success\""; exit 1; }

echo "PASS: bext purge contract OK (port ${PORT})"
