#!/bin/bash
#
# Regression test for scripts/check-wip-markers.sh.
#
# finish-milestone.md Step 6 and review-milestone.md Step 1 both need this
# script's exit code and match list to agree exactly with what
# `grep -n "WIP:" docs/releases/RELEASE_NOTES_<version>.md` would show —
# this test pins the three cases those callers branch on: clean notes, a
# remaining marker, and a missing file.
#
# Runs entirely against a temporary release notes file under a scratch
# docs/releases/ directory this script creates and always cleans up (via a
# trap), never touching any real file under docs/releases/.
#
# Usage: bash tests/hooks/test-check-wip-markers.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

REPO_ROOT="$(git rev-parse --show-toplevel)"
SCRIPT="$REPO_ROOT/scripts/check-wip-markers.sh"

TESTS_RUN=0
TESTS_FAILED=0

TEST_VERSION="__test_wip_marker_$$"
NOTES_FILE="$REPO_ROOT/docs/releases/RELEASE_NOTES_${TEST_VERSION}.md"

cleanup() {
    rm -f "$NOTES_FILE"
}
trap cleanup EXIT

assert() {
    local desc="$1" expected_exit="$2" actual_exit="$3"
    TESTS_RUN=$((TESTS_RUN + 1))
    if [ "$expected_exit" -eq "$actual_exit" ]; then
        echo "PASS: $desc"
    else
        echo "FAIL: $desc (expected exit $expected_exit, got $actual_exit)"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

# --- Scenario 1: file missing -> exit 2 -------------------------------------
rm -f "$NOTES_FILE"
"$SCRIPT" "$TEST_VERSION" >/dev/null 2>&1
assert "missing release notes file exits 2" 2 "$?"

# --- Scenario 2: clean notes, no WIP markers -> exit 0 ----------------------
cat > "$NOTES_FILE" <<'EOF'
## Issues Resolved

- [#1](https://github.com/elan-registry/registry/issues/1) — Done thing
EOF
"$SCRIPT" "$TEST_VERSION" >/dev/null 2>&1
assert "clean release notes exits 0" 0 "$?"

# --- Scenario 3: one remaining WIP marker -> exit 1, printed ----------------
cat > "$NOTES_FILE" <<'EOF'
## Issues Resolved

- WIP: [#2](https://github.com/elan-registry/registry/issues/2) — Not done yet
EOF
OUTPUT="$("$SCRIPT" "$TEST_VERSION" 2>/dev/null)"
EXIT_CODE=$?
assert "remaining WIP marker exits 1" 1 "$EXIT_CODE"

TESTS_RUN=$((TESTS_RUN + 1))
if echo "$OUTPUT" | grep -q "WIP:"; then
    echo "PASS: remaining WIP marker is printed to stdout"
else
    echo "FAIL: remaining WIP marker was not printed to stdout"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# --- Report ------------------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
