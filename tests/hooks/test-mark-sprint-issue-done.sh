#!/bin/bash
#
# Regression test for scripts/mark-sprint-issue-done.sh.
#
# HERMETIC: builds a throwaway repo (mktemp -d) with its own
# docs/plans/sprints/ directory and a synthetic sprint file, so real sprint
# plan files under this checkout's (gitignored, private) docs/plans/ are
# never read or modified.
#
# Usage: bash tests/hooks/test-mark-sprint-issue-done.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/mark-sprint-issue-done.sh"
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

cd "$TMPROOT" || exit 1
mkdir -p docs/plans/sprints

reset_sprint_file() {
    printf '%s\n' "**#1591 → #1547 → #1438 → #1439**" > docs/plans/sprints/v9.9.9.md
}

# --- Scenario 1: mark an unmarked issue in the middle of the sequence -------
reset_sprint_file
"$SCRIPT" v9.9.9 1547 >/dev/null 2>&1
STATUS1=$?
CONTENT1="$(cat docs/plans/sprints/v9.9.9.md)"
if [ "$STATUS1" -eq 0 ] && [ "$CONTENT1" = "**#1591 → ✅#1547 → #1438 → #1439**" ]; then
    pass "Scenario 1: marks the target issue, leaves the rest of the line untouched"
else
    fail "Scenario 1: marks the target issue, leaves the rest of the line untouched" \
        "exit: $STATUS1 (want 0)" "content: [$CONTENT1]"
fi

# --- Scenario 2: idempotent on an already-marked issue ----------------------
"$SCRIPT" v9.9.9 1547 >/dev/null 2>&1
STATUS2=$?
CONTENT2="$(cat docs/plans/sprints/v9.9.9.md)"
if [ "$STATUS2" -eq 0 ] && [ "$CONTENT2" = "$CONTENT1" ]; then
    pass "Scenario 2: re-running on an already-marked issue is a no-op, still exit 0"
else
    fail "Scenario 2: re-running on an already-marked issue is a no-op, still exit 0" \
        "exit: $STATUS2 (want 0)" "content: [$CONTENT2] (want unchanged: [$CONTENT1])"
fi

# --- Scenario 3: the first issue in the sequence ----------------------------
reset_sprint_file
"$SCRIPT" v9.9.9 1591 >/dev/null 2>&1
STATUS3=$?
CONTENT3="$(cat docs/plans/sprints/v9.9.9.md)"
if [ "$STATUS3" -eq 0 ] && [ "$CONTENT3" = "**✅#1591 → #1547 → #1438 → #1439**" ]; then
    pass "Scenario 3: marks the first issue in the sequence"
else
    fail "Scenario 3: marks the first issue in the sequence" \
        "exit: $STATUS3 (want 0)" "content: [$CONTENT3]"
fi

# --- Scenario 4: the last issue in the sequence -----------------------------
reset_sprint_file
"$SCRIPT" v9.9.9 1439 >/dev/null 2>&1
STATUS4=$?
CONTENT4="$(cat docs/plans/sprints/v9.9.9.md)"
if [ "$STATUS4" -eq 0 ] && [ "$CONTENT4" = "**#1591 → #1547 → #1438 → ✅#1439**" ]; then
    pass "Scenario 4: marks the last issue in the sequence"
else
    fail "Scenario 4: marks the last issue in the sequence" \
        "exit: $STATUS4 (want 0)" "content: [$CONTENT4]"
fi

# --- Scenario 5: issue number not a substring collision ---------------------
# #1439 vs #143 (a shorter number sharing a prefix) must not cross-match.
reset_sprint_file
"$SCRIPT" v9.9.9 143 >/dev/null 2>&1
STATUS5=$?
if [ "$STATUS5" -eq 2 ]; then
    pass "Scenario 5: a numeric substring of a real issue number is not falsely matched"
else
    fail "Scenario 5: a numeric substring of a real issue number is not falsely matched" \
        "exit: $STATUS5 (want 2)"
fi

# --- Scenario 6: issue not in the sequence at all ---------------------------
reset_sprint_file
"$SCRIPT" v9.9.9 9999 >/dev/null 2>&1
STATUS6=$?
CONTENT6="$(cat docs/plans/sprints/v9.9.9.md)"
if [ "$STATUS6" -eq 2 ] && [ "$CONTENT6" = "**#1591 → #1547 → #1438 → #1439**" ]; then
    pass "Scenario 6: issue absent from the sequence -> exit 2, file untouched"
else
    fail "Scenario 6: issue absent from the sequence -> exit 2, file untouched" \
        "exit: $STATUS6 (want 2)" "content: [$CONTENT6]"
fi

# --- Scenario 7: sprint file absent is normal, not an error -----------------
rm -f docs/plans/sprints/v0.0.0.md
"$SCRIPT" v0.0.0 1591 >/dev/null 2>&1
STATUS7=$?
if [ "$STATUS7" -eq 1 ]; then
    pass "Scenario 7: missing sprint file -> exit 1 (normal, not blocking)"
else
    fail "Scenario 7: missing sprint file -> exit 1 (normal, not blocking)" \
        "exit: $STATUS7 (want 1)"
fi

# --- Scenario 8: usage errors ------------------------------------------------
"$SCRIPT" v9.9.9 >/dev/null 2>&1
STATUS8A=$?
if [ "$STATUS8A" -eq 2 ]; then
    pass "Scenario 8a: missing issue-number argument -> exit 2 (usage)"
else
    fail "Scenario 8a: missing issue-number argument -> exit 2 (usage)" "exit: $STATUS8A (want 2)"
fi

"$SCRIPT" v9.9.9 abc >/dev/null 2>&1
STATUS8B=$?
if [ "$STATUS8B" -eq 2 ]; then
    pass "Scenario 8b: non-numeric issue number -> exit 2 (usage)"
else
    fail "Scenario 8b: non-numeric issue number -> exit 2 (usage)" "exit: $STATUS8B (want 2)"
fi

# --- Report ------------------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
