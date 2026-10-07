#!/bin/bash
#
# Regression test for scripts/resolve-base-branch.sh: argument handling,
# error paths, and the ref NAME it prints. The ranking rules themselves are
# covered by tests/hooks/test-pick-closest-base.sh.
#
# HERMETIC: builds a throwaway repo (mktemp -d) with synthetic origin/main
# and milestone/* refs, so real repo refs are never read.
#
# Usage: bash tests/hooks/test-resolve-base-branch.sh

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

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

# --- Scenario 3: explicit branch argument, not the checked-out branch ------
# The issue branch is cut from a milestone branch, so the printed name must
# be the milestone branch, not origin/main.
git checkout -q -b milestone/v1.0.0 >/dev/null 2>&1
echo msonly > ms.txt
git add -A >/dev/null 2>&1
git commit -q -m "milestone work" >/dev/null 2>&1
git update-ref refs/remotes/origin/milestone/v1.0.0 "$(git rev-parse HEAD)"

git checkout -q -b issue/901-from-milestone >/dev/null 2>&1
echo change > y.txt
git add -A >/dev/null 2>&1
git commit -q -m "issue work on top of milestone" >/dev/null 2>&1

git checkout -q main >/dev/null 2>&1
OUT3="$(run issue/901-from-milestone)"
STATUS3=$?
if [ "$STATUS3" -eq 0 ] && [ "$OUT3" = "milestone/v1.0.0" ]; then
    pass "Scenario 3: explicit branch argument is resolved even when a different branch is checked out"
else
    fail "Scenario 3: explicit branch argument is resolved even when a different branch is checked out" \
        "exit: $STATUS3 (want 0)" "stdout: [$OUT3] (want milestone/v1.0.0)"
fi

git branch -D issue/901-from-milestone milestone/v1.0.0 >/dev/null 2>&1
git update-ref -d refs/remotes/origin/milestone/v1.0.0 >/dev/null 2>&1

# --- Scenario 4: usage error (too many arguments) ---------------------------
"$SCRIPT" a b >/dev/null 2>&1
STATUS4=$?
if [ "$STATUS4" -eq 1 ]; then
    pass "Scenario 4: too many arguments -> exit 1"
else
    fail "Scenario 4: too many arguments -> exit 1" "exit: $STATUS4 (want 1)"
fi

# --- Scenario 5: unresolvable branch name -----------------------------------
OUT5="$(run does-not-exist)"
STATUS5=$?
if [ "$STATUS5" -eq 1 ] && [ -z "$OUT5" ]; then
    pass "Scenario 5: nonexistent branch argument -> exit 1, no stdout"
else
    fail "Scenario 5: nonexistent branch argument -> exit 1, no stdout" \
        "exit: $STATUS5 (want 1)" "stdout: [$OUT5] (want empty)"
fi

harness_report
