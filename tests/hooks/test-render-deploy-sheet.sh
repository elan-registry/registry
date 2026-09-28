#!/bin/bash
#
# Regression test for scripts/render-deploy-sheet.sh (#2225): a match early
# in a large (>64 KB) diff must still be detected. A piped `grep -q` under
# `pipefail` returned 141 there, which read as "no match".
#
# HERMETIC: builds a throwaway git repo under a temp dir and runs the real
# script against synthetic branches there. Never touches this repo's own
# branches or history.
#
# Usage: bash tests/hooks/test-render-deploy-sheet.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/render-deploy-sheet.sh"
if [ ! -f "$SCRIPT" ]; then
    echo "FAIL: $SCRIPT not found" >&2
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

pass() { TESTS_RUN=$((TESTS_RUN + 1)); echo "PASS: $1"; }
fail() {
    TESTS_RUN=$((TESTS_RUN + 1))
    TESTS_FAILED=$((TESTS_FAILED + 1))
    echo "FAIL: $1"
    shift
    local line
    for line in "$@"; do echo "      $line"; done
}

# --- Build a throwaway git repo -------------------------------------------

REPO="$TMPROOT/repo"
mkdir -p "$REPO"
cd "$REPO" || exit 1
git init -q -b main 2>/dev/null || { git init -q; git checkout -q -b main 2>/dev/null || true; }
git config user.name "test-render-deploy-sheet"
git config user.email "test-render-deploy-sheet@localhost"

echo "base" > base.txt
git add base.txt
git commit -q -m "base commit on main"

# Writes 20000 lines (~300 KB), so the diff runs far past a pipe buffer.
pad() { awk 'BEGIN { for (i = 0; i < 20000; i++) print "padding line " i }' > "$1"; }

# --- Case 1: CREATE TRIGGER early, followed by >64 KB more diff -----------
# A migration file with CREATE TRIGGER near the top, plus a large second
# file so the diff exceeds the ~64 KB pipe buffer after the match line.
git checkout -q -b milestone/vtest main
mkdir -p database/migrations
{
    echo "<?php"
    echo "-- CREATE TRIGGER test_trigger"
    echo "-- rest of migration"
} > database/migrations/20990101000000_test.php
pad padding.txt
git add database/migrations/20990101000000_test.php padding.txt
git commit -q -m "add trigger migration + padding"

OUT1="$(bash "$SCRIPT" vtest 2>/dev/null)"
if printf '%s\n' "$OUT1" | grep -q '^migration: TRUE$' \
    && printf '%s\n' "$OUT1" | grep -q '^trigger-migration: TRUE$'; then
    pass "Case 1: CREATE TRIGGER early in a >64KB diff is detected"
else
    fail "Case 1: CREATE TRIGGER early in a >64KB diff is detected" "output: [$OUT1]"
fi

# --- Case 2: control — large migration WITHOUT CREATE TRIGGER -------------
git checkout -q -b milestone/vctrl main
mkdir -p database/migrations
{
    echo "<?php"
    echo "-- ordinary migration, no trigger"
} > database/migrations/20990101000001_control.php
pad padding.txt
git add database/migrations/20990101000001_control.php padding.txt
git commit -q -m "add control migration + padding"

OUT2="$(bash "$SCRIPT" vctrl 2>/dev/null)"
if printf '%s\n' "$OUT2" | grep -q '^migration: TRUE$' \
    && ! printf '%s\n' "$OUT2" | grep -q '^trigger-migration: TRUE$'; then
    pass "Case 2 (control): migration without CREATE TRIGGER -> migration TRUE, trigger-migration absent"
else
    fail "Case 2 (control): migration without CREATE TRIGGER -> migration TRUE, trigger-migration absent" \
        "output: [$OUT2]"
fi

# --- Case 3: .env.example changes AND a large DIFF_FILES name list --------
git checkout -q -b milestone/vmany main
echo "FOO=bar" > .env.example
mkdir -p many
# Few, long names: the script runs two commands per changed file, so the file
# count sets the run time; the name list only has to pass 70 KB.
i=0
while [ "$i" -lt 800 ]; do
    printf 'x' > "many/generated-file-name-padded-to-be-very-long-for-fewer-files-in-this-fixture-$(printf '%04d' "$i").txt"
    i=$((i + 1))
done
git add .env.example many
git commit -q -m "change .env.example + add many files"

DIFF_NAME_BYTES="$(git diff --name-only "main...milestone/vmany" | wc -c | tr -d ' ')"
if [ "$DIFF_NAME_BYTES" -le 70000 ]; then
    fail "Case 3: DIFF_FILES fixture too small to exercise the bug" \
        "name-list bytes: $DIFF_NAME_BYTES (want > 70000)"
else
    OUT3="$(bash "$SCRIPT" vmany 2>/dev/null)"
    if printf '%s\n' "$OUT3" | grep -q '^env-vars: TRUE$'; then
        pass "Case 3: .env.example match survives a >64KB DIFF_FILES name list"
    else
        fail "Case 3: .env.example match survives a >64KB DIFF_FILES name list" \
            "name-list bytes: $DIFF_NAME_BYTES" "output: [$OUT3]"
    fi
fi

git checkout -q main

# --- Report ------------------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
