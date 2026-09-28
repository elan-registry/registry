#!/bin/bash
#
# Regression test for scripts/check-known-broken-tests.sh.
#
# finish-milestone.md Step 3.5 and review-milestone.md Step 2 both need this
# script's exit code and per-match issue-state lookup to agree — this test
# pins the three cases those callers branch on: no tags present, a tag
# citing an issue, and a tag with no issue number in it.
#
# The script greps a relative `tests/` directory, so this test builds a
# throwaway scratch directory with its own tests/ subtree and runs the
# script with that directory as the working directory — it never touches
# this repo's real tests/ tree. Always cleaned up via a trap.
#
# Hermetic: a stub `gh` is put first on PATH before any scenario runs, so
# every `gh issue view` call in this test is answered locally — none reaches
# the real GitHub API. A GitHub-hosted CI runner has no gh credentials, so a
# real call would fail there. The stub answers issue 1 as closed, issue 2 as
# open, and issue 999999 as a lookup failure (exit 1, no output), matching
# the shape scripts/check-known-broken-tests.sh expects from
# `gh issue view <n> --repo elan-registry/registry --json state --jq .state`.
#
# Usage: bash tests/hooks/test-check-known-broken-tests.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

REPO_ROOT="$(git rev-parse --show-toplevel)"
SCRIPT="$REPO_ROOT/scripts/check-known-broken-tests.sh"

TESTS_RUN=0
TESTS_FAILED=0

SCRATCH_DIR="$(mktemp -d)"
STUB_BIN_DIR=""

cleanup() {
    rm -rf "$SCRATCH_DIR"
    [ -n "$STUB_BIN_DIR" ] && rm -rf "$STUB_BIN_DIR"
}
trap cleanup EXIT

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
    TESTS_RUN=$((TESTS_RUN + 1))
    if [ "$expected_exit" -eq "$actual_exit" ]; then
        echo "PASS: $desc"
    else
        echo "FAIL: $desc (expected exit $expected_exit, got $actual_exit)"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

# --- Scenario 1: no tags present -> exit 0, no output -----------------------
: > "$SCRATCH_DIR/tests/PlainTest.php"
OUTPUT="$(cd "$SCRATCH_DIR" && "$SCRIPT" 2>/dev/null)"
EXIT_CODE=$?
assert "no known-broken tags exits 0" 0 "$EXIT_CODE"

TESTS_RUN=$((TESTS_RUN + 1))
if [ -z "$OUTPUT" ]; then
    echo "PASS: no tags produces no output"
else
    echo "FAIL: expected no output, got: $OUTPUT"
    TESTS_FAILED=$((TESTS_FAILED + 1))
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

TESTS_RUN=$((TESTS_RUN + 1))
if printf '%s' "$OUTPUT" | grep -qEi $'^tests/TaggedTest\\.php\t[0-9]+\t1\tclosed$'; then
    echo "PASS: match line reports file, line, issue number, and looked-up state"
else
    echo "FAIL: unexpected match line format: $OUTPUT"
    TESTS_FAILED=$((TESTS_FAILED + 1))
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

TESTS_RUN=$((TESTS_RUN + 1))
if printf '%s' "$OUTPUT" | grep -qE $'^tests/UncitedTest\\.php\t[0-9]+\t\\(none\\)\t\\(unknown\\)$'; then
    echo "PASS: uncited match reports (none)/(unknown)"
else
    echo "FAIL: unexpected match line format: $OUTPUT"
    TESTS_FAILED=$((TESTS_FAILED + 1))
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

TESTS_RUN=$((TESTS_RUN + 1))
if printf '%s' "$OUTPUT" | grep -qE $'^tests/LookupFailedTest\\.php\t[0-9]+\t999999\t\\(lookup-failed\\)$'; then
    echo "PASS: failed lookup reports (lookup-failed)"
else
    echo "FAIL: unexpected match line format: $OUTPUT"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# --- Report ------------------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
