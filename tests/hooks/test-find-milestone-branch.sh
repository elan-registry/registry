#!/bin/bash
#
# Regression test for scripts/find-milestone-branch.sh.
#
# HERMETIC: builds a throwaway repo (mktemp -d) with its own throwaway bare
# "origin" (also mktemp -d), so the local-only/origin-only/both/neither cases
# are all exercised without touching the real repo's refs or any real
# network remote. Nothing here modifies an existing ref in this checkout.
#
# Usage: bash tests/hooks/test-find-milestone-branch.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY

export GIT_AUTHOR_NAME="test-find-milestone-branch"
export GIT_AUTHOR_EMAIL="test-find-milestone-branch@localhost"
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME"
export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/find-milestone-branch.sh"
if [ ! -x "$SCRIPT" ]; then
    echo "FAIL: $SCRIPT not found or not executable" >&2
    exit 1
fi

TMPROOT="$(mktemp -d)" || exit 1

# --- Build a throwaway bare "origin" and a working clone -------------------

ORIGIN="$TMPROOT/origin.git"
git init -q --bare "$ORIGIN" >/dev/null 2>&1

REPO="$TMPROOT/repo"
git init -q "$REPO" >/dev/null 2>&1
cd "$REPO" || exit 1
git config user.name "$GIT_AUTHOR_NAME"
git config user.email "$GIT_AUTHOR_EMAIL"
git config commit.gpgsign false
git symbolic-ref HEAD refs/heads/main
git remote add origin "$ORIGIN"

echo "base" > README.md
git add -A >/dev/null 2>&1
git commit -q -m "initial" >/dev/null 2>&1
git push -q origin main >/dev/null 2>&1

# --- Scenario 1: neither local nor origin has it ---------------------------
OUT1="$("$SCRIPT" v9.9.9 2>/dev/null)"
STATUS1=$?
if [ "$STATUS1" -eq 1 ] && [ -z "$OUT1" ]; then
    pass "Scenario 1: not found locally or on origin -> exit 1, no stdout"
else
    fail "Scenario 1: not found locally or on origin -> exit 1, no stdout" \
        "exit: $STATUS1 (want 1)" "stdout: [$OUT1] (want empty)"
fi

# --- Scenario 2: local-only -------------------------------------------------
git branch milestone/v1.0.0 >/dev/null 2>&1
OUT2="$("$SCRIPT" v1.0.0 2>/dev/null)"
STATUS2=$?
if [ "$STATUS2" -eq 0 ] && [ "$OUT2" = "milestone/v1.0.0" ]; then
    pass "Scenario 2: local-only branch is found"
else
    fail "Scenario 2: local-only branch is found" \
        "exit: $STATUS2 (want 0)" "stdout: [$OUT2] (want milestone/v1.0.0)"
fi
git branch -D milestone/v1.0.0 >/dev/null 2>&1

# --- Scenario 3: origin-only ------------------------------------------------
git checkout -q -b milestone/v2.0.0 >/dev/null 2>&1
git push -q origin milestone/v2.0.0 >/dev/null 2>&1
git checkout -q main >/dev/null 2>&1
git branch -D milestone/v2.0.0 >/dev/null 2>&1
# Drop the stale local remote-tracking ref too, so this scenario genuinely
# exercises `git ls-remote` (the origin-only path) rather than a leftover
# refs/remotes/origin/milestone/v2.0.0 the script might read locally instead.
git update-ref -d refs/remotes/origin/milestone/v2.0.0 >/dev/null 2>&1

OUT3="$("$SCRIPT" v2.0.0 2>/dev/null)"
STATUS3=$?
if [ "$STATUS3" -eq 0 ] && [ "$OUT3" = "milestone/v2.0.0" ]; then
    pass "Scenario 3: origin-only branch is found via ls-remote (no local ref at all)"
else
    fail "Scenario 3: origin-only branch is found via ls-remote (no local ref at all)" \
        "exit: $STATUS3 (want 0)" "stdout: [$OUT3] (want milestone/v2.0.0)"
fi

# --- Scenario 4: both local and origin --------------------------------------
git checkout -q -b milestone/v3.0.0 >/dev/null 2>&1
git push -q origin milestone/v3.0.0 >/dev/null 2>&1
git checkout -q main >/dev/null 2>&1

OUT4="$("$SCRIPT" v3.0.0 2>/dev/null)"
STATUS4=$?
if [ "$STATUS4" -eq 0 ] && [ "$OUT4" = "milestone/v3.0.0" ]; then
    pass "Scenario 4: branch present both locally and on origin is found"
else
    fail "Scenario 4: branch present both locally and on origin is found" \
        "exit: $STATUS4 (want 0)" "stdout: [$OUT4] (want milestone/v3.0.0)"
fi

# --- Scenario 5: leading "milestone/" in the argument is accepted ----------
OUT5="$("$SCRIPT" milestone/v3.0.0 2>/dev/null)"
STATUS5=$?
if [ "$STATUS5" -eq 0 ] && [ "$OUT5" = "milestone/v3.0.0" ]; then
    pass "Scenario 5: a 'milestone/' prefix on the argument is stripped and re-applied"
else
    fail "Scenario 5: a 'milestone/' prefix on the argument is stripped and re-applied" \
        "exit: $STATUS5 (want 0)" "stdout: [$OUT5] (want milestone/v3.0.0)"
fi

# --- Scenario 6: usage errors ------------------------------------------------
"$SCRIPT" >/dev/null 2>&1
STATUS6="$?"
if [ "$STATUS6" -eq 2 ]; then
    pass "Scenario 6a: no argument -> exit 2 (usage)"
else
    fail "Scenario 6a: no argument -> exit 2 (usage)" "exit: $STATUS6 (want 2)"
fi

"$SCRIPT" v1 v2 >/dev/null 2>&1
STATUS6B="$?"
if [ "$STATUS6B" -eq 2 ]; then
    pass "Scenario 6b: two arguments -> exit 2 (usage)"
else
    fail "Scenario 6b: two arguments -> exit 2 (usage)" "exit: $STATUS6B (want 2)"
fi

harness_report
