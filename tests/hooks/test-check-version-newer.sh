#!/bin/bash
#
# Tests for scripts/check-version-newer.sh: numeric comparison of a
# candidate version with the last release tag, including the optional
# fourth number of a patch release from main (v2.30.4.1).
#
# HERMETIC: every case passes the last tag as the second argument, so the
# script never runs `git describe`. The one `git describe` case runs in a
# throwaway repo under a temp dir.
#
# Usage: bash tests/hooks/test-check-version-newer.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/check-version-newer.sh"
if [ ! -x "$SCRIPT" ]; then
    echo "FAIL: $SCRIPT not found or not executable" >&2
    exit 1
fi

TMPROOT="$(mktemp -d)" || exit 1
# shellcheck disable=SC2329 # called only through the EXIT trap below
cleanup() {
    cd / || true
    [ -n "${TMPROOT:-}" ] && rm -rf "$TMPROOT"
}
trap cleanup EXIT

TESTS_RUN=0
TESTS_FAILED=0

# expect <want-exit> <candidate> <last-tag>
expect() {
    local want="$1" candidate="$2" last="$3" out status
    out="$("$SCRIPT" "$candidate" "$last" 2>&1)"
    status=$?
    TESTS_RUN=$((TESTS_RUN + 1))
    if [ "$status" -eq "$want" ]; then
        echo "PASS: $candidate vs $last -> exit $want"
    else
        TESTS_FAILED=$((TESTS_FAILED + 1))
        echo "FAIL: $candidate vs $last -> exit $want"
        echo "      exit: $status" "output: [$out]"
    fi
}

# Three-part versions.
expect 0 v2.30.5 v2.30.4
expect 0 v2.31.0 v2.30.9
expect 0 v3.0.0 v2.99.99
expect 0 v2.10.0 v2.9.0
expect 0 2.30.5 v2.30.4
expect 1 v2.30.4 v2.30.4
expect 1 v2.30.3 v2.30.4
expect 1 v2.9.0 v2.10.0

# Four-part patch-release tags: v2.30.5 > v2.30.4.1 > v2.30.4.
expect 0 v2.30.5 v2.30.4.1
expect 0 v2.30.4.1 v2.30.4
expect 0 v2.30.4.2 v2.30.4.1
expect 0 v2.30.4.10 v2.30.4.9
expect 1 v2.30.4 v2.30.4.1
expect 1 v2.30.4.1 v2.30.4.1
expect 1 v2.30.4.1 v2.30.5
expect 1 v2.30.4.0 v2.30.4

# Versions the script cannot parse.
expect 2 v2.30 v2.30.4
expect 2 v2.30.5 v2.30.4.1.1
expect 2 v2.30.5 v2.30.4-rc1
expect 2 v2.30.5 latest

# --- Last tag from `git describe` is a four-part tag -> exit 0 ------------
REPO="$TMPROOT/repo"
mkdir -p "$REPO"
(
    cd "$REPO" || exit 1
    git init -q
    git config user.name "test-check-version-newer"
    git config user.email "test-check-version-newer@localhost"
    git commit -q --allow-empty -m "release"
    git tag -a v2.30.4 -m "v2.30.4"
    git commit -q --allow-empty -m "hotfix"
    git tag -a v2.30.4.1 -m "v2.30.4.1"
) || exit 1
OUT="$(cd "$REPO" && "$SCRIPT" v2.30.5 2>&1)"
STATUS=$?
TESTS_RUN=$((TESTS_RUN + 1))
if [ "$STATUS" -eq 0 ] && printf '%s' "$OUT" | grep -q 'v2.30.5 is newer than v2.30.4.1'; then
    echo "PASS: last tag from git describe is v2.30.4.1 -> exit 0"
else
    TESTS_FAILED=$((TESTS_FAILED + 1))
    echo "FAIL: last tag from git describe is v2.30.4.1 -> exit 0"
    echo "      exit: $STATUS" "output: [$OUT]"
fi

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
