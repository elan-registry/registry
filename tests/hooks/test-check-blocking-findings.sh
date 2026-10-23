#!/bin/bash
#
# Regression test for scripts/check-blocking-findings.sh (Part A) and
# scripts/verify-ci-review.sh (Part B), #2506.
#
# The scripts accept a review only when it is a comment from
# github-actions[bot], the last line of the body is a verdict line, and the
# verdict is for the head commit of the PR:
#
#   <!-- claude-review-verdict sha=<40 hex> blocking=<n> important=<n> -->
#
# HERMETIC: the shared stub in lib/gh-stub.sh goes first on PATH. It runs the
# real jq filter of the script on fixture JSON, so a wrong filter fails here.
# The fixtures are issue comments in the real GitHub shape (id, created_at,
# user.login, body). Part B runs a copy of verify-ci-review.sh beside stub
# sibling scripts, which the copy finds through its own SCRIPT_DIR. No network
# and no real gh.
#
# Environment (optional): CHECK_BLOCKING_SCRIPT and VERIFY_SCRIPT point the
# test at other copies of the scripts. The proof that a case can fail uses it
# with the files of origin/main.
#
# Usage: bash tests/hooks/test-check-blocking-findings.sh

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"
# shellcheck source=/dev/null
. "$(dirname "$0")/lib/gh-stub.sh"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
CHECK_SCRIPT="${CHECK_BLOCKING_SCRIPT:-$REAL_REPO/scripts/check-blocking-findings.sh}"
VERIFY_SCRIPT="${VERIFY_SCRIPT:-$REAL_REPO/scripts/verify-ci-review.sh}"
for script in "$CHECK_SCRIPT" "$VERIFY_SCRIPT"; do
    if [ ! -x "$script" ]; then
        echo "FAIL: $script not found or not executable" >&2
        exit 1
    fi
done

TMPROOT="$(mktemp -d)" || exit 1

STUBDIR="$TMPROOT/bin"
gh_stub_install "$STUBDIR" || exit 1

# --- Fixtures ----------------------------------------------------------------
# H1 is the head commit of the PR. H0 is an older commit of the same PR.
H1="9fceb02d0ae598e95dc970b74767f19372d61af8"
H0="3b18e512dba79e4c8300dd08aeb7f61b7a4f5da9"
BOT="github-actions[bot]"
T1="2026-10-20T10:00:00Z"
T2="2026-10-20T11:00:00Z"
T3="2026-10-20T12:00:00Z"

# A fixture SHA with a wrong length would make every "old SHA" case pass for
# the wrong reason. So check the fixtures first.
if [ "${#H1}" -ne 40 ] || [ "${#H0}" -ne 40 ]; then
    echo "FAIL: fixture SHAs must be 40 characters" >&2
    exit 1
fi

# verdict <sha> <blocking> <important>: the exact verdict line.
verdict() {
    printf '<!-- claude-review-verdict sha=%s blocking=%s important=%s -->' "$1" "$2" "$3"
}

# review_body <verdict line>: a rendered review with the verdict as last line.
# It keeps a Strengths heading, as the old format had. So a case that expects
# "does not count" cannot pass only because the heading is missing.
review_body() {
    printf '%s\n\n### Strengths\n- The tests cover the change.\n\n### Notes\n- **Naming** (app/Car.php:12) — Consider a clearer name.\n\n%s\n' \
        'The change is small.' "$1"
}

# comment <id> <created_at> <login> <body>: one issue comment as GitHub sends it.
comment() {
    jq -cn --argjson id "$1" --arg created "$2" --arg login "$3" --arg body "$4" \
        '{id: $id, created_at: $created, updated_at: $created,
          user: {login: $login, type: "Bot"}, body: $body}'
}

# use_page <comment-json>...: write page 1 of the comments and select it.
use_page() {
    printf '%s\n' "$@" | jq -s '.' > "$TMPROOT/page1.json"
    STUB_COMMENTS="$TMPROOT/page1.json"
    unset STUB_PAGE1 STUB_PAGE2
}

printf '{"headRefOid": "%s"}\n' "$H1" > "$TMPROOT/pr.json"
STUB_PR_JSON="$TMPROOT/pr.json"
STUB_LOG="$TMPROOT/gh.log"
STUB_COMMENTS=""
export STUB_PR_JSON STUB_LOG STUB_COMMENTS

# assert_no_verdict <label> <status> <output>: exit 2 for the reason "no
# verdict for the head". Without the message check, a script that fails for
# another reason (an unknown gh route, say) would pass these cases.
assert_no_verdict() {
    if [ "$2" -eq 2 ] && [[ "$3" == *"No review comment with a verdict for the head commit"* ]]; then
        pass "$1"
    else
        fail "$1" "exit: $2 (want 2, with the no-verdict message)" "output: [$3]"
    fi
}

# assert_exit <label> <want> <status> <output>
assert_exit() {
    if [ "$3" -eq "$2" ]; then
        pass "$1"
    else
        fail "$1" "exit: $3 (want $2)" "output: [$4]"
    fi
}

# =========================================================================
# Part A: scripts/check-blocking-findings.sh
# =========================================================================

run_check() {
    PATH="$STUBDIR:$PATH" "$CHECK_SCRIPT" "$@"
}

# --- A1: a clean verdict for the head -> 0 ----------------------------------
use_page "$(comment 101 "$T1" "$BOT" "$(review_body "$(verdict "$H1" 0 0)")")"
OUT="$(run_check 1 2>&1)"; S=$?
assert_exit "A1: clean verdict for the head -> exit 0" 0 "$S" "$OUT"

# --- A2: blocking=2 -> 1, and the output names the comment and counts -------
use_page "$(comment 101 "$T1" "$BOT" "$(review_body "$(verdict "$H1" 2 0)")")"
OUT="$(run_check 7 2>&1)"; S=$?
WANT="Unresolved findings in review comment 101 on PR #7: blocking=2 important=0"
if [ "$S" -eq 1 ] && [ "$OUT" = "$WANT" ]; then
    pass "A2: blocking=2 -> exit 1, stdout names the comment and the counts"
else
    fail "A2: blocking=2 -> exit 1, stdout names the comment and the counts" \
        "exit: $S (want 1)" "output: [$OUT]" "want:   [$WANT]"
fi

# --- A3: important only -> 0, and 1 with --include-important ----------------
use_page "$(comment 101 "$T1" "$BOT" "$(review_body "$(verdict "$H1" 0 3)")")"
OUT_PLAIN="$(run_check 1 2>&1)"; S_PLAIN=$?
OUT_FLAG="$(run_check 1 --include-important 2>&1)"; S_FLAG=$?
OUT_FLAG_FIRST="$(run_check --include-important 1 2>&1)"; S_FLAG_FIRST=$?
if [ "$S_PLAIN" -eq 0 ] && [ "$S_FLAG" -eq 1 ] && [ "$S_FLAG_FIRST" -eq 1 ] \
    && [ "$OUT_FLAG" = "Unresolved findings in review comment 101 on PR #1: blocking=0 important=3" ]; then
    pass "A3: important=3 -> exit 0, and exit 1 with --include-important in either position"
else
    fail "A3: important=3 -> exit 0, and exit 1 with --include-important in either position" \
        "exits: $S_PLAIN $S_FLAG $S_FLAG_FIRST (want 0 1 1)" "output: [$OUT_FLAG] [$OUT_PLAIN] [$OUT_FLAG_FIRST]"
fi

# --- A4: a verdict for an older SHA only -> 2 -------------------------------
# The review is of old code. The newest push has no review yet (#2314).
use_page "$(comment 101 "$T1" "$BOT" "$(review_body "$(verdict "$H0" 0 0)")")"
OUT="$(run_check 1 2>&1)"; S=$?
assert_no_verdict "A4: verdict for an older SHA only -> exit 2, no verdict for the head" "$S" "$OUT"

# --- A5: two verdicts for the head -> the newest wins, in either order ------
OLD_CLEAN="$(comment 101 "$T1" "$BOT" "$(review_body "$(verdict "$H1" 0 0)")")"
NEW_BLOCK="$(comment 102 "$T2" "$BOT" "$(review_body "$(verdict "$H1" 1 0)")")"
use_page "$OLD_CLEAN" "$NEW_BLOCK"
OUT1="$(run_check 1 2>&1)"; S1=$?
use_page "$NEW_BLOCK" "$OLD_CLEAN"
OUT2="$(run_check 1 2>&1)"; S2=$?
OLD_BLOCK="$(comment 101 "$T1" "$BOT" "$(review_body "$(verdict "$H1" 1 0)")")"
NEW_CLEAN="$(comment 102 "$T2" "$BOT" "$(review_body "$(verdict "$H1" 0 0)")")"
use_page "$OLD_BLOCK" "$NEW_CLEAN"
OUT3="$(run_check 1 2>&1)"; S3=$?
use_page "$NEW_CLEAN" "$OLD_BLOCK"
OUT4="$(run_check 1 2>&1)"; S4=$?
if [ "$S1" -eq 1 ] && [ "$S2" -eq 1 ] && [ "$S3" -eq 0 ] && [ "$S4" -eq 0 ]; then
    pass "A5: two verdicts for the head -> the newest wins, in both array orders"
else
    fail "A5: two verdicts for the head -> the newest wins, in both array orders" \
        "exits: $S1 $S2 $S3 $S4 (want 1 1 0 0)" "outputs: [$OUT1] [$OUT2] [$OUT3] [$OUT4]"
fi

# --- A5b: same created_at -> the higher comment ID wins ---------------------
TIE_LOW="$(comment 201 "$T1" "$BOT" "$(review_body "$(verdict "$H1" 1 0)")")"
TIE_HIGH="$(comment 305 "$T1" "$BOT" "$(review_body "$(verdict "$H1" 0 0)")")"
use_page "$TIE_HIGH" "$TIE_LOW"
OUT="$(run_check 1 2>&1)"; S=$?
assert_exit "A5b: same created_at -> the higher comment ID wins" 0 "$S" "$OUT"

# --- A6: a perfect verdict from a non-bot author does not count -> 2 --------
# A user can paste a clean verdict line. Only github-actions[bot] counts.
FORGED_LOGINS=("octocat" "claude[bot]" "github-actions-evil" "github-actions" "GitHub-Actions[bot]")
FORGERY_FAILS=""
for login in "${FORGED_LOGINS[@]}"; do
    use_page "$(comment 101 "$T1" "$login" "$(review_body "$(verdict "$H1" 0 0)")")"
    OUT="$(run_check 1 2>&1)"; S=$?
    { [ "$S" -eq 2 ] && [[ "$OUT" == *"No review comment with a verdict"* ]]; } || FORGERY_FAILS+="$login:$S "
done
if [ "${#FORGED_LOGINS[@]}" -ge 5 ] && [ -z "$FORGERY_FAILS" ]; then
    pass "A6: a clean verdict from a human, claude[bot] or a look-alike login -> exit 2"
else
    fail "A6: a clean verdict from a human, claude[bot] or a look-alike login -> exit 2" \
        "authors with a wrong exit: [$FORGERY_FAILS]"
fi

# --- A7: a newer human clean verdict after a bot blocking=1 -> still 1 ------
use_page "$(comment 101 "$T1" "$BOT" "$(review_body "$(verdict "$H1" 1 0)")")" \
    "$(comment 102 "$T3" "octocat" "$(review_body "$(verdict "$H1" 0 0)")")"
OUT="$(run_check 1 2>&1)"; S=$?
assert_exit "A7: a newer human clean verdict does not hide a bot blocking=1 -> exit 1" 1 "$S" "$OUT"

# --- A8: only the last line counts ------------------------------------------
# A clean verdict in the middle must not hide the real last-line verdict.
MID_BODY="$(printf '%s\n\n%s\n\nMore text.\n%s\n' 'Summary.' "$(verdict "$H1" 0 0)" "$(verdict "$H1" 1 0)")"
use_page "$(comment 101 "$T1" "$BOT" "$MID_BODY")"
OUT_MID="$(run_check 1 2>&1)"; S_MID=$?
# A verdict with text after it is not a verdict.
TAIL_BODY="$(printf '%s\n\n%s\nThanks for reading.\n' 'Summary.' "$(verdict "$H1" 0 0)")"
use_page "$(comment 101 "$T1" "$BOT" "$TAIL_BODY")"
OUT_TAIL="$(run_check 1 2>&1)"; S_TAIL=$?
SAME_LINE_BODY="$(printf '%s\n\n%s trailing\n' 'Summary.' "$(verdict "$H1" 0 0)")"
use_page "$(comment 101 "$T1" "$BOT" "$SAME_LINE_BODY")"
OUT_SAME="$(run_check 1 2>&1)"; S_SAME=$?
if [ "$S_MID" -eq 1 ] && [ "$S_TAIL" -eq 2 ] && [ "$S_SAME" -eq 2 ]; then
    pass "A8: last line decides (clean in the middle, blocking last -> 1) and trailing text -> 2"
else
    fail "A8: last line decides (clean in the middle, blocking last -> 1) and trailing text -> 2" \
        "exits: $S_MID $S_TAIL $S_SAME (want 1 2 2)" "outputs: [$OUT_MID] [$OUT_TAIL] [$OUT_SAME]"
fi

# --- A9: an old-format review has no verdict -> 2 ---------------------------
OLD_REVIEW="$(printf '### Strengths\nGood tests.\n\n### Blocking\n- None.\n\nClaude completed this review.\n')"
use_page "$(comment 101 "$T1" "$BOT" "$OLD_REVIEW")" \
    "$(comment 102 "$T2" "claude[bot]" "$OLD_REVIEW")"
OUT="$(run_check 1 2>&1)"; S=$?
assert_no_verdict "A9: an old-format review (Strengths, Blocking, no verdict line) -> exit 2, no verdict" "$S" "$OUT"

# --- A10: malformed verdict lines do not count -> 2 each --------------------
BAD_LINES=(
    "<!-- claude-review-verdict sha=$H1 blocking=abc important=0 -->"
    "<!-- claude-review-verdict sha=$H1 blocking=0 -->"
    "<!-- claude-review-verdict sha=$H1 blocking=0 important= -->"
    "<!--  claude-review-verdict sha=$H1 blocking=0 important=0 -->"
    "<!-- claude-review-verdict sha=$H1  blocking=0 important=0 -->"
    "<!-- claude-review-verdict sha=$H1 blocking=0 important=0  -->"
    "<!-- CLAUDE-REVIEW-VERDICT sha=$H1 blocking=0 important=0 -->"
    "<!-- claude-review-verdict sha=${H1:0:7} blocking=0 important=0 -->"
    "<!-- claude-review-verdict sha=${H1}0 blocking=0 important=0 -->"
    "<!-- claude-review-verdict sha=$(printf '%s' "$H1" | tr 'a-f' 'A-F') blocking=0 important=0 -->"
    "<!-- claude-review-verdict sha=$H1 blocking=-1 important=0 -->"
    "<!-- claude-review-verdict sha=$H1 blocking=0 important=0 --"
    "claude-review-verdict sha=$H1 blocking=0 important=0"
)
BAD_FAILS=""
n=0
for line in "${BAD_LINES[@]}"; do
    n=$((n + 1))
    use_page "$(comment 101 "$T1" "$BOT" "$(review_body "$line")")"
    OUT="$(run_check 1 2>&1)"; S=$?
    { [ "$S" -eq 2 ] && [[ "$OUT" == *"No review comment with a verdict"* ]]; } || BAD_FAILS+="#$n:$S "
done
if [ "${#BAD_LINES[@]}" -ge 10 ] && [ -z "$BAD_FAILS" ]; then
    pass "A10: ${#BAD_LINES[@]} malformed verdict lines -> exit 2 each"
else
    fail "A10: ${#BAD_LINES[@]} malformed verdict lines -> exit 2 each" \
        "lines with a wrong exit (number:exit): [$BAD_FAILS]"
fi

# --- A11: gh failures -> 2, and a verdict that only page 2 holds is found ---
use_page "$(comment 101 "$T1" "$BOT" "$(review_body "$(verdict "$H1" 0 0)")")"
OUT_ALL="$(STUB_GH_FAIL=1 run_check 1 2>&1)"; S_ALL=$?
OUT_HEAD="$(STUB_GH_FAIL_PR_VIEW=1 run_check 1 2>&1)"; S_HEAD=$?
printf '{"headRefOid": ""}\n' > "$TMPROOT/pr-empty.json"
OUT_EMPTY="$(STUB_PR_JSON="$TMPROOT/pr-empty.json" run_check 1 2>&1)"; S_EMPTY=$?
if [ "$S_ALL" -eq 2 ] && [ "$S_HEAD" -eq 2 ] && [ "$S_EMPTY" -eq 2 ]; then
    pass "A11: gh fails, head lookup fails, or head SHA is empty -> exit 2"
else
    fail "A11: gh fails, head lookup fails, or head SHA is empty -> exit 2" \
        "exits: $S_ALL $S_HEAD $S_EMPTY (want 2 2 2)" "outputs: [$OUT_ALL] [$OUT_HEAD] [$OUT_EMPTY]"
fi

comment 101 "$T1" "octocat" "A human comment on page one." | jq -s '.' > "$TMPROOT/p1.json"
comment 140 "$T2" "$BOT" "$(review_body "$(verdict "$H1" 1 0)")" | jq -s '.' > "$TMPROOT/p2.json"
: > "$STUB_LOG"
OUT_P2="$(STUB_PAGE1="$TMPROOT/p1.json" STUB_PAGE2="$TMPROOT/p2.json" run_check 1 2>&1)"; S_P2=$?
if [ "$S_P2" -eq 1 ] && grep -q -- '--paginate' "$STUB_LOG"; then
    pass "A11b: the only verdict is on page 2 -> found (exit 1 for its blocking=1)"
else
    fail "A11b: the only verdict is on page 2 -> found (exit 1 for its blocking=1)" \
        "exit: $S_P2 (want 1)" "output: [$OUT_P2]"
fi

# --- A12: usage errors -> exit 2 ---------------------------------------------
USAGE_FAILS=""
run_check >/dev/null 2>&1; S=$?; [ "$S" -eq 2 ] || USAGE_FAILS+="none:$S "
run_check abc >/dev/null 2>&1; S=$?; [ "$S" -eq 2 ] || USAGE_FAILS+="abc:$S "
run_check 1 2 >/dev/null 2>&1; S=$?; [ "$S" -eq 2 ] || USAGE_FAILS+="two:$S "
run_check --bogus >/dev/null 2>&1; S=$?; [ "$S" -eq 2 ] || USAGE_FAILS+="bogus:$S "
run_check --include-important >/dev/null 2>&1; S=$?; [ "$S" -eq 2 ] || USAGE_FAILS+="flag-only:$S "
if [ -z "$USAGE_FAILS" ]; then
    pass "A12: no PR number, non-numeric, two numbers, unknown flag, flag only -> exit 2"
else
    fail "A12: no PR number, non-numeric, two numbers, unknown flag, flag only -> exit 2" \
        "wrong exits: [$USAGE_FAILS]"
fi

# --- A13: CRLF line ends and a trailing newline are accepted ----------------
CRLF_BODY="$(printf 'Summary.\r\n\r\n%s\r\n' "$(verdict "$H1" 0 0)")"
use_page "$(comment 101 "$T1" "$BOT" "$CRLF_BODY")"
OUT="$(run_check 1 2>&1)"; S=$?
assert_exit "A13: a CRLF body with a trailing newline still counts -> exit 0" 0 "$S" "$OUT"

# --- A15: the three verdict-rule blocks are byte-identical ------------------
# Each script keeps its own copy of the rule. A copy that drifts accepts a
# verdict that the others reject.
POSTED_SCRIPT="${CHECK_POSTED_SCRIPT:-$REAL_REPO/scripts/check-review-posted.sh}"

# verdict_block <file>: prints the lines from the "# --- Verdict rule" line to
# the "# --- End of verdict rule" line.
verdict_block() {
    awk '/^# --- Verdict rule/{f=1} f{print} /^# --- End of verdict rule/{f=0}' "$1"
}

# blocks_identical <file>...: 0 when each file has a block and all blocks match.
blocks_identical() {
    local first file block
    first="$(verdict_block "$1")"
    [ -n "$first" ] && [[ "$first" == *"# --- End of verdict rule"* ]] || return 1
    for file in "$@"; do
        block="$(verdict_block "$file")"
        [ "$block" = "$first" ] || return 1
    done
    return 0
}

blocks_identical "$CHECK_SCRIPT" "$VERIFY_SCRIPT" "$POSTED_SCRIPT"; S_REAL=$?
# The mutated copy proves the check can fail.
MUTANT="$TMPROOT/mutant-check.sh"
sed '/^# --- Verdict rule/,/^# --- End of verdict rule/s/important=(\[0123456789\]+)/important=([0-9]+)/' \
    "$CHECK_SCRIPT" > "$MUTANT"
blocks_identical "$MUTANT" "$VERIFY_SCRIPT" "$POSTED_SCRIPT"; S_MUT=$?
if [ "$S_REAL" -eq 0 ] && [ "$S_MUT" -ne 0 ] && ! cmp -s "$MUTANT" "$CHECK_SCRIPT"; then
    pass "A15: the three verdict-rule blocks are byte-identical, and a changed copy fails the check"
else
    fail "A15: the three verdict-rule blocks are byte-identical, and a changed copy fails the check" \
        "real scripts: $S_REAL (want 0)" "mutated copy: $S_MUT (want non-zero)"
fi

# =========================================================================
# Part B: scripts/verify-ci-review.sh
# =========================================================================

VSCRIPTS="$TMPROOT/vscripts"
mkdir -p "$VSCRIPTS"
cp "$VERIFY_SCRIPT" "$VSCRIPTS/verify-ci-review.sh"
chmod +x "$VSCRIPTS/verify-ci-review.sh"

POLL_COUNT_FILE="$TMPROOT/poll-count"
CHECK_ARGS_LOG="$TMPROOT/check-args.log"
export CHECK_ARGS_LOG

# Built once: the matching path, then 50000 more. That is about 540 KB, far
# past a pipe buffer. The #2225 bug showed every time from 10000 lines up.
DIFF_MATCH_FILE="$TMPROOT/gh-diff-match.txt"
{
    printf '%s\n' '.github/workflows/claude-code-review.yml'
    seq 1 50000 | sed 's|^|app/f|'
} > "$DIFF_MATCH_FILE"
export DIFF_MATCH_FILE

# Stub poll-review-posted.sh. The first call exits STUB_POLL_EXIT. When
# STUB_POLL2_EXIT is set, the second call exits with it instead. A counter
# file keeps the call number.
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

# Stub check-blocking-findings.sh. It writes each argument as `[arg]` on its
# own line to CHECK_ARGS_LOG, then exits STUB_CHECK_EXIT. The brackets keep an
# empty argument visible.
cat > "$VSCRIPTS/check-blocking-findings.sh" <<'STUB'
#!/bin/bash
if [ -n "${CHECK_ARGS_LOG:-}" ]; then
    printf '[%s]\n' "$@" > "$CHECK_ARGS_LOG"
fi
exit "${STUB_CHECK_EXIT:-0}"
STUB
chmod +x "$VSCRIPTS/check-blocking-findings.sh"

# Routes the shared stub does not cover. The shared stub runs this hook first.
# Exit 3 means "not handled", and the stub goes on to its own routes.
EXTRA="$TMPROOT/extra-routes.sh"
cat > "$EXTRA" <<'STUB'
#!/bin/bash
args="$*"
case "$args" in
    "pr view "*"--json title"*)
        if [ "${STUB_TITLE_FAIL:-0}" = "1" ]; then
            echo "gh: simulated title lookup failure (stub)" >&2
            exit 1
        fi
        printf '%s\n' "${STUB_TITLE:-}"
        exit 0
        ;;
    "pr view "*"--json labels"*)
        printf '%s' "${STUB_LABELS:-}"
        exit 0
        ;;
    "api repos/"*"/pulls/"*"/files"*)
        case "${STUB_DIFF_MODE:-}" in
            large-match)
                # exec, so the exit code is the SIGPIPE code of cat. An
                # `exit 0` would hide the code that the old bug read.
                exec cat "${DIFF_MATCH_FILE:?DIFF_MATCH_FILE not set}"
                ;;
            small-match)
                printf '%s\n' 'app/index.php' '.github/workflows/claude-code-review.yml'
                exit 0
                ;;
            fail)
                echo "gh: simulated PR file list failure (stub)" >&2
                exit 1
                ;;
            *) exit 0 ;;
        esac
        ;;
    "workflow run "*)
        if [ "${STUB_WORKFLOW_RUN_FAIL:-0}" = "1" ]; then
            echo "gh: simulated workflow run failure (stub)" >&2
            exit 1
        fi
        exit 0
        ;;
    "pr edit "*)
        if [ "${STUB_PR_EDIT_FAIL:-0}" = "1" ]; then
            echo "gh: simulated pr edit failure (stub)" >&2
            exit 1
        fi
        exit 0
        ;;
esac
exit 3
STUB
chmod +x "$EXTRA"

# The default fixture is a verdict for the head. A case that tests freshness
# replaces it with use_page.
FRESH_COMMENT="$(comment 101 "$T1" "$BOT" "$(review_body "$(verdict "$H1" 0 0)")")"
use_page "$FRESH_COMMENT"

reset_verify_state() {
    rm -f "$POLL_COUNT_FILE" "$CHECK_ARGS_LOG"
    : > "$STUB_LOG"
    : > "$CHECK_ARGS_LOG"
}

run_verify() {
    reset_verify_state
    STUB_POLL_COUNT_FILE="$POLL_COUNT_FILE" STUB_EXTRA="$EXTRA" PATH="$STUBDIR:$PATH" \
        bash "$VSCRIPTS/verify-ci-review.sh" 1 1 1 --trigger=workflow "$@"
}

# shellcheck disable=SC2119,SC2120 # "$@" stays for cases that need extra flags
run_verify_label() {
    reset_verify_state
    STUB_POLL_COUNT_FILE="$POLL_COUNT_FILE" STUB_EXTRA="$EXTRA" PATH="$STUBDIR:$PATH" \
        bash "$VSCRIPTS/verify-ci-review.sh" 1 1 1 --trigger=label "$@"
}

# shellcheck disable=SC2119,SC2120 # "$@" stays for cases that need extra flags
run_verify_none() {
    reset_verify_state
    STUB_POLL_COUNT_FILE="$POLL_COUNT_FILE" STUB_EXTRA="$EXTRA" PATH="$STUBDIR:$PATH" \
        bash "$VSCRIPTS/verify-ci-review.sh" 1 1 1 --trigger=none "$@"
}

# --- B1: a verdict for the head -> reads the findings, no recovery ----------
OUT="$(STUB_POLL_EXIT=0 STUB_CHECK_EXIT=0 run_verify 2>&1)"; S=$?
ARGS_PLAIN="$(cat "$CHECK_ARGS_LOG")"
LOG_PLAIN="$(cat "$STUB_LOG")"
OUT_FLAG="$(STUB_POLL_EXIT=0 STUB_CHECK_EXIT=0 run_verify --include-important 2>&1)"; S_FLAG=$?
ARGS_FLAG="$(cat "$CHECK_ARGS_LOG")"
if [ "$S" -eq 0 ] && [ "$S_FLAG" -eq 0 ] \
    && [ "$ARGS_PLAIN" = "[1]" ] \
    && [ "$ARGS_FLAG" = "$(printf '[1]\n[--include-important]')" ] \
    && ! grep -q 'workflow run' <<< "$LOG_PLAIN"; then
    pass "B1: a verdict for the head -> exit 0, check-blocking-findings.sh gets [1] (and the flag when set), no recovery"
else
    fail "B1: a verdict for the head -> exit 0, check-blocking-findings.sh gets [1] (and the flag when set), no recovery" \
        "exits: $S $S_FLAG (want 0 0)" "args: [$ARGS_PLAIN] [$ARGS_FLAG]" "output: [$OUT] [$OUT_FLAG]"
fi

# --- B2: a verdict for an older SHA is not fresh -> recovery, then 4 --------
# The old rule compared the comment time with a push time from the activity
# API. The new rule needs no such call. So the log must not show one.
use_page "$(comment 101 "$T1" "$BOT" "$(review_body "$(verdict "$H0" 0 0)")")"
OUT="$(STUB_POLL_EXIT=0 STUB_CHECK_EXIT=0 run_verify 2>&1)"; S=$?
if [ "$S" -eq 4 ] && [ ! -s "$CHECK_ARGS_LOG" ] \
    && grep -q 'workflow run claude-code-review.yml' "$STUB_LOG" \
    && ! grep -q 'activity' "$STUB_LOG" \
    && grep -q 'no review verdict for its head commit' <<< "$OUT"; then
    pass "B2: a verdict for an older SHA -> recovery sent, exit 4, findings not read, no activity-API call"
else
    fail "B2: a verdict for an older SHA -> recovery sent, exit 4, findings not read, no activity-API call" \
        "exit: $S (want 4)" "check args: [$(cat "$CHECK_ARGS_LOG")] (want empty)" \
        "log: [$(cat "$STUB_LOG")]" "output: [$OUT]"
fi

# --- B3: no verdict at all (human, claude[bot], old-format) -> not fresh ----
# A human forgery, a claude[bot] verdict and an old-format review must not
# count as a review.
use_page "$(comment 101 "$T1" "octocat" "$(review_body "$(verdict "$H1" 0 0)")")" \
    "$(comment 102 "$T2" "$BOT" "$(printf '### Strengths\nFine.\n')")" \
    "$(comment 103 "$T3" "claude[bot]" "$(review_body "$(verdict "$H1" 0 0)")")"
OUT="$(STUB_POLL_EXIT=0 STUB_CHECK_EXIT=0 run_verify 2>&1)"; S=$?
LOG_WF="$(cat "$STUB_LOG")"
OUT_NONE="$(STUB_POLL_EXIT=0 STUB_CHECK_EXIT=0 run_verify_none 2>&1)"; S_NONE=$?
LOG_NONE="$(cat "$STUB_LOG")"
if [ "$S" -eq 4 ] && grep -q 'workflow run' <<< "$LOG_WF" \
    && [ "$S_NONE" -eq 4 ] && ! grep -q 'workflow run' <<< "$LOG_NONE" \
    && grep -q 'no recovery attempted' <<< "$OUT_NONE"; then
    pass "B3: no bot verdict -> exit 4 (recovery sent with --trigger=workflow, none with --trigger=none)"
else
    fail "B3: no bot verdict -> exit 4 (recovery sent with --trigger=workflow, none with --trigger=none)" \
        "exits: $S $S_NONE (want 4 4)" "output: [$OUT] [$OUT_NONE]"
fi

# --- B3b: a verdict that only page 2 holds counts as fresh ------------------
printf '%s\n' "$FRESH_COMMENT" | jq -s '.' > "$TMPROOT/p2b.json"
OUT="$(STUB_PAGE1="$TMPROOT/p1.json" STUB_PAGE2="$TMPROOT/p2b.json" \
    STUB_POLL_EXIT=0 STUB_CHECK_EXIT=0 run_verify 2>&1)"; S=$?
use_page "$FRESH_COMMENT"
assert_exit "B3b: the verdict for the head is on page 2 only -> fresh, exit 0" 0 "$S" "$OUT"

# --- B4: gh fails -> exit 1 (could not verify, not "no review") -------------
OUT_ALL="$(STUB_POLL_EXIT=0 STUB_CHECK_EXIT=0 STUB_GH_FAIL=1 run_verify 2>&1)"; S_ALL=$?
OUT_HEAD="$(STUB_POLL_EXIT=0 STUB_CHECK_EXIT=0 STUB_GH_FAIL_PR_VIEW=1 run_verify 2>&1)"; S_HEAD=$?
if [ "$S_ALL" -eq 1 ] && [ "$S_HEAD" -eq 1 ] && ! grep -q 'workflow run' "$STUB_LOG"; then
    pass "B4: gh fails, or the head lookup fails -> exit 1, no recovery"
else
    fail "B4: gh fails, or the head lookup fails -> exit 1, no recovery" \
        "exits: $S_ALL $S_HEAD (want 1 1)" "outputs: [$OUT_ALL] [$OUT_HEAD]"
fi

# --- B5: the exit code of the findings check maps to 0, 2 and 1 -------------
STUB_POLL_EXIT=0 STUB_CHECK_EXIT=0 run_verify >/dev/null 2>&1; S0=$?
STUB_POLL_EXIT=0 STUB_CHECK_EXIT=1 run_verify >/dev/null 2>&1; S1=$?
STUB_POLL_EXIT=0 STUB_CHECK_EXIT=2 run_verify >/dev/null 2>&1; S2=$?
if [ "$S0" -eq 0 ] && [ "$S1" -eq 2 ] && [ "$S2" -eq 1 ]; then
    pass "B5: findings check 0 -> 0, 1 (blocking) -> 2, 2 (cannot verify) -> 1"
else
    fail "B5: findings check 0 -> 0, 1 (blocking) -> 2, 2 (cannot verify) -> 1" \
        "exits: $S0 $S1 $S2 (want 0 2 1)"
fi

# --- B6: the poll result maps to the exit code ------------------------------
STUB_POLL_EXIT=1 STUB_POLL2_EXIT=2 run_verify >/dev/null 2>&1; SA=$?
STUB_POLL_EXIT=1 STUB_POLL2_EXIT=1 run_verify >/dev/null 2>&1; SB=$?
POLLS_B="$(cat "$POLL_COUNT_FILE")"
STUB_POLL_EXIT=2 run_verify >/dev/null 2>&1; SC=$?
STUB_POLL_EXIT=1 STUB_POLL2_EXIT=0 run_verify >/dev/null 2>&1; SD=$?
POLLS_D="$(cat "$POLL_COUNT_FILE")"
if [ "$SA" -eq 1 ] && [ "$SB" -eq 4 ] && [ "$POLLS_B" = "2" ] && [ "$SC" -eq 1 ] \
    && [ "$SD" -eq 0 ] && [ "$POLLS_D" = "2" ]; then
    pass "B6: poll 1 then 2 -> 1, poll 1 then 1 -> 4, poll 2 -> 1, poll 1 then 0 -> 0 (one recovery)"
else
    fail "B6: poll 1 then 2 -> 1, poll 1 then 1 -> 4, poll 2 -> 1, poll 1 then 0 -> 0 (one recovery)" \
        "exits: $SA $SB $SC $SD (want 1 4 1 0)" "polls: $POLLS_B $POLLS_D (want 2 2)"
fi

# --- B7: a large PR file list with an early match (#2225) -------------------
# STUB_POLL_EXIT=1 forces the recovery path to the workflow-file guard. A
# piped `grep -q` would SIGPIPE gh here and, under pipefail, read that as "no
# match". The guard must still see the match, skip recovery, and exit 4 after
# one poll. The poll count and the missing `workflow run` line are the checks
# that catch the bug.
OUT="$(STUB_POLL_EXIT=1 STUB_CHECK_EXIT=0 STUB_DIFF_MODE=large-match run_verify 2>&1)"; S=$?
POLLS="$(cat "$POLL_COUNT_FILE" 2>/dev/null || echo '?')"
if [ "$S" -eq 4 ] && [ "$POLLS" = "1" ] && ! grep -q 'workflow run' "$STUB_LOG" \
    && grep -q 'claude-code-review.yml itself' <<< "$OUT"; then
    pass "B7: PR changes the review workflow (large file list) -> exit 4 with the explanation, no recovery run, one poll"
else
    fail "B7: PR changes the review workflow (large file list) -> exit 4 with the explanation, no recovery run, one poll" \
        "exit: $S (want 4)" "polls: $POLLS (want 1)" "output: [$OUT]"
fi

OUT="$(STUB_POLL_EXIT=1 STUB_DIFF_MODE=small-match run_verify 2>&1)"; S=$?
if [ "$S" -eq 4 ] && ! grep -q 'workflow run' "$STUB_LOG"; then
    pass "B7b: PR changes the review workflow (short file list) -> exit 4, no recovery run"
else
    fail "B7b: PR changes the review workflow (short file list) -> exit 4, no recovery run" \
        "exit: $S (want 4)" "output: [$OUT]"
fi

# --- B8: the PR file list call fails -> 1 -----------------------------------
OUT="$(STUB_POLL_EXIT=1 STUB_DIFF_MODE=fail run_verify 2>&1)"; S=$?
if [ "$S" -eq 1 ] && grep -q 'file list could not be read' <<< "$OUT" \
    && ! grep -q 'workflow run' "$STUB_LOG"; then
    pass "B8: PR file list call fails -> exit 1, message names the failure, no recovery run"
else
    fail "B8: PR file list call fails -> exit 1, message names the failure, no recovery run" \
        "exit: $S (want 1)" "output: [$OUT]"
fi

# --- B9: recovery 'gh workflow run' or 'gh pr edit' fails -> 1 --------------
# A failed trigger must not read as "Recovery trigger sent" and then as "no
# review posted".
OUT_WF="$(STUB_POLL_EXIT=1 STUB_WORKFLOW_RUN_FAIL=1 run_verify 2>&1)"; S_WF=$?
OUT_ED="$(STUB_POLL_EXIT=1 STUB_PR_EDIT_FAIL=1 run_verify_label 2>&1)"; S_ED=$?
if [ "$S_WF" -eq 1 ] && grep -q 'recovery trigger failed' <<< "$OUT_WF" \
    && grep -q 'simulated workflow run failure' <<< "$OUT_WF" \
    && ! grep -q 'Recovery trigger sent' <<< "$OUT_WF" \
    && [ "$S_ED" -eq 1 ] && grep -q 'recovery trigger failed' <<< "$OUT_ED" \
    && grep -q 'simulated pr edit failure' <<< "$OUT_ED" \
    && ! grep -q 'Recovery trigger sent' <<< "$OUT_ED"; then
    pass "B9: recovery trigger fails (workflow or label) -> exit 1, message shown, no 'Recovery trigger sent'"
else
    fail "B9: recovery trigger fails (workflow or label) -> exit 1, message shown, no 'Recovery trigger sent'" \
        "exits: $S_WF $S_ED (want 1 1)" "outputs: [$OUT_WF] [$OUT_ED]"
fi

# --- B10: [skip-review] title -> exit 3, and a failed title lookup warns ----
OUT_SKIP="$(STUB_POLL_EXIT=1 STUB_TITLE='docs: tidy [skip-review]' run_verify --check-skip-tag 2>&1)"; S_SKIP=$?
LOG_SKIP="$(cat "$STUB_LOG")"
OUT_FAIL="$(STUB_POLL_EXIT=1 STUB_TITLE_FAIL=1 run_verify --check-skip-tag 2>&1)"; S_FAIL=$?
if [ "$S_SKIP" -eq 3 ] && ! grep -q 'workflow run' <<< "$LOG_SKIP" \
    && grep -q 'could not read the PR title' <<< "$OUT_FAIL" && [ "$S_FAIL" -ne 3 ]; then
    pass "B10: [skip-review] title -> exit 3, no recovery. A failed title lookup prints a warning"
else
    fail "B10: [skip-review] title -> exit 3, no recovery. A failed title lookup prints a warning" \
        "exits: $S_SKIP $S_FAIL (want 3, not 3)" "outputs: [$OUT_SKIP] [$OUT_FAIL]"
fi

# --- B11: label recovery removes an existing deep-review label first --------
OUT_HAS="$(STUB_POLL_EXIT=1 STUB_POLL2_EXIT=0 STUB_LABELS="$(printf 'bug\ndeep-review')" run_verify_label 2>&1)"; S_HAS=$?
EDITS_HAS="$(grep 'pr edit' "$STUB_LOG")"
WANT_HAS="$(printf '%s\n%s' 'pr edit 1 --remove-label deep-review --repo elan-registry/registry' \
    'pr edit 1 --add-label deep-review --repo elan-registry/registry')"
OUT_NO="$(STUB_POLL_EXIT=1 STUB_POLL2_EXIT=0 STUB_LABELS='bug' run_verify_label 2>&1)"; S_NO=$?
EDITS_NO="$(grep 'pr edit' "$STUB_LOG")"
if [ "$S_HAS" -eq 0 ] && [ "$EDITS_HAS" = "$WANT_HAS" ] \
    && [ "$S_NO" -eq 0 ] && [ "$EDITS_NO" = 'pr edit 1 --add-label deep-review --repo elan-registry/registry' ]; then
    pass "B11: label recovery removes deep-review first only when the PR has it"
else
    fail "B11: label recovery removes deep-review first only when the PR has it" \
        "exits: $S_HAS $S_NO (want 0 0)" "edits: [$EDITS_HAS]" "edits without label: [$EDITS_NO]" \
        "outputs: [$OUT_HAS] [$OUT_NO]"
fi

harness_report
