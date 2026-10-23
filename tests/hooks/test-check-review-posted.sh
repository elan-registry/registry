#!/bin/bash
#
# Regression test for scripts/check-review-posted.sh (#2314, #2506).
#
# The script counts the review verdicts for the head commit of a PR, on all
# pages of the PR comments. A comment counts only when the author is
# github-actions[bot], the last line of the body is a verdict line, and the
# SHA in the verdict is the head commit:
#
#   <!-- claude-review-verdict sha=<40 hex> blocking=<n> important=<n> -->
#
# The script keeps an exit-code contract: exit 0 with a count on stdout, or
# exit 1 with nothing on stdout when a gh call fails.
#
# HERMETIC: the shared stub in lib/gh-stub.sh goes first on PATH. It runs the
# real jq filter of the script on fixture JSON, once for each page, as real
# gh does with --paginate. It reads page 2 only with --paginate. No network
# and no real gh.
#
# Environment (optional): CHECK_POSTED_SCRIPT points the test at another copy
# of the script. The proof that a case can fail uses it with the file of
# origin/main.
#
# Usage: bash tests/hooks/test-check-review-posted.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"
# shellcheck source=/dev/null
. "$(dirname "$0")/lib/gh-stub.sh"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
CHECK_SCRIPT="${CHECK_POSTED_SCRIPT:-$REAL_REPO/scripts/check-review-posted.sh}"
if [ ! -x "$CHECK_SCRIPT" ]; then
    echo "FAIL: $CHECK_SCRIPT not found or not executable" >&2
    exit 1
fi

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

# A fixture SHA with a wrong length would make the "old SHA" case pass for
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

# write_page <file> <comment-json>...: a page of comments as a JSON array.
write_page() {
    local file="$1"
    shift
    if [ "$#" -eq 0 ]; then
        echo '[]' > "$file"
    else
        printf '%s\n' "$@" | jq -s '.' > "$file"
    fi
}

printf '{"headRefOid": "%s"}\n' "$H1" > "$TMPROOT/pr.json"
STUB_PR_JSON="$TMPROOT/pr.json"
STUB_LOG="$TMPROOT/gh.log"
export STUB_PR_JSON STUB_LOG

# run_count <page1-file> [<page2-file>]: run the script on the fixtures. The
# script sends stdout to $TMPROOT/out and stderr to $TMPROOT/err.
run_count() {
    unset STUB_COMMENTS STUB_PAGE1 STUB_PAGE2
    STUB_PAGE1="$1"
    [ -n "${2:-}" ] && STUB_PAGE2="$2"
    export STUB_PAGE1
    [ -n "${2:-}" ] && export STUB_PAGE2
    : > "$STUB_LOG"
    PATH="$STUBDIR:$PATH" "$CHECK_SCRIPT" 1 > "$TMPROOT/out" 2> "$TMPROOT/err"
}

GOOD_H1="$(comment 101 "$T1" "$BOT" "$(review_body "$(verdict "$H1" 0 0)")")"
GOOD_H1_B="$(comment 102 "$T2" "$BOT" "$(review_body "$(verdict "$H1" 1 2)")")"

# --- C1: two verdicts for the head -> prints 2 -------------------------------
write_page "$TMPROOT/c1.json" "$GOOD_H1" "$GOOD_H1_B"
run_count "$TMPROOT/c1.json"; S=$?
OUT="$(cat "$TMPROOT/out")"
if [ "$S" -eq 0 ] && [ "$OUT" = "2" ]; then
    pass "C1: two verdicts for the head -> prints 2, exit 0"
else
    fail "C1: two verdicts for the head -> prints 2, exit 0" \
        "exit: $S (want 0)" "stdout: [$OUT] (want [2])" "stderr: [$(cat "$TMPROOT/err")]"
fi

# --- C2: a verdict for an older SHA only -> prints 0 -------------------------
write_page "$TMPROOT/c2.json" "$(comment 101 "$T1" "$BOT" "$(review_body "$(verdict "$H0" 0 0)")")"
run_count "$TMPROOT/c2.json"; S=$?
OUT="$(cat "$TMPROOT/out")"
if [ "$S" -eq 0 ] && [ "$OUT" = "0" ]; then
    pass "C2: a verdict for an older SHA only -> prints 0, exit 0"
else
    fail "C2: a verdict for an older SHA only -> prints 0, exit 0" \
        "exit: $S (want 0)" "stdout: [$OUT] (want [0])"
fi

# --- C3: a verdict from a human or claude[bot] does not count ----------------
write_page "$TMPROOT/c3.json" \
    "$(comment 101 "$T1" "octocat" "$(review_body "$(verdict "$H1" 0 0)")")" \
    "$(comment 102 "$T1" "claude[bot]" "$(review_body "$(verdict "$H1" 0 0)")")" \
    "$(comment 103 "$T2" "github-actions-evil" "$(review_body "$(verdict "$H1" 0 0)")")"
run_count "$TMPROOT/c3.json"; S=$?
OUT="$(cat "$TMPROOT/out")"
if [ "$S" -eq 0 ] && [ "$OUT" = "0" ]; then
    pass "C3: verdicts from a human, claude[bot] and a look-alike login -> prints 0"
else
    fail "C3: verdicts from a human, claude[bot] and a look-alike login -> prints 0" \
        "exit: $S (want 0)" "stdout: [$OUT] (want [0])"
fi

# --- C4: the verdict is not the last line -> prints 0 ------------------------
NOT_LAST="$(printf '%s\n\n%s\n\nThanks for reading.\n' 'Summary.' "$(verdict "$H1" 0 0)")"
write_page "$TMPROOT/c4.json" "$(comment 101 "$T1" "$BOT" "$NOT_LAST")"
run_count "$TMPROOT/c4.json"; S=$?
OUT="$(cat "$TMPROOT/out")"
if [ "$S" -eq 0 ] && [ "$OUT" = "0" ]; then
    pass "C4: a verdict that is not the last line -> prints 0"
else
    fail "C4: a verdict that is not the last line -> prints 0" \
        "exit: $S (want 0)" "stdout: [$OUT] (want [0])"
fi

# --- C5: an old-format review -> prints 0 ------------------------------------
OLD_REVIEW="$(printf '### Strengths\nGood tests.\n\n### Blocking\n- None.\n\nClaude completed this review.\n')"
write_page "$TMPROOT/c5.json" "$(comment 101 "$T1" "$BOT" "$OLD_REVIEW")" \
    "$(comment 102 "$T2" "claude[bot]" "$OLD_REVIEW")"
run_count "$TMPROOT/c5.json"; S=$?
OUT="$(cat "$TMPROOT/out")"
if [ "$S" -eq 0 ] && [ "$OUT" = "0" ]; then
    pass "C5: an old-format review (Strengths, Blocking, no verdict line) -> prints 0"
else
    fail "C5: an old-format review (Strengths, Blocking, no verdict line) -> prints 0" \
        "exit: $S (want 0)" "stdout: [$OUT] (want [0])"
fi

# --- C6: one verdict on each of two pages -> prints 2 ------------------------
# Without --paginate the script sees page 1 only and prints 1.
write_page "$TMPROOT/c6a.json" "$GOOD_H1"
write_page "$TMPROOT/c6b.json" "$GOOD_H1_B"
run_count "$TMPROOT/c6a.json" "$TMPROOT/c6b.json"; S=$?
OUT="$(cat "$TMPROOT/out")"
if [ "$S" -eq 0 ] && [ "$OUT" = "2" ] && grep -q -- '--paginate' "$STUB_LOG"; then
    pass "C6: one verdict on each of two pages -> prints 2, exit 0"
else
    fail "C6: one verdict on each of two pages -> prints 2, exit 0" \
        "exit: $S (want 0)" "stdout: [$OUT] (want [2])"
fi

# The only verdict is on page 2 (a PR with more than 30 comments).
write_page "$TMPROOT/c6c.json" "$(comment 90 "$T1" "octocat" "A human comment.")"
run_count "$TMPROOT/c6c.json" "$TMPROOT/c6b.json"; S=$?
OUT="$(cat "$TMPROOT/out")"
if [ "$S" -eq 0 ] && [ "$OUT" = "1" ]; then
    pass "C6b: the only verdict is on page 2 -> prints 1, exit 0"
else
    fail "C6b: the only verdict is on page 2 -> prints 1, exit 0" \
        "exit: $S (want 0)" "stdout: [$OUT] (want [1])"
fi

# --- C7: gh fails -> exit 1, nothing on stdout -------------------------------
write_page "$TMPROOT/c7.json" "$GOOD_H1"
STUB_GH_FAIL=1 run_count "$TMPROOT/c7.json"; S=$?
OUT="$(cat "$TMPROOT/out")"
ERR="$(cat "$TMPROOT/err")"
if [ "$S" -eq 1 ] && [ -z "$OUT" ] && [[ "$ERR" == *"cannot verify"* ]]; then
    pass "C7: gh call fails -> exit 1, empty stdout, 'cannot verify' on stderr"
else
    fail "C7: gh call fails -> exit 1, empty stdout, 'cannot verify' on stderr" \
        "exit: $S (want 1)" "stdout: [$OUT] (want empty)" "stderr: [$ERR]"
fi

# --- C8: the head lookup fails -> exit 1, nothing on stdout ------------------
STUB_GH_FAIL_PR_VIEW=1 run_count "$TMPROOT/c7.json"; S=$?
OUT="$(cat "$TMPROOT/out")"
ERR="$(cat "$TMPROOT/err")"
printf '{"headRefOid": ""}\n' > "$TMPROOT/pr-empty.json"
STUB_PR_JSON="$TMPROOT/pr-empty.json" run_count "$TMPROOT/c7.json"; S_EMPTY=$?
OUT_EMPTY="$(cat "$TMPROOT/out")"
if [ "$S" -eq 1 ] && [ -z "$OUT" ] && [[ "$ERR" == *"head commit"* ]] \
    && [ "$S_EMPTY" -eq 1 ] && [ -z "$OUT_EMPTY" ]; then
    pass "C8: head lookup fails, or the head SHA is empty -> exit 1, empty stdout"
else
    fail "C8: head lookup fails, or the head SHA is empty -> exit 1, empty stdout" \
        "exits: $S $S_EMPTY (want 1 1)" "stdout: [$OUT] [$OUT_EMPTY] (want empty)" "stderr: [$ERR]"
fi

harness_report
