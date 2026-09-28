#!/bin/bash
#
# Regression test for scripts/check-blocking-findings.sh and
# scripts/verify-ci-review.sh (#2222): a clean review (no Blocking heading at
# all) must exit 0, not fail under `pipefail`/`set -e` before it can print
# anything, and a caller's `if ! poll; then poll_status=$?` pattern must not
# silently store the negated (always-0) exit status.
#
# Also covers #2225: the workflow-file guard in verify-ci-review.sh reads the
# PR's file list (`gh api …/pulls/N/files`) through a here-string, not a
# piped `grep -q`, so a large list can't SIGPIPE `gh` and read as "no match".
#
# Also covers #2223: CI's own Blocking gate in claude-code-review.yml (both
# the pr-to-milestone-review and milestone-review jobs) must keep using the
# exact same HEADING_PATTERN, EXCLUSION_PATTERN, and per-grep
# `|| [ $? -eq 1 ]` guard as scripts/check-blocking-findings.sh. See that
# script's header for why. Part C checks the two never drift apart.
#
# HERMETIC: Part A stubs `gh` (and, for case 16, `grep`) first on PATH. Part B
# runs a copy of verify-ci-review.sh from a directory that also holds stub
# poll-review-posted.sh and check-blocking-findings.sh siblings, which the
# copy finds through its own SCRIPT_DIR. Part B also puts a logging `gh` stub
# first on PATH for the recovery path. Part C reads files under $REAL_REPO
# and temp copies of the workflow file, and also runs the extracted gate
# blocks with `bash -e -c` (GitHub Actions' real default shell, no
# `-o pipefail`; no network, no real `gh`, no `eval`).
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

# --- Cases 5b-5d: each other recap phrasing on its own -> 0 --------------
# Case 5 uses "previous round", so it cannot show that the other parts of
# EXCLUSION_PATTERN still work. Losing one of them would report a clean
# review as blocked, the same symptom as #2222. 5d has no "round" in it, so
# it tests the ": resolved" part alone.
for recap in \
    "5b|### Blocking finding from the prior round" \
    "5c|### Blocking (resolved)" \
    "5d|### Blocking finding: resolved"; do
    id="${recap%%|*}"
    heading="${recap#*|}"
    BODY5X="### Strengths
Fine.

$heading
Fixed in the latest commit."
    OUT5X="$(run_check "$BODY5X" 1 2>&1)"
    STATUS5X=$?
    if [ "$STATUS5X" -eq 0 ]; then
        pass "Case $id: recap heading '$heading' is excluded -> exit 0"
    else
        fail "Case $id: recap heading '$heading' is excluded -> exit 0" \
            "exit: $STATUS5X (want 0)" "output: [$OUT5X]"
    fi
done

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

# Built once: the matching path, then 50000 more (~540 KB, far past a pipe
# buffer; the bug reproduced every time from 10000 lines up).
STUB_GH_DIFF_MATCH_FILE="$TMPROOT/gh-diff-match.txt"
{
    printf '%s\n' '.github/workflows/claude-code-review.yml'
    seq 1 50000 | sed 's|^|app/f|'
} > "$STUB_GH_DIFF_MATCH_FILE"

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

# Stub `gh` for the recovery path: logs its arguments, exits 0. The PR file
# list call (`gh api repos/…/pulls/N/files`) depends on STUB_GH_DIFF_MODE:
#   unset/empty  : prints nothing (self-referential-workflow-file check never
#                  matches) — existing cases 10-22 rely on this default.
#   large-match  : prints the matching workflow file first, then 50000 more
#                  path lines from a pre-built file — big enough to fill the
#                  pipe buffer (#2225).
#   fail         : the file list call itself fails (simulated `gh` error).
# `gh workflow run` and `gh pr edit` just succeed in every mode.
cat > "$STUBDIR/gh" <<'STUB'
#!/bin/bash
printf '%s\n' "gh $*" >> "${STUB_GH_LOG:?STUB_GH_LOG not set}"
if [ "$1" = "api" ] && case "$2" in repos/*/pulls/*/files) true ;; *) false ;; esac; then
    case "${STUB_GH_DIFF_MODE:-}" in
        large-match)
            # `exec` so the stub exits with cat's SIGPIPE 141; the stub's own
            # `exit 0` would hide it, and the old code would pass this case.
            exec cat "${STUB_GH_DIFF_MATCH_FILE:?STUB_GH_DIFF_MATCH_FILE not set}"
            ;;
        fail)
            echo "gh: simulated PR file list failure (stub)" >&2
            exit 1
            ;;
        *)
            exit 0
            ;;
    esac
fi
exit 0
STUB
chmod +x "$STUBDIR/gh"

run_verify() {
    rm -f "$POLL_COUNT_FILE"
    : > "$GH_LOG"
    : > "$CHECK_ARGS_LOG"
    STUB_POLL_COUNT_FILE="$POLL_COUNT_FILE" STUB_GH_LOG="$GH_LOG" \
        STUB_GH_DIFF_MODE="${STUB_GH_DIFF_MODE:-}" \
        STUB_GH_DIFF_MATCH_FILE="$STUB_GH_DIFF_MATCH_FILE" \
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

# --- Case 23: large PR file list with an early match (#2225) --------------
# STUB_POLL_EXIT=1 forces the recovery path so verify-ci-review.sh reaches
# the workflow-file guard's file list call. large-match makes the stub
# print a match first, then 50000 more lines — enough to fill the pipe
# buffer. A piped `grep -q` would SIGPIPE `gh` here and (under pipefail) read
# that as "no match"; the here-string fix must still detect the match, skip
# recovery, and exit 4 after exactly one poll. The old code also ends with
# exit 4 here (its second poll fails too), so the poll count and the absent
# `workflow run` line are the checks that catch the bug. Keep them.
OUT23="$(STUB_POLL_EXIT=1 STUB_CHECK_EXIT=0 STUB_GH_DIFF_MODE=large-match run_verify 2>&1)"
STATUS23=$?
POLLS23="$(cat "$POLL_COUNT_FILE" 2>/dev/null || echo '?')"
if [ "$STATUS23" -eq 4 ] && [ "$POLLS23" = "1" ] \
    && ! grep -q 'workflow run' "$GH_LOG"; then
    pass "Case 23: large PR file list with an early match -> exit 4, no recovery workflow run, one poll"
else
    fail "Case 23: large PR file list with an early match -> exit 4, no recovery workflow run, one poll" \
        "exit: $STATUS23 (want 4)" "polls: $POLLS23 (want 1)" "output: [$OUT23]"
fi

# --- Case 24: the PR file list call fails -> 1 -----------------------------
OUT24="$(STUB_POLL_EXIT=1 STUB_CHECK_EXIT=0 STUB_GH_DIFF_MODE=fail run_verify 2>&1)"
STATUS24=$?
if [ "$STATUS24" -eq 1 ] && printf '%s' "$OUT24" | grep -q 'file list could not be read' \
    && ! grep -q 'workflow run' "$GH_LOG"; then
    pass "Case 24: PR file list call fails -> exit 1, message mentions the failure, no recovery workflow run"
else
    fail "Case 24: PR file list call fails -> exit 1, message mentions the failure, no recovery workflow run" \
        "exit: $STATUS24 (want 1)" "output: [$OUT24]"
fi

# =========================================================================
# Part C: claude-code-review.yml must not drift from check-blocking-findings.sh (#2223)
# =========================================================================
#
# CI's Blocking gate (both the pr-to-milestone-review and milestone-review
# jobs in claude-code-review.yml) carries its own copy of HEADING_PATTERN,
# EXCLUSION_PATTERN, and the per-grep `|| [ $? -eq 1 ]` guard, instead of
# calling check-blocking-findings.sh directly. Nothing stops that copy from
# drifting out of sync with the script over time. This check reads both
# files as plain text and compares them line by line.

WORKFLOW_FILE="$REAL_REPO/.github/workflows/claude-code-review.yml"
SCRIPT_HEADING_LINE="$(grep -m1 '^HEADING_PATTERN=' "$CHECK_SCRIPT")"
SCRIPT_EXCLUSION_LINE="$(grep -m1 '^EXCLUSION_PATTERN=' "$CHECK_SCRIPT")"

# Checks that a `VARNAME=` assignment line occurs exactly twice in a
# workflow file and that each occurrence, with leading whitespace stripped,
# equals `expected` exactly. Prints a reason and returns non-zero otherwise.
#
# The count is validated with `[ "${count:-0}" -eq 2 ] || return 1` rather
# than `[ "$count" -ne 2 ]`. A missing or unreadable file makes `grep -c`
# print nothing, and `[ "" -ne 2 ]` is a shell error that `if` reads as
# false — silently passing the check on a file that was never read. The
# explicit `-eq` form with a `${count:-0}` fallback fails closed instead.
check_pattern_line() {
    local wf="$1"
    local varname="$2"
    local expected="$3"
    local count line stripped

    count="$(grep -c "^[[:space:]]*${varname}=" "$wf" 2>/dev/null || true)"
    [ "${count:-0}" -eq 2 ] 2>/dev/null || {
        echo "${varname}= line appears '${count:-0}' time(s) in $wf, want 2"
        return 1
    }
    while IFS= read -r line; do
        stripped="$(printf '%s' "$line" | sed 's/^[[:space:]]*//')"
        if [ "$stripped" != "$expected" ]; then
            echo "${varname}= line in $wf does not match the script:"
            echo "  workflow: $stripped"
            echo "  script:   $expected"
            return 1
        fi
    done < <(grep "^[[:space:]]*${varname}=" "$wf")

    return 0
}

# Checks that a literal (non-regex) substring occurs exactly twice in a
# workflow file. Prints a reason and returns non-zero otherwise. Same
# fail-closed `${count:-0}` idiom as check_pattern_line.
check_literal_count() {
    local wf="$1"
    local literal="$2"
    local label="$3"
    local count

    count="$(grep -Fc "$literal" "$wf" 2>/dev/null || true)"
    [ "${count:-0}" -eq 2 ] 2>/dev/null || {
        echo "$label appears '${count:-0}' time(s) in $wf, want 2"
        return 1
    }
    return 0
}

# Checks one workflow file for drift against check-blocking-findings.sh.
# Prints a reason on failure and returns non-zero. Used both against the
# real workflow (case 25) and a deliberately-drifted copy (case 26).
check_workflow_matches_script() {
    local wf="$1"

    if [ ! -r "$wf" ]; then
        echo "$wf is missing or not readable"
        return 1
    fi

    check_pattern_line "$wf" EXCLUSION_PATTERN "$SCRIPT_EXCLUSION_LINE" || return 1
    check_pattern_line "$wf" HEADING_PATTERN "$SCRIPT_HEADING_LINE" || return 1

    # shellcheck disable=SC2016 # -F is a literal match; no expansion wanted
    check_literal_count "$wf" 'grep -E "^(${HEADING_PATTERN})" || [ $? -eq 1 ]' \
        "the heading grep with its guard" || return 1
    # shellcheck disable=SC2016 # -F is a literal match; no expansion wanted
    check_literal_count "$wf" 'grep -viE "$EXCLUSION_PATTERN" || [ $? -eq 1 ]' \
        "the exclusion grep with its guard" || return 1

    if grep -q 'grep -cviE' "$wf"; then
        echo "$wf still contains the old 'grep -cviE' pipeline"
        return 1
    fi

    return 0
}

# Copies `src` to `dst`, replacing a literal (not regex) string `find` with
# `replace` on the Nth line that contains it (`which` a 1-based number), or
# on every such line when `which` is "all". Uses awk's index()/substr()
# rather than a regex substitution, so special regex characters in `find` or
# `replace` (parens, `$`, `|`) need no escaping and the match is portable
# across awk implementations. The find/replace strings are passed through
# the environment rather than `-v`, because `awk -v` unescapes backslash
# sequences in its value (so `\(` would arrive as `(`) and ENVIRON does not.
# Fails loudly (non-zero exit, stderr message) if `find` is never found in
# `src`, so a mutant that silently failed to apply can't be mistaken for a
# mutant that applied but didn't change behavior.
make_mutant() {
    local src="$1" dst="$2" find="$3" replace="$4" which="$5"
    MUTANT_FIND="$find" MUTANT_REPL="$replace" MUTANT_WHICH="$which" awk '
        BEGIN {
            find = ENVIRON["MUTANT_FIND"]
            repl = ENVIRON["MUTANT_REPL"]
            which = ENVIRON["MUTANT_WHICH"]
        }
        {
            line = $0
            if (index(line, find) > 0 && (which == "all" || ++seen == which)) {
                pos = index(line, find)
                line = substr(line, 1, pos - 1) repl substr(line, pos + length(find))
                applied++
            }
            print line
        }
        END {
            if (applied == 0) {
                print "make_mutant: literal find string not found: " find > "/dev/stderr"
                exit 1
            }
        }
    ' "$src" > "$dst"
}

# --- Case 25: the real workflow matches the script ------------------------
OUT25="$(check_workflow_matches_script "$WORKFLOW_FILE" 2>&1)"
STATUS25=$?
if [ "$STATUS25" -eq 0 ]; then
    pass "Case 25: claude-code-review.yml's patterns and guards match check-blocking-findings.sh"
else
    fail "Case 25: claude-code-review.yml's patterns and guards match check-blocking-findings.sh" \
        "output: [$OUT25]"
fi

# --- Case 26: negative control — a drifted copy must fail the check -------
# Replaces one EXCLUSION_PATTERN= line with the old, broken pattern (the one
# #2223 removed, which also matches "unresolved"). The check above must
# catch this, or it isn't actually testing anything. Only the first
# occurrence is changed — one drifted copy is enough to prove the check
# notices, and it keeps the substitution unambiguous.
DRIFTED_WORKFLOW="$TMPROOT/drifted-claude-code-review.yml"
if MUTANT_ERR="$(make_mutant "$WORKFLOW_FILE" "$DRIFTED_WORKFLOW" "$SCRIPT_EXCLUSION_LINE" \
    "EXCLUSION_PATTERN='resolved|previous round|prior round|earlier round'" 1 2>&1)"; then
    OUT26="$(check_workflow_matches_script "$DRIFTED_WORKFLOW" 2>&1)"
    STATUS26=$?
else
    OUT26="make_mutant could not build the drifted copy: $MUTANT_ERR"
    STATUS26=0
fi
if [ "$STATUS26" -ne 0 ]; then
    pass "Case 26: a drifted EXCLUSION_PATTERN in the workflow is caught (negative control)"
else
    fail "Case 26: a drifted EXCLUSION_PATTERN in the workflow is caught (negative control)" \
        "exit: $STATUS26 (want non-zero)" "output: [$OUT26]"
fi

# --- Part C continued: run the workflow's own gate blocks, not just their patterns
#
# check_workflow_matches_script (cases 25-26) only compares text. A change
# that keeps HEADING_PATTERN and EXCLUSION_PATTERN byte-identical but breaks
# the surrounding logic — for example flipping `-n "$MATCHES"` to `-z`, or
# dropping the `!` on `if ! MATCHES=$(...)` — passes that check untouched
# and silently makes the gate pass every PR, findings or not. This section
# pulls each of the 2 gate blocks out of the workflow file as literal shell
# text and actually runs it, under `bash -e` — GitHub Actions' real default
# shell for a `run:` step with no `shell:` override (`bash -e {0}`, NOT
# `-o pipefail`; only an explicit `shell: bash` gets pipefail) — so a change
# to the gate's control flow gets caught here even when the patterns it
# operates on stay the same. This is also why each block sets its own
# `set -o pipefail` (see the comment above HEADING_PATTERN in the workflow).

# Extracts gate block number `n` (1 or 2, by order of appearance) from a
# workflow file: from its `HEADING_PATTERN=` line through the first
# following line containing "no Blocking section found.", with the common
# 10-space run: indentation stripped so the result is plain, runnable shell.
# Fails (non-zero, no output) if that block cannot be found.
extract_gate_block() {
    local wf="$1"
    local n="$2"
    awk -v want="$n" '
        /^[[:space:]]*HEADING_PATTERN=/ && !in_block {
            count++
            if (count == want) { in_block = 1 }
        }
        in_block {
            line = $0
            sub(/^          /, "", line)
            print line
        }
        in_block && /no Blocking section found\./ { in_block = 0; found = 1; exit }
        END { if (!found) exit 1 }
    ' "$wf"
}

# Runs the 6 behavioral cases (a-f) against both gate blocks in a workflow
# file. Prints a reason and returns non-zero on the first failure. Reuses
# the case 16/16b grep stub (GREPSTUBDIR) for cases (d) and (e).
check_gate_blocks_behave() {
    local wf="$1"
    local n block out rc count

    count="$(grep -c '^[[:space:]]*HEADING_PATTERN=' "$wf" 2>/dev/null || true)"
    [ "${count:-0}" -eq 2 ] 2>/dev/null || {
        echo "expected exactly 2 gate blocks in $wf, found '${count:-0}'"
        return 1
    }

    for n in 1 2; do
        block="$(extract_gate_block "$wf" "$n")" || {
            echo "could not extract gate block $n from $wf"
            return 1
        }

        # (a) a clean review -> exit 0
        out="$(REVIEW_BODY=$'### Strengths\n- ok' bash -e -c "$block" < /dev/null 2>&1)"
        rc=$?
        if [ "$rc" -ne 0 ]; then
            echo "block $n, case (a) clean review: exit $rc (want 0): $out"
            return 1
        fi

        # (b) a live, unresolved Blocking heading -> exit 1, error printed
        out="$(REVIEW_BODY=$'### Blocking issues, unresolved\n- x' bash -e -c "$block" < /dev/null 2>&1)"
        rc=$?
        if [ "$rc" -ne 1 ] || ! printf '%s' "$out" | grep -q '::error::Review posted Blocking findings'; then
            echo "block $n, case (b) unresolved Blocking: exit $rc (want 1), output: [$out]"
            return 1
        fi

        # (c) a resolved recap heading -> exit 0
        out="$(REVIEW_BODY=$'### Blocking finding from the previous round: resolved' bash -e -c "$block" < /dev/null 2>&1)"
        rc=$?
        if [ "$rc" -ne 0 ]; then
            echo "block $n, case (c) recap resolved: exit $rc (want 0): $out"
            return 1
        fi

        # (d) the first grep errors -> exit 1, "cannot verify" error printed,
        # never a silent pass
        out="$(PATH="$GREPSTUBDIR:$PATH" STUB_GREP_FAIL_ON=-E REVIEW_BODY=$'### Strengths\n- ok' bash -e -c "$block" < /dev/null 2>&1)"
        rc=$?
        if [ "$rc" -ne 1 ] || ! printf '%s' "$out" | grep -q 'grep failed while scanning'; then
            echo "block $n, case (d) grep error: exit $rc (want 1), output: [$out]"
            return 1
        fi

        # (e) the second grep (-viE, the recap exclusion) errors -> exit 1,
        # "cannot verify" error printed. The body has a live heading, so the
        # first grep matches and passes it to the second grep, which is the
        # one that fails here (case 16b's same approach, applied to the
        # workflow block instead of the script).
        out="$(PATH="$GREPSTUBDIR:$PATH" STUB_GREP_FAIL_ON=-viE REVIEW_BODY=$'### Blocking\n- x' bash -e -c "$block" < /dev/null 2>&1)"
        rc=$?
        if [ "$rc" -ne 1 ] || ! printf '%s' "$out" | grep -q 'grep failed while scanning'; then
            echo "block $n, case (e) second grep error: exit $rc (want 1), output: [$out]"
            return 1
        fi

        # (f) a resolved recap heading followed by a live Blocking heading in
        # the same body -> exit 1, Blocking error printed (#1843). Checks
        # every match across the whole body, not just the first one: a
        # `head -n 1` (or similar) on the heading grep would drop the live
        # heading here and silently pass.
        out="$(REVIEW_BODY=$'### Strengths\n- ok\n### Blocking finding from the previous round: resolved\n### Blocking\n- SQLi' bash -e -c "$block" < /dev/null 2>&1)"
        rc=$?
        if [ "$rc" -ne 1 ] || ! printf '%s' "$out" | grep -q '::error::Review posted Blocking findings'; then
            echo "block $n, case (f) recap then live heading: exit $rc (want 1), output: [$out]"
            return 1
        fi
    done

    return 0
}

# --- Case 27: both real gate blocks behave correctly (a)-(f) --------------
OUT27="$(check_gate_blocks_behave "$WORKFLOW_FILE" 2>&1)"
STATUS27=$?
if [ "$STATUS27" -eq 0 ]; then
    pass "Case 27: both claude-code-review.yml gate blocks pass cases (a)-(f)"
else
    fail "Case 27: both claude-code-review.yml gate blocks pass cases (a)-(f)" \
        "output: [$OUT27]"
fi

# --- Case 28: negative control — `-n "$MATCHES"` flipped to `-z` ----------
# Mutates only the first block's `if [ -n "$MATCHES" ]` to `-z`, which
# inverts the Blocking check: a clean review would then be reported as
# Blocking, and a real Blocking finding would pass silently. Case (a) on
# block 1 must fail against this mutant, or case 27 was not actually
# exercising the gate's logic.
MUTANT_NEGATED_WORKFLOW="$TMPROOT/mutant-negated-claude-code-review.yml"
# shellcheck disable=SC2016 # literal find/replace strings; no expansion wanted
if MUTANT_ERR="$(make_mutant "$WORKFLOW_FILE" "$MUTANT_NEGATED_WORKFLOW" \
    'if [ -n "$MATCHES" ]; then' 'if [ -z "$MATCHES" ]; then' 1 2>&1)"; then
    OUT28="$(check_gate_blocks_behave "$MUTANT_NEGATED_WORKFLOW" 2>&1)"
    STATUS28=$?
else
    OUT28="make_mutant could not build the mutant: $MUTANT_ERR"
    STATUS28=0
fi
if [ "$STATUS28" -ne 0 ]; then
    pass "Case 28: '-n \"\$MATCHES\"' flipped to '-z' in block 1 is caught (negative control)"
else
    fail "Case 28: '-n \"\$MATCHES\"' flipped to '-z' in block 1 is caught (negative control)" \
        "exit: $STATUS28 (want non-zero)" "output: [$OUT28]"
fi

# --- Case 29: negative control — `if !` dropped from the grep guard -------
# Mutates only the second block's `if ! MATCHES=$(printf ...)` to
# `if MATCHES=$(printf ...)`. This inverts the branch, it is not a `set -e`
# abort: a command in an `if` condition is exempt from `-e` whether or not
# it has a leading `!`. With the `!` gone, a clean review's assignment
# succeeds, so `if MATCHES=...` is now true, and the block runs the
# "grep failed" error branch on a review that has no grep error at all.
# Case (a) on block 2 fails against this mutant first, catching the break.
MUTANT_UNGUARDED_WORKFLOW="$TMPROOT/mutant-unguarded-claude-code-review.yml"
# shellcheck disable=SC2016 # literal find/replace strings; no expansion wanted
if MUTANT_ERR="$(make_mutant "$WORKFLOW_FILE" "$MUTANT_UNGUARDED_WORKFLOW" \
    'if ! MATCHES=$(printf' 'if MATCHES=$(printf' 2 2>&1)"; then
    OUT29="$(check_gate_blocks_behave "$MUTANT_UNGUARDED_WORKFLOW" 2>&1)"
    STATUS29=$?
else
    OUT29="make_mutant could not build the mutant: $MUTANT_ERR"
    STATUS29=0
fi
if [ "$STATUS29" -ne 0 ]; then
    pass "Case 29: 'if !' dropped from block 2's grep guard is caught (negative control)"
else
    fail "Case 29: 'if !' dropped from block 2's grep guard is caught (negative control)" \
        "exit: $STATUS29 (want non-zero)" "output: [$OUT29]"
fi

# --- Case 30: negative control — the exclusion-grep guard always succeeds -
# Mutates BOTH blocks' second guard from `|| [ $? -eq 1 ]` to
# `|| [ $? -eq 1 ] || true`, so a real grep error (exit 2) on the exclusion
# grep no longer fails the assignment — the trailing `|| true` swallows it
# and the step reports a clean review instead of "cannot verify". Case (e)
# on both blocks must fail against this mutant.
MUTANT_ALWAYS_TRUE_WORKFLOW="$TMPROOT/mutant-always-true-claude-code-review.yml"
# shellcheck disable=SC2016 # literal find/replace strings; no expansion wanted
if MUTANT_ERR="$(make_mutant "$WORKFLOW_FILE" "$MUTANT_ALWAYS_TRUE_WORKFLOW" \
    'grep -viE "$EXCLUSION_PATTERN" || [ $? -eq 1 ]; }); then' \
    'grep -viE "$EXCLUSION_PATTERN" || [ $? -eq 1 ] || true; }); then' all 2>&1)"; then
    OUT30="$(check_gate_blocks_behave "$MUTANT_ALWAYS_TRUE_WORKFLOW" 2>&1)"
    STATUS30=$?
else
    OUT30="make_mutant could not build the mutant: $MUTANT_ERR"
    STATUS30=0
fi
if [ "$STATUS30" -ne 0 ]; then
    pass "Case 30: '|| true' added to both blocks' exclusion-grep guard is caught (negative control)"
else
    fail "Case 30: '|| true' added to both blocks' exclusion-grep guard is caught (negative control)" \
        "exit: $STATUS30 (want non-zero)" "output: [$OUT30]"
fi

# --- Case 31: negative control — `set -o pipefail` removed from block 1 ---
# Removes block 1's `set -o pipefail` (replaced with a no-op `:`), leaving
# block 2's untouched. This step's real shell has no pipefail of its own
# (`bash -e {0}`), so without this line the `$(... | {grep1} | {grep2})`
# pipeline's exit status comes from grep2 alone. Case (d) — the first grep
# erroring — is the one this breaks: grep2 still succeeds on empty input, so
# the block reports "clean" instead of "cannot verify" (the exact CI bug,
# confirmed in run 36491820008). Case (e) still catches its own scenario,
# because the failing grep there is the pipeline's last command, so its exit
# status is never lost even without pipefail.
MUTANT_NO_PIPEFAIL_WORKFLOW="$TMPROOT/mutant-no-pipefail-claude-code-review.yml"
if MUTANT_ERR="$(make_mutant "$WORKFLOW_FILE" "$MUTANT_NO_PIPEFAIL_WORKFLOW" \
    '          set -o pipefail' '          :' 1 2>&1)"; then
    OUT31="$(check_gate_blocks_behave "$MUTANT_NO_PIPEFAIL_WORKFLOW" 2>&1)"
    STATUS31=$?
else
    OUT31="make_mutant could not build the mutant: $MUTANT_ERR"
    STATUS31=0
fi
if [ "$STATUS31" -ne 0 ] && printf '%s' "$OUT31" | grep -q 'case (d)'; then
    pass "Case 31: 'set -o pipefail' removed from block 1 is caught by case (d) (negative control)"
else
    fail "Case 31: 'set -o pipefail' removed from block 1 is caught by case (d) (negative control)" \
        "exit: $STATUS31 (want non-zero, case (d))" "output: [$OUT31]"
fi

# --- Case 32: negative control — `| head -n 1` added to the heading grep --
# Appends ` | head -n 1` onto both blocks' heading-grep line, keeping the
# `\` line continuation valid. This drops every heading match after the
# first, so a resolved recap heading followed by a genuine live Blocking
# heading in the same comment (#1843) is silently reduced to just the
# recap, and the live heading never reaches the exclusion grep. Case (f) on
# both blocks must fail against this mutant.
MUTANT_HEAD1_WORKFLOW="$TMPROOT/mutant-head1-claude-code-review.yml"
# shellcheck disable=SC2016,SC1003 # literal find/replace strings ending in a
# real trailing backslash (the line continuation), not an escape attempt
if MUTANT_ERR="$(make_mutant "$WORKFLOW_FILE" "$MUTANT_HEAD1_WORKFLOW" \
    '            | { grep -E "^(${HEADING_PATTERN})" || [ $? -eq 1 ]; } \' \
    '            | { grep -E "^(${HEADING_PATTERN})" || [ $? -eq 1 ]; } | head -n 1 \' \
    all 2>&1)"; then
    OUT32="$(check_gate_blocks_behave "$MUTANT_HEAD1_WORKFLOW" 2>&1)"
    STATUS32=$?
else
    OUT32="make_mutant could not build the mutant: $MUTANT_ERR"
    STATUS32=0
fi
if [ "$STATUS32" -ne 0 ] && printf '%s' "$OUT32" | grep -q 'case (f)'; then
    pass "Case 32: '| head -n 1' added to the heading grep is caught by case (f) (negative control)"
else
    fail "Case 32: '| head -n 1' added to the heading grep is caught by case (f) (negative control)" \
        "exit: $STATUS32 (want non-zero, case (f))" "output: [$OUT32]"
fi

# --- Case 33: negative control — a drifted HEADING_PATTERN must fail the --
# check (like case 26, but for HEADING_PATTERN instead of EXCLUSION_PATTERN)
# Replaces the first HEADING_PATTERN= line with the old, exact-line-only
# pattern that #2223's predecessor fix replaced — it has no `([[:space:]]|$)`
# boundary, so it would silently fail-open on a heading with trailing words
# ("### Blocking findings"). check_workflow_matches_script must catch this,
# or the HEADING_PATTERN half of case 25/26 isn't actually testing anything.
DRIFTED_HEADING_WORKFLOW="$TMPROOT/drifted-heading-claude-code-review.yml"
if MUTANT_ERR="$(make_mutant "$WORKFLOW_FILE" "$DRIFTED_HEADING_WORKFLOW" "$SCRIPT_HEADING_LINE" \
    "HEADING_PATTERN='^#{1,6}[[:space:]]+Blocking\$'" 1 2>&1)"; then
    OUT33="$(check_workflow_matches_script "$DRIFTED_HEADING_WORKFLOW" 2>&1)"
    STATUS33=$?
else
    OUT33="make_mutant could not build the drifted copy: $MUTANT_ERR"
    STATUS33=0
fi
if [ "$STATUS33" -ne 0 ]; then
    pass "Case 33: a drifted HEADING_PATTERN in the workflow is caught (negative control)"
else
    fail "Case 33: a drifted HEADING_PATTERN in the workflow is caught (negative control)" \
        "exit: $STATUS33 (want non-zero)" "output: [$OUT33]"
fi

# --- Report ------------------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
