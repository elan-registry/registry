#!/bin/bash
#
# Regression test for scripts/check-known-broken-tests.sh. finish-milestone.md
# and review-milestone.md branch on its exit code and per-match issue state.
#
# The script greps a relative `tests/` directory, so each scenario runs it
# from a scratch directory, never from this repo's real tests/ tree.
#
# Hermetic: a stub `gh` is first on PATH, so no call reaches GitHub (CI has
# no gh credentials). Stub: issue 1 closed, 2 open, 999999 lookup failure.
#
# Usage: bash tests/hooks/test-check-known-broken-tests.sh

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

REPO_ROOT="$(git rev-parse --show-toplevel)"
SCRIPT="$REPO_ROOT/scripts/check-known-broken-tests.sh"

SCRATCH_DIR="$(mktemp -d)"
STUB_BIN_DIR=""

# shellcheck disable=SC2329 # called only through the EXIT trap in lib/harness.sh
cleanup() {
    rm -rf "$SCRATCH_DIR"
    [ -n "$STUB_BIN_DIR" ] && rm -rf "$STUB_BIN_DIR"
}

mkdir -p "$SCRATCH_DIR/tests"

# --- Stub gh: answers `issue view <n> --repo ... --json state --jq .state` -
STUB_BIN_DIR="$(mktemp -d)"
cat > "$STUB_BIN_DIR/gh" <<'EOF'
#!/bin/bash
# Stub gh for test-check-known-broken-tests.sh. Only implements the one call
# scripts/check-known-broken-tests.sh makes:
#   gh issue view <n> --repo elan-registry/registry --json state --jq .state
if [ "$1" = "issue" ] && [ "$2" = "view" ]; then
    issue_num="$3"
    case "$issue_num" in
        1) echo "closed"; exit 0 ;;
        2) echo "open"; exit 0 ;;
        999999) exit 1 ;;
        *) exit 1 ;;
    esac
fi
exit 1
EOF
chmod +x "$STUB_BIN_DIR/gh"
export PATH="$STUB_BIN_DIR:$PATH"

assert() {
    local desc="$1" expected_exit="$2" actual_exit="$3"
    if [ "$expected_exit" -eq "$actual_exit" ]; then
        pass "$desc"
    else
        fail "$desc" "expected exit $expected_exit, got $actual_exit"
    fi
}

# --- Scenario 1: no tags present -> exit 0, no output -----------------------
: > "$SCRATCH_DIR/tests/PlainTest.php"
OUTPUT="$(cd "$SCRATCH_DIR" && "$SCRIPT" 2>/dev/null)"
EXIT_CODE=$?
assert "no known-broken tags exits 0" 0 "$EXIT_CODE"

if [ -z "$OUTPUT" ]; then
    pass "no tags produces no output"
else
    fail "no tags produces no output" "expected no output, got: $OUTPUT"
fi

# --- Scenario 2: tag citing an issue number -> exit 1, state looked up ------
# Issue 1 is stubbed as closed.
cat > "$SCRATCH_DIR/tests/TaggedTest.php" <<'EOF'
<?php
// #1 — fails on Linux CI, root cause under investigation
#[Group('known-broken')]
public function testSomething(): void {}
EOF
OUTPUT="$(cd "$SCRATCH_DIR" && "$SCRIPT" 2>/dev/null)"
EXIT_CODE=$?
assert "tagged test citing an issue exits 1" 1 "$EXIT_CODE"

if printf '%s' "$OUTPUT" | grep -qEi $'^tests/TaggedTest\\.php\t[0-9]+\t1\tclosed$'; then
    pass "match line reports file, line, issue number, and looked-up state"
else
    fail "match line reports file, line, issue number, and looked-up state" "unexpected match line format: $OUTPUT"
fi

# --- Scenario 3: tag with no issue number cited -----------------------------
rm -f "$SCRATCH_DIR/tests/TaggedTest.php"
cat > "$SCRATCH_DIR/tests/UncitedTest.php" <<'EOF'
<?php
// no issue cited here
#[Group('known-broken')]
public function testSomething(): void {}
EOF
OUTPUT="$(cd "$SCRATCH_DIR" && "$SCRIPT" 2>/dev/null)"
EXIT_CODE=$?
assert "tagged test with no issue number exits 1" 1 "$EXIT_CODE"

if printf '%s' "$OUTPUT" | grep -qE $'^tests/UncitedTest\\.php\t[0-9]+\t\\(none\\)\t\\(unknown\\)$'; then
    pass "uncited match reports (none)/(unknown)"
else
    fail "uncited match reports (none)/(unknown)" "unexpected match line format: $OUTPUT"
fi

# --- Scenario 4: gh lookup fails -> exit 2, state (lookup-failed) -----------
# Issue 999999 is stubbed to fail, simulating an auth/network/rate-limit
# error from the real `gh issue view`.
rm -f "$SCRATCH_DIR/tests/UncitedTest.php"
cat > "$SCRATCH_DIR/tests/LookupFailedTest.php" <<'EOF'
<?php
// #999999 — fails on Linux CI, root cause under investigation
#[Group('known-broken')]
public function testSomething(): void {}
EOF
OUTPUT="$(cd "$SCRATCH_DIR" && "$SCRIPT" 2>/dev/null)"
EXIT_CODE=$?
assert "gh lookup failure exits 2" 2 "$EXIT_CODE"

if printf '%s' "$OUTPUT" | grep -qE $'^tests/LookupFailedTest\\.php\t[0-9]+\t999999\t\\(lookup-failed\\)$'; then
    pass "failed lookup reports (lookup-failed)"
else
    fail "failed lookup reports (lookup-failed)" "unexpected match line format: $OUTPUT"
fi

harness_report
