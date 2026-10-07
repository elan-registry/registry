#!/bin/bash
#
# Regression test for scripts/check-review-posted.sh (#2314): the script
# must count the Strengths-anchored review comments on all pages of PR
# comments, not only the first page of 30. It must also keep its exit-code
# contract: exit 0 with a count on stdout, or exit 1 with nothing on stdout
# when the gh call fails.
#
# HERMETIC: a stub `gh` goes first on PATH. The stub ignores --jq and prints
# what the script's filter would print for each page: one comment ID per
# line. Page 1 is STUB_PAGE1. Page 2 is STUB_PAGE2, and the stub prints it
# only when --paginate is given, as real gh does. STUB_GH_FAIL=1 makes the
# call fail.
#
# Usage: bash tests/hooks/test-check-review-posted.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
CHECK_SCRIPT="$REAL_REPO/scripts/check-review-posted.sh"
if [ ! -x "$CHECK_SCRIPT" ]; then
    echo "FAIL: $CHECK_SCRIPT not found or not executable" >&2
    exit 1
fi

TMPROOT="$(mktemp -d)" || exit 1

STUBDIR="$TMPROOT/bin"
mkdir -p "$STUBDIR"
cat > "$STUBDIR/gh" <<'STUB'
#!/bin/bash
if [ "${STUB_GH_FAIL:-0}" = "1" ]; then
    echo "gh: simulated API failure (stub)" >&2
    exit 1
fi
paginate=0
for arg in "$@"; do
    [ "$arg" = "--paginate" ] && paginate=1
done
[ -n "${STUB_PAGE1:-}" ] && printf '%s\n' "$STUB_PAGE1"
if [ "$paginate" = "1" ] && [ -n "${STUB_PAGE2:-}" ]; then
    printf '%s\n' "$STUB_PAGE2"
fi
exit 0
STUB
chmod +x "$STUBDIR/gh"

# --- Case 1: no review comment -> prints 0, exit 0 -------------------------
OUT1="$(STUB_PAGE1="" PATH="$STUBDIR:$PATH" "$CHECK_SCRIPT" 1 2>/dev/null)"
STATUS1=$?
if [ "$STATUS1" -eq 0 ] && [ "$OUT1" = "0" ]; then
    pass "Case 1: no review comment -> prints 0, exit 0"
else
    fail "Case 1: no review comment -> prints 0, exit 0" \
        "exit: $STATUS1 (want 0)" "stdout: [$OUT1] (want [0])"
fi

# --- Case 2: reviews on page 1 and page 2 -> counts all of them ------------
# Without --paginate the script sees only page 1 and prints 2.
OUT2="$(STUB_PAGE1=$'101\n102' STUB_PAGE2='205' PATH="$STUBDIR:$PATH" "$CHECK_SCRIPT" 1 2>/dev/null)"
STATUS2=$?
if [ "$STATUS2" -eq 0 ] && [ "$OUT2" = "3" ]; then
    pass "Case 2: reviews on two pages -> prints 3, exit 0"
else
    fail "Case 2: reviews on two pages -> prints 3, exit 0" \
        "exit: $STATUS2 (want 0)" "stdout: [$OUT2] (want [3])"
fi

# --- Case 3: the only review is on page 2 -> prints 1 ----------------------
# A PR with more than 30 comments can have its first review after page 1.
OUT3="$(STUB_PAGE1="" STUB_PAGE2='205' PATH="$STUBDIR:$PATH" "$CHECK_SCRIPT" 1 2>/dev/null)"
STATUS3=$?
if [ "$STATUS3" -eq 0 ] && [ "$OUT3" = "1" ]; then
    pass "Case 3: only review on page 2 -> prints 1, exit 0"
else
    fail "Case 3: only review on page 2 -> prints 1, exit 0" \
        "exit: $STATUS3 (want 0)" "stdout: [$OUT3] (want [1])"
fi

# --- Case 4: gh call fails -> exit 1, nothing on stdout --------------------
OUT4="$(STUB_GH_FAIL=1 PATH="$STUBDIR:$PATH" "$CHECK_SCRIPT" 1 2>"$TMPROOT/err4")"
STATUS4=$?
ERR4="$(cat "$TMPROOT/err4")"
if [ "$STATUS4" -eq 1 ] && [ -z "$OUT4" ] && [[ "$ERR4" == *"simulated API failure"* ]]; then
    pass "Case 4: gh call fails -> exit 1, empty stdout, gh error on stderr"
else
    fail "Case 4: gh call fails -> exit 1, empty stdout, gh error on stderr" \
        "exit: $STATUS4 (want 1)" "stdout: [$OUT4] (want empty)" "stderr: [$ERR4]"
fi

harness_report
