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

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

REPO_ROOT="$(git rev-parse --show-toplevel)"
SCRIPT="$REPO_ROOT/scripts/check-wip-markers.sh"

TEST_VERSION="__test_wip_marker_$$"
NOTES_FILE="$REPO_ROOT/docs/releases/RELEASE_NOTES_${TEST_VERSION}.md"

# shellcheck disable=SC2329 # called only through the EXIT trap in lib/harness.sh
cleanup() {
    rm -f "$NOTES_FILE"
}

assert() {
    local desc="$1" expected_exit="$2" actual_exit="$3"
    if [ "$expected_exit" -eq "$actual_exit" ]; then
        pass "$desc"
    else
        fail "$desc" "expected exit $expected_exit, got $actual_exit"
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

if echo "$OUTPUT" | grep -q "WIP:"; then
    pass "remaining WIP marker is printed to stdout"
else
    fail "remaining WIP marker is printed to stdout" "output: [$OUTPUT]"
fi

harness_report
