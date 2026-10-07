#!/bin/bash
#
# Tests for scripts/fetch-pr-findings.sh: inline review comments are read
# from every page (--paginate) and combined into one JSON array.
#
# HERMETIC: a stub `gh` goes first on PATH. It answers only the calls the
# script makes and exits 99 on anything else. STUB_GH_FAIL=1 makes the inline
# comment call fail. No network calls.
#
# Usage: bash tests/hooks/test-fetch-pr-findings.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/fetch-pr-findings.sh"

TMPROOT="$(mktemp -d)" || exit 1

mkdir -p "$TMPROOT/bin"
cat > "$TMPROOT/bin/gh" <<'STUB'
#!/bin/bash
case "$*" in
  *"--json reviews,comments"*) echo '{"reviews":[],"comments":[]}' ;;
  "api --paginate repos/elan-registry/registry/pulls/9/comments --jq "*)
    [ "${STUB_GH_FAIL:-0}" = "1" ] && { echo "gh: simulated failure" >&2; exit 1; }
    # Two pages: --jq prints one object per line for each page.
    printf '%s\n' '{"path":"a.php","line":1,"body":"page one","user":"x"}'
    printf '%s\n' '{"path":"b.php","line":2,"body":"page two","user":"y"}' ;;
  *headRefOid*) echo abc123 ;;
  *check-runs*) : ;;
  *) echo "stub gh: unexpected argv: $*" >&2; exit 99 ;;
esac
STUB
chmod +x "$TMPROOT/bin/gh"

# --- Case 1: inline comments from two pages become one array ---------------
OUT1="$(PATH="$TMPROOT/bin:$PATH" "$SCRIPT" 9 2>&1)"
STATUS1=$?
N1="$(printf '%s' "$OUT1" | jq '.inline_comments | length' 2>/dev/null)"
B1="$(printf '%s' "$OUT1" | jq -r '.inline_comments[1].body' 2>/dev/null)"
if [ "$STATUS1" -eq 0 ] && [ "$N1" = "2" ] && [ "$B1" = "page two" ]; then
    pass "Case 1: inline comments from both pages are combined"
else
    fail "Case 1: inline comments from both pages are combined" "exit: $STATUS1" "output: [$OUT1]"
fi

# --- Case 2: the inline comment call fails -> exit 1 -----------------------
OUT2="$(STUB_GH_FAIL=1 PATH="$TMPROOT/bin:$PATH" "$SCRIPT" 9 2>&1)"
STATUS2=$?
if [ "$STATUS2" -eq 1 ] && printf '%s' "$OUT2" | grep -q 'pulls/comments failed'; then
    pass "Case 2: a failed inline comment call exits 1"
else
    fail "Case 2: a failed inline comment call exits 1" "exit: $STATUS2 (want 1)" "output: [$OUT2]"
fi

harness_report
