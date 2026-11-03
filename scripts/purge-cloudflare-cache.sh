#!/usr/bin/env bash
# Purges the Cloudflare cache for usersc/js/ and usersc/css/ on one host,
# then checks that the edge no longer serves the cached copy.
#
# Every deploy that runs `npm run build` rebuilds these files in place under
# the same names (ADR-018). They are served with a one-year max-age, so
# Cloudflare keeps the old build until a purge (DEPLOYMENT.md, "Deployment
# Verification Checklist"). A purge by URL may not clear the `?v=` entries,
# so this script purges by prefix.
#
# The token is a purge-only API token (Zone > Cache Purge > Purge, zone
# elanregistry.org). 1Password holds it. The script reads it with `op run`,
# so the token is never in the shell history or on disk.
#
# Usage:
#   scripts/purge-cloudflare-cache.sh elanregistry.org
#   scripts/purge-cloudflare-cache.sh test.elanregistry.org
#
# Exit codes: 0 purged and checked, 1 the API refused the purge,
# 2 bad usage, 3 purged but the check still sees a cached copy.

set -euo pipefail

readonly ZONE_ID="e539161f94cc9831ac538a5c983f4e87" # elanregistry.org
readonly TOKEN_REF="op://ElanRegistry/Cloudflare Purge ElanRegistry Cache API token/credential"
readonly CHECK_PATH="usersc/js/chart.umd.min.js"

host="${1:-}"
case "$host" in
    elanregistry.org | test.elanregistry.org) ;;
    *)
        echo "Usage: $0 elanregistry.org|test.elanregistry.org" >&2
        exit 2
        ;;
esac

for cmd in op curl jq; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "ERROR: $cmd is not installed." >&2
        exit 2
    fi
done

# Cloudflare wants each prefix as host and path, with no scheme.
body=$(jq -cn --arg h "$host" '{prefixes: ["\($h)/usersc/js/", "\($h)/usersc/css/"]}')

# The token stays inside the op run child process. The single quotes are
# intentional: the child shell expands $CF_TOKEN, not this one.
# shellcheck disable=SC2016
response=$(CF_PURGE_BODY="$body" CF_ZONE_ID="$ZONE_ID" \
    op run --env-file=<(printf 'CF_TOKEN=%s\n' "$TOKEN_REF") -- bash -c '
        curl -sS -X POST \
            -H "Authorization: Bearer $CF_TOKEN" \
            -H "Content-Type: application/json" \
            "https://api.cloudflare.com/client/v4/zones/$CF_ZONE_ID/purge_cache" \
            --data "$CF_PURGE_BODY"
    ')

# A network or proxy failure can return a body that is not JSON. Treat it
# as a refusal and show the raw body.
success=$(jq -r '.success // false' <<<"$response" 2>/dev/null || echo false)
if [ "$success" != "true" ]; then
    echo "ERROR: Cloudflare refused the purge for $host:" >&2
    jq -c '.errors' <<<"$response" >&2 2>/dev/null || echo "$response" >&2
    exit 1
fi
echo "Purged $host/usersc/js/ and $host/usersc/css/."

# A purge takes a few seconds to reach every edge.
sleep 10
status=$(curl -sI "https://$host/$CHECK_PATH" | tr -d '\r' |
    awk -F': ' 'tolower($1) == "cf-cache-status" {print $2}')
echo "https://$host/$CHECK_PATH: cf-cache-status ${status:-unknown}"

# MISS (or any status other than HIT) passes: the edge fetched the file
# from the origin, so it holds no pre-purge copy. HIT is correct only when
# this request filled the cache after the purge, so check its age.
if [ "$status" = "HIT" ]; then
    age=$(curl -sI "https://$host/$CHECK_PATH" | tr -d '\r' |
        awk -F': ' 'tolower($1) == "age" {print $2}')
    if ! [[ "${age:-0}" =~ ^[0-9]+$ ]]; then
        echo "ERROR: the edge returned HIT with an age header that is not a number: '$age'." >&2
        exit 3
    fi
    if [ "${age:-0}" -gt 60 ]; then
        echo "ERROR: the edge still serves a copy that is ${age}s old." >&2
        exit 3
    fi
fi
