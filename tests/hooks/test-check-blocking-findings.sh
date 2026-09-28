#!/bin/bash
#
# Regression test for scripts/check-blocking-findings.sh and
# scripts/verify-ci-review.sh (#2222): a clean review (no Blocking heading at
# all) must exit 0, not fail under `pipefail`/`set -e` before it can print
# anything, and a caller's `if ! poll; then poll_status=$?` pattern must not
# silently store the negated (always-0) exit status.
#
# HERMETIC: Part A stubs `gh` (and, for case 16, `grep`) first on PATH. Part B
# runs a copy of verify-ci-review.sh from a directory that also holds stub
# poll-review-posted.sh and check-blocking-findings.sh siblings, which the
# copy finds through its own SCRIPT_DIR. Part B also puts a logging `gh` stub
# first on PATH for the recovery path. Never calls the network or the real
# `gh`.
#
# Usage: bash tests/hooks/test-check-blocking-findings.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
CHECK_SCRIPT="$REAL_REPO/scripts/check-blocking-findings.sh"
VERIFY_SCRIPT="$REAL_REPO/scripts/verify-ci-review.sh"
if [ ! -x "$CHECK_SCRIPT" ]; then
    echo "FAIL: $CHECK_SCRIPT not found or not executable" >&2
    exit 1
fi
if [ ! -x "$VERIFY_SCRIPT" ]; then
    echo "FAIL: $VERIFY_SCRIPT not found or not executable" >&2
    exit 1
fi

TMPROOT="$(mktemp -d)" || exit 1
# shellcheck disable=SC2329 # called only through the EXIT trap below
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

# =========================================================================
# Part A: scripts/check-blocking-findings.sh
# =========================================================================

STUBDIR="$TMPROOT/bin"
mkdir -p "$STUBDIR"
STUB_BODY_FILE="$TMPROOT/body.txt"
export STUB_BODY_FILE

# Stub `gh`: ignores its arguments (the real script's --jq filter selects
# the last Strengths-anchored comment's body; the stub just hands back
# whatever body this scenario staged). STUB_GH_FAIL=1 simulates an API
# failure (auth/network/rate-limit).
cat > "$STUBDIR/gh" <<'STUB'
#!/bin/bash
if [ "${STUB_GH_FAIL:-0}" = "1" ]; then
    echo "gh: simulated API failure (stub)" >&2
    exit 1
fi
cat "$STUB_BODY_FILE" 2>/dev/null
exit 0
STUB
chmod +x "$STUBDIR/gh"

run_check() {
    local body="$1"
    shift
    printf '%s' "$body" > "$STUB_BODY_FILE"
    PATH="$STUBDIR:$PATH" "$CHECK_SCRIPT" "$@"
}

# --- Case 1: clean review (Strengths + Suggestions, no Blocking) -> 0 -----
# This is the #2222 regression: under the old code, a clean review's first
# grep found nothing, exited 1, and `pipefail`/`set -e` stopped the script
# before it reached `exit 0`.
BODY1="### Strengths
Good test coverage.

### Suggestions
Consider renaming this variable."
OUT1="$(run_check "$BODY1" 1 2>&1)"
STATUS1=$?
if [ "$STATUS1" -eq 0 ]; then
    pass "Case 1: clean review (Strengths + Suggestions, no Blocking) -> exit 0"
else
    fail "Case 1: clean review (Strengths + Suggestions, no Blocking) -> exit 0" \
        "exit: $STATUS1 (want 0)" "output: [$OUT1]"
fi

# --- Case 2: live "### Blocking" section -> 1, stdout has the heading -----
BODY2="### Strengths
Fine.

### Blocking
- SQL injection in the search form."
OUT2="$(run_check "$BODY2" 1 2>&1)"
STATUS2=$?
if [ "$STATUS2" -eq 1 ] && printf '%s' "$OUT2" | grep -q '### Blocking'; then
    pass "Case 2: a live '### Blocking' section -> exit 1, output shows the heading"
else
    fail "Case 2: a live '### Blocking' section -> exit 1, output shows the heading" \
        "exit: $STATUS2 (want 1)" "output: [$OUT2]"
fi

# --- Case 3: "**Blocking**" bold form -> 1 --------------------------------
BODY3="### Strengths
Fine.

**Blocking**
- Missing CSRF check."
OUT3="$(run_check "$BODY3" 1 2>&1)"
STATUS3=$?
if [ "$STATUS3" -eq 1 ]; then
    pass "Case 3: '**Blocking**' bold form -> exit 1"
else
    fail "Case 3: '**Blocking**' bold form -> exit 1" "exit: $STATUS3 (want 1)" "output: [$OUT3]"
fi

# --- Case 4: "### Blocking findings" (trailing words) -> 1 ----------------
BODY4="### Strengths
Fine.

### Blocking findings
- Unescaped output in the view."
OUT4="$(run_check "$BODY4" 1 2>&1)"
STATUS4=$?
if [ "$STATUS4" -eq 1 ]; then
    pass "Case 4: '### Blocking findings' (trailing words) -> exit 1"
else
    fail "Case 4: '### Blocking findings' (trailing words) -> exit 1" \
        "exit: $STATUS4 (want 1)" "output: [$OUT4]"
fi

# --- Case 5: recap heading only -> 0 --------------------------------------
BODY5="### Strengths
Fine.

### Blocking finding from the previous round: resolved
Fixed in the latest commit."
OUT5="$(run_check "$BODY5" 1 2>&1)"
STATUS5=$?
if [ "$STATUS5" -eq 0 ]; then
    pass "Case 5: a recap-only heading is excluded -> exit 0"
else
    fail "Case 5: a recap-only heading is excluded -> exit 0" \
        "exit: $STATUS5 (want 0)" "output: [$OUT5]"
fi

# --- Case 6: "### Important" section, flag on/off -------------------------
BODY6="### Strengths
Fine.

### Important
- Consider adding a rate limit."
OUT6A="$(run_check "$BODY6" 1 2>&1)"
STATUS6A=$?
if [ "$STATUS6A" -eq 0 ]; then
    pass "Case 6a: '### Important' section without --include-important -> exit 0"
else
    fail "Case 6a: '### Important' section without --include-important -> exit 0" \
        "exit: $STATUS6A (want 0)" "output: [$OUT6A]"
fi

OUT6B="$(run_check "$BODY6" 1 --include-important 2>&1)"
STATUS6B=$?
if [ "$STATUS6B" -eq 1 ]; then
    pass "Case 6b: '### Important' section with --include-important -> exit 1"
else
    fail "Case 6b: '### Important' section with --include-important -> exit 1" \
        "exit: $STATUS6B (want 1)" "output: [$OUT6B]"
fi

# --- Case 7: empty body file (no Strengths-anchored comment) -> 2 --------
OUT7="$(run_check "" 1 2>&1)"
STATUS7=$?
if [ "$STATUS7" -eq 2 ]; then
    pass "Case 7: empty body (no Strengths-anchored comment found) -> exit 2"
else
    fail "Case 7: empty body (no Strengths-anchored comment found) -> exit 2" \
        "exit: $STATUS7 (want 2)" "output: [$OUT7]"
fi

# --- Case 8: gh api call fails -> 2 ---------------------------------------
: > "$STUB_BODY_FILE"
OUT8="$(STUB_GH_FAIL=1 PATH="$STUBDIR:$PATH" "$CHECK_SCRIPT" 1 2>&1)"
STATUS8=$?
if [ "$STATUS8" -eq 2 ]; then
    pass "Case 8: gh api call fails -> exit 2"
else
    fail "Case 8: gh api call fails -> exit 2" "exit: $STATUS8 (want 2)" "output: [$OUT8]"
fi

# --- Case 9: no arguments -> 2 ---------------------------------------------
OUT9="$(PATH="$STUBDIR:$PATH" "$CHECK_SCRIPT" 2>&1)"
STATUS9=$?
if [ "$STATUS9" -eq 2 ]; then
    pass "Case 9: no arguments -> exit 2 (usage)"
else
    fail "Case 9: no arguments -> exit 2 (usage)" "exit: $STATUS9 (want 2)" "output: [$OUT9]"
fi

# --- Case 16: real grep error -> 2, stderr mentions "cannot verify" -------
# Resolve the real grep's path BEFORE the stub dir goes on PATH, so the stub
# can exec it for any invocation other than the one it means to fail.
REAL_GREP="$(command -v grep)" || REAL_GREP=""
if [ -z "$REAL_GREP" ]; then
    echo "FAIL: cannot resolve the real grep for the case 16 stubs" >&2
    exit 1
fi
GREPSTUBDIR="$TMPROOT/grepstub"
mkdir -p "$GREPSTUBDIR"
cat > "$GREPSTUBDIR/grep" <<STUB
#!/bin/bash
if [ "\$1" = "\${STUB_GREP_FAIL_ON:--E}" ]; then
    # Read all input first. A stub that exits at once gives the stage before
    # it SIGPIPE, and that stage's own guard would then report the error, so
    # the test would never reach this stage's guard.
    cat > /dev/null
    exit 2
fi
exec "$REAL_GREP" "\$@"
STUB
chmod +x "$GREPSTUBDIR/grep"
BODY16="### Strengths
Fine.

### Blocking
- Something."
printf '%s' "$BODY16" > "$STUB_BODY_FILE"
OUT16="$(PATH="$GREPSTUBDIR:$STUBDIR:$PATH" "$CHECK_SCRIPT" 1 2>&1)"
STATUS16=$?
if [ "$STATUS16" -eq 2 ] && printf '%s' "$OUT16" | grep -q 'grep failed while scanning'; then
    pass "Case 16: first grep error -> exit 2, stderr says 'grep failed while scanning'"
else
    fail "Case 16: first grep error -> exit 2, stderr says 'grep failed while scanning'" \
        "exit: $STATUS16 (want 2)" "output: [$OUT16]"
fi

# --- Case 16b: second grep (-viE, the recap exclusion) error -> 2 --------
# The body has a live heading, so the first grep matches and the error comes
# from the second grep. A `|| true` guard there would report "clean" (0).
printf '%s' "$BODY16" > "$STUB_BODY_FILE"
OUT16B="$(STUB_GREP_FAIL_ON=-viE PATH="$GREPSTUBDIR:$STUBDIR:$PATH" "$CHECK_SCRIPT" 1 2>&1)"
STATUS16B=$?
if [ "$STATUS16B" -eq 2 ] && printf '%s' "$OUT16B" | grep -q 'grep failed while scanning'; then
    pass "Case 16b: second grep error -> exit 2, stderr says 'grep failed while scanning'"
else
    fail "Case 16b: second grep error -> exit 2, stderr says 'grep failed while scanning'" \
        "exit: $STATUS16B (want 2)" "output: [$OUT16B]"
fi

# --- Case 17: recap heading AND live heading in one comment (#1843) -------
BODY17="### Strengths
Fine.

### Blocking finding from the previous round: resolved
Fixed already.

### Blocking
- new bug"
OUT17="$(run_check "$BODY17" 1 2>&1)"
STATUS17=$?
if [ "$STATUS17" -eq 1 ] \
    && printf '%s\n' "$OUT17" | grep -qFx '### Blocking' \
    && ! printf '%s' "$OUT17" | grep -q 'previous round'; then
    pass "Case 17: recap heading + live heading (#1843) -> exit 1, live heading shown, recap excluded"
else
    fail "Case 17: recap heading + live heading (#1843) -> exit 1, live heading shown, recap excluded" \
        "exit: $STATUS17 (want 1)" "output: [$OUT17]"
fi

# --- Case 18: "### Blocking issues, unresolved" -> 1 (narrow exclusion) ---
BODY18="### Strengths
Fine.

### Blocking issues, unresolved
- Still broken."
OUT18="$(run_check "$BODY18" 1 2>&1)"
STATUS18=$?
if [ "$STATUS18" -eq 1 ]; then
    pass "Case 18: '### Blocking issues, unresolved' -> exit 1 (narrow exclusion doesn't drop it)"
else
    fail "Case 18: '### Blocking issues, unresolved' -> exit 1 (narrow exclusion doesn't drop it)" \
        "exit: $STATUS18 (want 1)" "output: [$OUT18]"
fi

# --- Case 19: "#### Blocking" (level 4 heading) -> 1 -----------------------
BODY19="### Strengths
Fine.

#### Blocking
- Deep heading bug."
OUT19="$(run_check "$BODY19" 1 2>&1)"
STATUS19=$?
if [ "$STATUS19" -eq 1 ]; then
    pass "Case 19: '#### Blocking' (level 4) -> exit 1"
else
    fail "Case 19: '#### Blocking' (level 4) -> exit 1" "exit: $STATUS19 (want 1)" "output: [$OUT19]"
fi

# =========================================================================
# Part B: scripts/verify-ci-review.sh
# =========================================================================

VSCRIPTS="$TMPROOT/vscripts"
mkdir -p "$VSCRIPTS"
cp "$VERIFY_SCRIPT" "$VSCRIPTS/verify-ci-review.sh"
chmod +x "$VSCRIPTS/verify-ci-review.sh"

POLL_COUNT_FILE="$TMPROOT/poll-count"
GH_LOG="$TMPROOT/gh.log"
CHECK_ARGS_LOG="$TMPROOT/check-args.log"
export CHECK_ARGS_LOG

# Stub poll-review-posted.sh: first call exits STUB_POLL_EXIT; if
# STUB_POLL2_EXIT is set, the second call uses that code instead. Calls are
# tracked with a counter file so the stub itself doesn't need any state
# beyond a plain integer.
cat > "$VSCRIPTS/poll-review-posted.sh" <<'STUB'
#!/bin/bash
COUNT_FILE="${STUB_POLL_COUNT_FILE:?STUB_POLL_COUNT_FILE not set}"
n=0
[ -f "$COUNT_FILE" ] && n="$(cat "$COUNT_FILE")"
n=$((n + 1))
printf '%s' "$n" > "$COUNT_FILE"
if [ "$n" -ge 2 ] && [ -n "${STUB_POLL2_EXIT:-}" ]; then
    exit "$STUB_POLL2_EXIT"
fi
exit "${STUB_POLL_EXIT:-0}"
STUB
chmod +x "$VSCRIPTS/poll-review-posted.sh"

# Stub check-blocking-findings.sh: logs each argument as `[arg]` on its own
# line to CHECK_ARGS_LOG, then exits STUB_CHECK_EXIT. The brackets keep an
# empty argument visible: `$(cat …)` would drop a bare trailing empty line.
cat > "$VSCRIPTS/check-blocking-findings.sh" <<'STUB'
#!/bin/bash
if [ -n "${CHECK_ARGS_LOG:-}" ]; then
    printf '[%s]\n' "$@" > "$CHECK_ARGS_LOG"
fi
exit "${STUB_CHECK_EXIT:-0}"
STUB
chmod +x "$VSCRIPTS/check-blocking-findings.sh"

# Stub `gh` for the recovery path: logs its arguments, exits 0. `gh pr diff`
# prints nothing so the self-referential-workflow-file check never matches;
# `gh workflow run` and `gh pr edit` just succeed.
cat > "$STUBDIR/gh" <<'STUB'
#!/bin/bash
printf '%s\n' "gh $*" >> "${STUB_GH_LOG:?STUB_GH_LOG not set}"
exit 0
STUB
chmod +x "$STUBDIR/gh"

run_verify() {
    rm -f "$POLL_COUNT_FILE"
    : > "$GH_LOG"
    : > "$CHECK_ARGS_LOG"
    STUB_POLL_COUNT_FILE="$POLL_COUNT_FILE" STUB_GH_LOG="$GH_LOG" \
        PATH="$STUBDIR:$PATH" \
        bash "$VSCRIPTS/verify-ci-review.sh" 1 1 1 --trigger=workflow "$@"
}

# --- Case 10: poll 0, check 0 -> 0 -----------------------------------------
OUT10="$(STUB_POLL_EXIT=0 STUB_CHECK_EXIT=0 run_verify 2>&1)"
STATUS10=$?
if [ "$STATUS10" -eq 0 ]; then
    pass "Case 10: poll 0, check 0 -> exit 0"
else
    fail "Case 10: poll 0, check 0 -> exit 0" "exit: $STATUS10 (want 0)" "output: [$OUT10]"
fi

# --- Case 11: poll 0, check 1 -> 2 -----------------------------------------
OUT11="$(STUB_POLL_EXIT=0 STUB_CHECK_EXIT=1 run_verify 2>&1)"
STATUS11=$?
if [ "$STATUS11" -eq 2 ]; then
    pass "Case 11: poll 0, check 1 -> exit 2"
else
    fail "Case 11: poll 0, check 1 -> exit 2" "exit: $STATUS11 (want 2)" "output: [$OUT11]"
fi

# --- Case 12: poll 0, check 2 -> 1 (regression: was 2 before the fix) -----
OUT12="$(STUB_POLL_EXIT=0 STUB_CHECK_EXIT=2 run_verify 2>&1)"
STATUS12=$?
if [ "$STATUS12" -eq 1 ] && printf '%s' "$OUT12" | grep -q 'could not verify findings'; then
    pass "Case 12: poll 0, check 2 (can't verify findings) -> exit 1"
else
    fail "Case 12: poll 0, check 2 (can't verify findings) -> exit 1" \
        "exit: $STATUS12 (want 1)" "output: [$OUT12]"
fi

# --- Case 13: first poll 1, second poll 2 -> 1 (regression: was 4) --------
OUT13="$(STUB_POLL_EXIT=1 STUB_POLL2_EXIT=2 STUB_CHECK_EXIT=0 run_verify 2>&1)"
STATUS13=$?
POLLS13="$(cat "$POLL_COUNT_FILE" 2>/dev/null || echo '?')"
if [ "$STATUS13" -eq 1 ] && [ "$POLLS13" = "2" ] \
    && grep -q 'workflow run claude-code-review.yml' "$GH_LOG"; then
    pass "Case 13: first poll 1, second poll 2 (can't verify after recovery) -> exit 1"
else
    fail "Case 13: first poll 1, second poll 2 (can't verify after recovery) -> exit 1" \
        "exit: $STATUS13 (want 1)" "polls: $POLLS13 (want 2)" "output: [$OUT13]"
fi

# --- Case 14: first poll 1, second poll 1 -> 4 -----------------------------
OUT14="$(STUB_POLL_EXIT=1 STUB_POLL2_EXIT=1 STUB_CHECK_EXIT=0 run_verify 2>&1)"
STATUS14=$?
POLLS14="$(cat "$POLL_COUNT_FILE" 2>/dev/null || echo '?')"
if [ "$STATUS14" -eq 4 ] && [ "$POLLS14" = "2" ] \
    && grep -q 'workflow run claude-code-review.yml' "$GH_LOG"; then
    pass "Case 14: no comment after poll + recovery -> exit 4"
else
    fail "Case 14: no comment after poll + recovery -> exit 4" \
        "exit: $STATUS14 (want 4)" "polls: $POLLS14 (want 2)" "output: [$OUT14]"
fi

# --- Case 15: first poll 2 -> 1 --------------------------------------------
OUT15="$(STUB_POLL_EXIT=2 STUB_CHECK_EXIT=0 run_verify 2>&1)"
STATUS15=$?
if [ "$STATUS15" -eq 1 ]; then
    pass "Case 15: first poll can't verify (exit 2) -> exit 1"
else
    fail "Case 15: first poll can't verify (exit 2) -> exit 1" \
        "exit: $STATUS15 (want 1)" "output: [$OUT15]"
fi

# --- Case 20: --include-important passthrough -----------------------------
OUT20="$(STUB_POLL_EXIT=0 STUB_CHECK_EXIT=0 run_verify --include-important 2>&1)"
STATUS20=$?
ARGS20="$(cat "$CHECK_ARGS_LOG" 2>/dev/null || echo '?')"
EXPECTED20="$(printf '[1]\n[--include-important]')"
if [ "$STATUS20" -eq 0 ] && [ "$ARGS20" = "$EXPECTED20" ]; then
    pass "Case 20: --include-important passthrough -> exit 0, check args are exactly [1] [--include-important]"
else
    fail "Case 20: --include-important passthrough -> exit 0, check args are exactly [1] [--include-important]" \
        "exit: $STATUS20 (want 0)" "log: [$ARGS20]" "output: [$OUT20]"
fi

# --- Case 21: without the flag, no empty argument passed through ----------
OUT21="$(STUB_POLL_EXIT=0 STUB_CHECK_EXIT=0 run_verify 2>&1)"
STATUS21=$?
ARGS21="$(cat "$CHECK_ARGS_LOG" 2>/dev/null || echo '?')"
if [ "$STATUS21" -eq 0 ] && [ "$ARGS21" = "[1]" ]; then
    pass "Case 21: without --include-important -> exit 0, check args are exactly [1] (no empty argument)"
else
    fail "Case 21: without --include-important -> exit 0, check args are exactly [1] (no empty argument)" \
        "exit: $STATUS21 (want 0)" "log: [$ARGS21] (want [1])" "output: [$OUT21]"
fi

# --- Case 22: first poll 1 (recovery), second poll 0 -> 0 ------------------
OUT22="$(STUB_POLL_EXIT=1 STUB_POLL2_EXIT=0 STUB_CHECK_EXIT=0 run_verify 2>&1)"
STATUS22=$?
POLLS22="$(cat "$POLL_COUNT_FILE" 2>/dev/null || echo '?')"
if [ "$STATUS22" -eq 0 ] && [ "$POLLS22" = "2" ]; then
    pass "Case 22: first poll fails, second poll succeeds (recovery) -> exit 0"
else
    fail "Case 22: first poll fails, second poll succeeds (recovery) -> exit 0" \
        "exit: $STATUS22 (want 0)" "polls: $POLLS22 (want 2)" "output: [$OUT22]"
fi

# --- Report ------------------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
