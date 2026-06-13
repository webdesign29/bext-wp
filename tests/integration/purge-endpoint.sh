#!/usr/bin/env bash
#
# purge-endpoint.sh — validate the bext purge contract the Cache module depends
# on. Run on a host where bext is serving; it talks to the bext main listener
# (the same endpoint Env::purge_proxy uses), NOT the legacy cache-purge port.
#
#   tests/integration/purge-endpoint.sh [base-url] [host]
#
# Defaults: base-url http://127.0.0.1 (loopback / Auto mode), host a probe value.
# For a cloud endpoint, pass the URL and set BEXT_TOKEN for the bearer header.
# Exits non-zero on a contract mismatch. (Not run in CI — needs a live bext.)
#
set -euo pipefail

BASE="${1:-http://127.0.0.1}"
HOST="${2:-__bext_wp_probe__.invalid}"
URL="${BASE%/}/__bext/cache/purge-proxy"

echo "POST $URL   host=$HOST"

AUTH=()
[ -n "${BEXT_TOKEN:-}" ] && AUTH=(-H "Authorization: Bearer ${BEXT_TOKEN}")

RESP="$(curl -s -X POST "$URL" \
	-H 'Content-Type: application/json' \
	-H "Host: ${HOST}" \
	"${AUTH[@]}" \
	-d "{\"host\":\"${HOST}\",\"paths\":[\"/\"],\"prefixes\":[]}" \
	-w '\n%{http_code}')"

CODE="$(echo "$RESP" | tail -n1)"
BODY="$(echo "$RESP" | sed '$d')"

echo "  HTTP $CODE"
echo "  $BODY"

[ "$CODE" = "200" ] || { echo "FAIL: expected HTTP 200"; exit 1; }
# purge-proxy returns {"purged":N,"host":...,"paths_count":N,"prefixes_count":N}
echo "$BODY" | grep -q '"purged"' || { echo "FAIL: response missing \"purged\""; exit 1; }
echo "$BODY" | grep -q '"paths_count"' || { echo "FAIL: response missing \"paths_count\""; exit 1; }

echo "PASS: bext purge-proxy contract OK"
