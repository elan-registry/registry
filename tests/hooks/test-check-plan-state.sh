#!/bin/bash
#
# Regression test for scripts/check-plan-state.sh.
#
# HERMETIC: builds a throwaway repo (mktemp -d) with its own
# docs/plans/issues/ directory and synthetic plan files, so real plan files
# under this checkout's (gitignored, private) docs/plans/ are never read or
# modified. See CLAUDE.md's Planning Work section for why docs/plans/ must
# stay untouched by anything other than the real workflow commands.
#
# Usage: bash tests/hooks/test-check-plan-state.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY

export GIT_AUTHOR_NAME="test-check-plan-state"
export GIT_AUTHOR_EMAIL="test-check-plan-state@localhost"
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME"
export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/check-plan-state.sh"
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

mkdir -p docs/plans/issues

write_plan() {
    local path="$1" status="$2" checked="$3" unchecked="$4"
    {
        echo "# Issue #999: Test"
        echo ""
        echo "**Status:** $status"
        echo ""
        echo "## Implementation Checklist"
        echo ""
        local i
        for ((i = 0; i < checked; i++)); do
            echo "- [x] done item $i"
        done
        for ((i = 0; i < unchecked; i++)); do
            echo "- [ ] pending item $i"
        done
    } > "$path"
}

# --- Scenario 1: approved plan, mixed checklist -----------------------------
git checkout -q -b issue/501-widget >/dev/null 2>&1
write_plan docs/plans/issues/issue-501-widget.md \
    "Approved — ready for /execute-plan" 2 1
OUT1="$("$SCRIPT" 2>/dev/null)"
STATUS1=$?
if [ "$STATUS1" -eq 0 ] \
    && printf '%s' "$OUT1" | grep -q "path: docs/plans/issues/issue-501-widget.md" \
    && printf '%s' "$OUT1" | grep -q "approved: yes" \
    && printf '%s' "$OUT1" | grep -q "checklist: 2/3"; then
    pass "Scenario 1: approved plan derived from branch name -> exit 0, correct fields"
else
    fail "Scenario 1: approved plan derived from branch name -> exit 0, correct fields" \
        "exit: $STATUS1 (want 0)" "output: [$OUT1]"
fi
git checkout -q main >/dev/null 2>&1
git branch -D issue/501-widget >/dev/null 2>&1
rm -f docs/plans/issues/issue-501-widget.md

# --- Scenario 1b: an item marked N/A counts as done --------------------------
git checkout -q -b issue/502-widget >/dev/null 2>&1
write_plan docs/plans/issues/issue-502-widget.md \
    "Approved — ready for /execute-plan" 1 1
echo "- [ ] not needed item — N/A: covered by the existing guard" >> docs/plans/issues/issue-502-widget.md
OUT1B="$("$SCRIPT" 2>/dev/null)"
if printf '%s' "$OUT1B" | grep -q "checklist: 2/3"; then
    pass "Scenario 1b: an N/A item counts as done -> checklist 2/3"
else
    fail "Scenario 1b: an N/A item counts as done -> checklist 2/3" "output: [$OUT1B]"
fi
git checkout -q main >/dev/null 2>&1
git branch -D issue/502-widget >/dev/null 2>&1
rm -f docs/plans/issues/issue-502-widget.md

# --- Scenario 2: draft (not approved) plan, explicit issue number -----------
write_plan docs/plans/issues/issue-502-gadget.md "Draft — pending approval" 0 4
OUT2="$("$SCRIPT" 502 2>/dev/null)"
STATUS2=$?
if [ "$STATUS2" -eq 2 ] \
    && printf '%s' "$OUT2" | grep -q "approved: no" \
    && printf '%s' "$OUT2" | grep -q "checklist: 0/4"; then
    pass "Scenario 2: draft plan with explicit issue number -> exit 2, approved: no"
else
    fail "Scenario 2: draft plan with explicit issue number -> exit 2, approved: no" \
        "exit: $STATUS2 (want 2)" "output: [$OUT2]"
fi
rm -f docs/plans/issues/issue-502-gadget.md

# --- Scenario 3: no plan file at all -----------------------------------------
OUT3="$("$SCRIPT" 777 2>"$TMPROOT/err3")"
STATUS3=$?
ERR3="$(cat "$TMPROOT/err3")"
if [ "$STATUS3" -eq 1 ] && printf '%s' "$OUT3" | grep -q "path: (none)" \
    && ! printf '%s' "$OUT3" | grep -q "worktree"; then
    pass "Scenario 3: no matching plan file -> exit 1, stdout fields only"
else
    fail "Scenario 3: no matching plan file -> exit 1, stdout fields only" \
        "exit: $STATUS3 (want 1)" "output: [$OUT3]"
fi
if printf '%s' "$ERR3" | grep -q "clone or worktree" \
    && printf '%s' "$ERR3" | grep -q "$REPO/docs/plans/"; then
    pass "Scenario 3b: no plan file -> stderr names the directory and the wrong-checkout cause"
else
    fail "Scenario 3b: no plan file -> stderr names the directory and the wrong-checkout cause" \
        "stderr: [$ERR3]"
fi

# --- Scenario 4: cannot derive issue number from a non-issue branch ---------
git checkout -q -b chore/cleanup >/dev/null 2>&1
"$SCRIPT" >/dev/null 2>&1
STATUS4=$?
if [ "$STATUS4" -eq 3 ]; then
    pass "Scenario 4: non-issue branch name, no argument -> exit 3"
else
    fail "Scenario 4: non-issue branch name, no argument -> exit 3" \
        "exit: $STATUS4 (want 3)"
fi
git checkout -q main >/dev/null 2>&1
git branch -D chore/cleanup >/dev/null 2>&1

# --- Scenario 5: bug/ and feature/ branch prefixes also derive the number --
git checkout -q -b bug/503-leak >/dev/null 2>&1
write_plan docs/plans/issues/issue-503-leak.md \
    "Approved — ready for /execute-plan" 1 0
OUT5="$("$SCRIPT" 2>/dev/null)"
STATUS5=$?
if [ "$STATUS5" -eq 0 ] && printf '%s' "$OUT5" | grep -q "checklist: 1/1"; then
    pass "Scenario 5: bug/NNN-slug branch derives the issue number"
else
    fail "Scenario 5: bug/NNN-slug branch derives the issue number" \
        "exit: $STATUS5 (want 0)" "output: [$OUT5]"
fi
git checkout -q main >/dev/null 2>&1
git branch -D bug/503-leak >/dev/null 2>&1
rm -f docs/plans/issues/issue-503-leak.md

# --- Scenario 6: usage error --------------------------------------------------
"$SCRIPT" 1 2 >/dev/null 2>&1
STATUS6=$?
if [ "$STATUS6" -eq 3 ]; then
    pass "Scenario 6: too many arguments -> exit 3 (usage)"
else
    fail "Scenario 6: too many arguments -> exit 3 (usage)" "exit: $STATUS6 (want 3)"
fi

harness_report
