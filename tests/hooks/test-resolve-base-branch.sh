#!/bin/bash
#
# Regression test for scripts/resolve-base-branch.sh.
#
# HERMETIC: builds a throwaway repo (mktemp -d) with synthetic origin/main
# and milestone/* refs, so real repo refs are never read. Reuses the same
# ranking as _pick_closest_base() (scripts/lib/pick-closest-base.sh) —
# tests/hooks/test-pick-closest-base.sh already covers that function's own
# rejection rules in depth; this file only checks that
# resolve-base-branch.sh's independent ref-name-preserving re-derivation
# agrees with it on the same scenarios, plus this script's own argument and
# error handling.
#
# Usage: bash tests/hooks/test-resolve-base-branch.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY

export GIT_AUTHOR_NAME="test-resolve-base-branch"
export GIT_AUTHOR_EMAIL="test-resolve-base-branch@localhost"
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME"
export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/resolve-base-branch.sh"
if [ ! -x "$SCRIPT" ]; then
    echo "FAIL: $SCRIPT not found or not executable" >&2
    exit 1
fi

TMPROOT="$(mktemp -d)" || exit 1
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

REPO="$TMPROOT/repo"
git init -q "$REPO" >/dev/null 2>&1
cd "$REPO" || exit 1
git config user.name "$GIT_AUTHOR_NAME"
git config user.email "$GIT_AUTHOR_EMAIL"
git config commit.gpgsign false
git symbolic-ref HEAD refs/heads/main
echo base > README.md
git add -A >/dev/null 2>&1
git commit -q -m initial >/dev/null 2>&1
MAIN_SHA="$(git rev-parse HEAD)"
git update-ref refs/remotes/origin/main "$MAIN_SHA"

run() { "$SCRIPT" "$@" 2>/dev/null; }

# --- Scenario 1: on main itself, resolves to origin/main -------------------
OUT1="$(run main)"
STATUS1=$?
if [ "$STATUS1" -eq 0 ] && [ "$OUT1" = "origin/main" ]; then
    pass "Scenario 1: 'main' resolves to origin/main"
else
    fail "Scenario 1: 'main' resolves to origin/main" \
        "exit: $STATUS1 (want 0)" "stdout: [$OUT1] (want origin/main)"
fi

# --- Scenario 2: an issue branch cut from main with no milestone branch ----
git checkout -q -b issue/900-no-milestone >/dev/null 2>&1
echo change > x.txt
git add -A >/dev/null 2>&1
git commit -q -m "issue work" >/dev/null 2>&1

OUT2="$(run)"
STATUS2=$?
if [ "$STATUS2" -eq 0 ] && [ "$OUT2" = "origin/main" ]; then
    pass "Scenario 2: no milestone branch exists -> falls back to origin/main (current branch, no argument)"
else
    fail "Scenario 2: no milestone branch exists -> falls back to origin/main (current branch, no argument)" \
        "exit: $STATUS2 (want 0)" "stdout: [$OUT2] (want origin/main)"
fi
git checkout -q main >/dev/null 2>&1
git branch -D issue/900-no-milestone >/dev/null 2>&1

# --- Scenario 3: an issue branch cut from a milestone branch ---------------
git checkout -q -b milestone/v1.0.0 >/dev/null 2>&1
echo msonly > ms.txt
git add -A >/dev/null 2>&1
git commit -q -m "milestone work" >/dev/null 2>&1
git update-ref refs/remotes/origin/milestone/v1.0.0 "$(git rev-parse HEAD)"

git checkout -q -b issue/901-from-milestone >/dev/null 2>&1
echo change > y.txt
git add -A >/dev/null 2>&1
git commit -q -m "issue work on top of milestone" >/dev/null 2>&1

OUT3="$(run)"
STATUS3=$?
if [ "$STATUS3" -eq 0 ] && [ "$OUT3" = "milestone/v1.0.0" ]; then
    pass "Scenario 3: issue branch cut from a milestone branch resolves to it, by name"
else
    fail "Scenario 3: issue branch cut from a milestone branch resolves to it, by name" \
        "exit: $STATUS3 (want 0)" "stdout: [$OUT3] (want milestone/v1.0.0)"
fi

# --- Scenario 4: explicit branch argument, not the checked-out branch ------
git checkout -q main >/dev/null 2>&1
OUT4="$(run issue/901-from-milestone)"
STATUS4=$?
if [ "$STATUS4" -eq 0 ] && [ "$OUT4" = "milestone/v1.0.0" ]; then
    pass "Scenario 4: explicit branch argument is resolved even when a different branch is checked out"
else
    fail "Scenario 4: explicit branch argument is resolved even when a different branch is checked out" \
        "exit: $STATUS4 (want 0)" "stdout: [$OUT4] (want milestone/v1.0.0)"
fi

git branch -D issue/901-from-milestone milestone/v1.0.0 >/dev/null 2>&1
git update-ref -d refs/remotes/origin/milestone/v1.0.0 >/dev/null 2>&1

# --- Scenario 5: usage error (too many arguments) ---------------------------
"$SCRIPT" a b >/dev/null 2>&1
STATUS5=$?
if [ "$STATUS5" -eq 1 ]; then
    pass "Scenario 5: too many arguments -> exit 1"
else
    fail "Scenario 5: too many arguments -> exit 1" "exit: $STATUS5 (want 1)"
fi

# --- Scenario 6: unresolvable branch name -----------------------------------
OUT6="$(run does-not-exist)"
STATUS6=$?
if [ "$STATUS6" -eq 1 ] && [ -z "$OUT6" ]; then
    pass "Scenario 6: nonexistent branch argument -> exit 1, no stdout"
else
    fail "Scenario 6: nonexistent branch argument -> exit 1, no stdout" \
        "exit: $STATUS6 (want 1)" "stdout: [$OUT6] (want empty)"
fi

# --- Report ------------------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
