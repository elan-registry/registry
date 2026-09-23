#!/bin/bash
#
# Regression test for .githooks/pre-push's integration-gate logic (#2160):
# _gate_base_for_ref, _gated_files_for_ref, the cache helpers, and the hook
# as a whole. Cases F-J pin pushes that earlier diff-narrowing rules wrongly
# skipped.
#
# HERMETIC: every scenario runs inside a throwaway repo created by mktemp -d,
# with its own synthetic origin refs, its own .git, and a stub `composer`
# first on PATH. Nothing here reads or writes the real repo's refs, objects,
# config or working tree — the only thing taken from it is the text of
# .githooks/pre-push. No real `git push` is ever run.
#
# Usage: bash tests/hooks/test-pre-push-gate.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

# Run from inside a hook or rebase, these would point every git command
# below at the real repo instead of the throwaway one.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY

export GIT_AUTHOR_NAME="test-pre-push-gate"
export GIT_AUTHOR_EMAIL="test-pre-push-gate@localhost"
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME"
export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"

ZERO="0000000000000000000000000000000000000000"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
HOOK_SRC="$REAL_REPO/.githooks/pre-push"
if [ ! -f "$HOOK_SRC" ]; then
    echo "FAIL: $HOOK_SRC not found" >&2
    exit 1
fi
HOOK_TEXT="$(cat "$HOOK_SRC")"
REAL_GIT="$(command -v git)"

TMPROOT="$(mktemp -d)" || exit 1
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

assert_eq() {
    local description="$1" expected="$2" actual="$3"
    if [ "$expected" = "$actual" ]; then
        pass "$description"
    else
        fail "$description" "expected: [${expected}]" "actual:   [${actual}]"
    fi
}

# --- Build the hermetic repo -------------------------------------------

REPO="$TMPROOT/repo"
mkdir -p "$REPO" || exit 1
cd "$REPO" || exit 1
git init -q . >/dev/null 2>&1
git config user.name "$GIT_AUTHOR_NAME"
git config user.email "$GIT_AUTHOR_EMAIL"
git config commit.gpgsign false
git symbolic-ref HEAD refs/heads/main

mkdir -p app usersc/classes tests/integration docs

commit_all() {
    git add -A >/dev/null 2>&1
    git commit -q -m "$1" >/dev/null 2>&1 || {
        echo "FATAL: commit failed: $1" >&2
        exit 1
    }
    git rev-parse HEAD
}

write_file() { printf '%s\n' "$2" > "$1"; }

# The test DB config and the hook copy live in the working tree but are
# ignored, so `commit_all` (git add -A) never commits them and the tracked
# tree stays clean for cache recording.
printf '%s\n' ".env.test.local" "pre-push-under-test" > .gitignore
printf 'DB_HOST=localhost\nDB_NAME="elan_test"\n' > .env.test.local

# main: initial commit
write_file app/base.php "<?php // base"
write_file docs/readme.md "docs v1"
MAIN_C1="$(commit_all "initial")"

# main moves on with an app/ change of its own (this is what a later
# `merge main` will drag into a branch).
write_file app/mainonly.php "<?php // main only v1"
MAIN_C2="$(commit_all "main: add app/mainonly.php")"

git update-ref refs/remotes/origin/main "$MAIN_C2"

# milestone/m forks from main@C1 and has its own app/ change.
git checkout -q -b milestone/m "$MAIN_C1"
write_file app/msonly.php "<?php // milestone only"
MS_C1="$(commit_all "milestone: add app/msonly.php")"
git update-ref refs/remotes/origin/milestone/m "$MS_C1"

git checkout -q main

# --- Load the hook's functions in isolation -----------------------------

extract_fn() {
    sed -n "/^$1() {/,/^}/p" "$HOOK_SRC"
}

FN_FILE="$TMPROOT/fns.sh"
{
    grep -E '^(integration_gate_paths|zero_sha)=' "$HOOK_SRC"
    for fn in _diff_names _pick_closest_base _gate_base_for_ref _gated_files_for_ref \
        _integration_cache_key _integration_cache_file \
        _integration_cache_hit _integration_cache_record; do
        body="$(extract_fn "$fn")"
        if [ -z "$body" ]; then
            echo "FATAL: could not extract $fn() from $HOOK_SRC" >&2
            exit 1
        fi
        printf '%s\n' "$body"
    done
} > "$FN_FILE" || exit 1
# shellcheck source=/dev/null
source "$FN_FILE"
if [ -z "${integration_gate_paths:-}" ] || [ -z "${zero_sha:-}" ]; then
    echo "FATAL: could not extract integration_gate_paths/zero_sha from $HOOK_SRC" >&2
    exit 1
fi

# --- Stub composer, and a git wrapper that can be told to fail `diff` -----

STUBDIR="$TMPROOT/bin"
mkdir -p "$STUBDIR"
COMPOSER_LOG="$TMPROOT/composer.log"
cat > "$STUBDIR/composer" <<'STUB'
#!/bin/bash
printf '%s\n' "composer $*" >> "$COMPOSER_LOG"
[ -n "${STUB_DRAIN:-}" ] && cat >/dev/null
exit "${STUB_EXIT:-0}"
STUB
chmod +x "$STUBDIR/composer"
export COMPOSER_LOG

GITSTUBDIR="$TMPROOT/gitbin"
mkdir -p "$GITSTUBDIR"
cat > "$GITSTUBDIR/git" <<STUB
#!/bin/bash
for arg in "\$@"; do
    if [ "\$arg" = "diff" ]; then
        echo "fatal: simulated git diff failure" >&2
        exit 128
    fi
done
exec "$REAL_GIT" "\$@"
STUB
chmod +x "$GITSTUBDIR/git"

HOOK="$REPO/pre-push-under-test"
printf '%s\n' "$HOOK_TEXT" > "$HOOK"
chmod +x "$HOOK"

CACHE_FILE="$(git rev-parse --git-path integration-passed)"

# record_pass <sha> — writes the cache exactly as a local pass of <sha>'s
# tree would. The gate must ignore it except for an identical tree.
record_pass() {
    local key
    key="$(_integration_cache_key "$1")"
    if [ -z "$key" ]; then
        echo "FATAL: empty cache key for $1" >&2
        exit 1
    fi
    printf '%s\n' "$key" > "$CACHE_FILE"
}
clear_pass() { rm -f "$CACHE_FILE"; }

# run_hook <stdin-lines> [remote-name] — runs the whole hook with the stub
# first on PATH (and $EXTRA_PATH before it, when set). Echoes its combined
# output; sets HOOK_EXIT.
# The exit code goes through a file, not a variable: every caller captures
# this function's stdout with $(...), which runs it in a subshell — a plain
# HOOK_EXIT assignment would be discarded there, silently leaving the
# PREVIOUS case's exit code in place and making assertions pass by accident.
HOOK_EXIT_FILE="$TMPROOT/hook.exit"
run_hook() {
    local stdin_lines="$1" remote="${2:-origin}" out status
    : > "$COMPOSER_LOG"
    out="$(printf '%s\n' "$stdin_lines" \
        | PATH="${EXTRA_PATH:+$EXTRA_PATH:}$STUBDIR:$PATH" "$HOOK" "$remote" "git@example.invalid:x/y.git" 2>&1)"
    status=$?
    printf '%s\n' "$status" > "$HOOK_EXIT_FILE"
    printf '%s\n' "$out"
}
hook_exit() { cat "$HOOK_EXIT_FILE" 2>/dev/null; }

composer_calls() { wc -l < "$COMPOSER_LOG" | tr -d ' '; }

# assert_hook <description> <expected-calls> <expected-exit> <output>
#             [must-contain] [must-not-contain]
assert_hook() {
    local description="$1" want_calls="$2" want_exit="$3" out="$4"
    local must="${5:-}" mustnot="${6:-}" calls
    calls="$(composer_calls)"
    if [ "$calls" = "$want_calls" ] && [ "$(hook_exit)" = "$want_exit" ] \
        && { [ -z "$must" ] || printf '%s' "$out" | grep -q -- "$must"; } \
        && { [ -z "$mustnot" ] || ! printf '%s' "$out" | grep -q -- "$mustnot"; }; then
        pass "$description"
    else
        fail "$description" "composer calls: $calls (want $want_calls)" \
            "exit: $(hook_exit) (want $want_exit)" "output: [$out]"
    fi
}

# =========================================================================
# Base / gated-file scenarios (functions under test, hermetic topology)
# =========================================================================

# --- Case 1: new ref off origin/milestone/m with an app/ change ----------
clear_pass
git checkout -q -b issue/one "$MS_C1"
write_file app/own.php "<?php // issue own v1"
ISSUE_C1="$(commit_all "issue: add app/own.php")"
BASE="$(_gate_base_for_ref "$ISSUE_C1" "refs/heads/issue/one" "$ZERO" 2>/dev/null)"
assert_eq "Case 1a: new ref resolves base to the milestone tip" "$MS_C1" "$BASE"
assert_eq "Case 1b: new ref with an app/ change is gated" \
    "app/own.php" "$(_gated_files_for_ref "$BASE" "$ISSUE_C1")"

# --- Case 2: existing ref + merge of origin/main touching app/ ------------
# The remote tip exists locally, so the base is the remote tip, and main's
# app/ change brought in by the merge is gated (even after a recorded pass).
git merge -q --no-ff -m "merge main" "$MAIN_C2" >/dev/null 2>&1
ISSUE_C2="$(git rev-parse HEAD)"
record_pass "$ISSUE_C1"
BASE2="$(_gate_base_for_ref "$ISSUE_C2" "refs/heads/issue/one" "$ISSUE_C1" 2>/dev/null)"
assert_eq "Case 2a: an existing ref's base is its remote tip" "$ISSUE_C1" "$BASE2"
assert_eq "Case 2b: merging origin/main into an existing ref is gated" \
    "app/mainonly.php" "$(_gated_files_for_ref "$BASE2" "$ISSUE_C2")"

# --- Case 3: existing ref + merge of the parent milestone -----------------
git checkout -q milestone/m
write_file app/msonly.php "<?php // milestone only v2"
MS_C2="$(commit_all "milestone: edit app/msonly.php")"
git update-ref refs/remotes/origin/milestone/m "$MS_C2"
git checkout -q issue/one
git merge -q --no-ff -m "merge milestone" "$MS_C2" >/dev/null 2>&1
ISSUE_C3="$(git rev-parse HEAD)"
record_pass "$ISSUE_C2"
BASE3="$(_gate_base_for_ref "$ISSUE_C3" "refs/heads/issue/one" "$ISSUE_C2" 2>/dev/null)"
assert_eq "Case 3: merging the parent milestone into an existing ref is gated" \
    "app/msonly.php" "$(_gated_files_for_ref "$BASE3" "$ISSUE_C3")"
clear_pass

# --- Case 4: existing ref + docs-only commit ------------------------------
write_file docs/readme.md "docs v2"
ISSUE_C4="$(commit_all "issue: docs only")"
BASE4="$(_gate_base_for_ref "$ISSUE_C4" "refs/heads/issue/one" "$ISSUE_C3" 2>/dev/null)"
assert_eq "Case 4: a docs-only follow-up to an existing ref is not gated" \
    "" "$(_gated_files_for_ref "$BASE4" "$ISSUE_C4")"

# --- Case 5: merge commit that ALSO edits the branch's own app/ file ------
git checkout -q -b issue/five "$MS_C1"
write_file app/own5.php "<?php // five v1"
FIVE_C1="$(commit_all "five: add app/own5.php")"
git checkout -q -b main-side "$MAIN_C2"
write_file app/mainonly.php "<?php // main only v2"
MAIN_C3="$(commit_all "main: edit app/mainonly.php")"
git update-ref refs/remotes/origin/main "$MAIN_C3"
git checkout -q issue/five
git merge -q --no-ff --no-commit "$MAIN_C3" >/dev/null 2>&1
write_file app/own5.php "<?php // five v2 resolved during merge"
git add -A >/dev/null 2>&1
git commit -q -m "five: merge main, resolving app/own5.php" >/dev/null 2>&1
FIVE_C2="$(git rev-parse HEAD)"
BASE5="$(_gate_base_for_ref "$FIVE_C2" "refs/heads/issue/five" "$FIVE_C1" 2>/dev/null)"
assert_eq "Case 5: a merge of main that also edits the branch's own file gates both" \
    "app/mainonly.php
app/own5.php" "$(_gated_files_for_ref "$BASE5" "$FIVE_C2")"

# --- Case 6: remote_sha not present locally -> parent-base fallback -------
FAKE_SHA="deadbeefdeadbeefdeadbeefdeadbeefdeadbeef"
assert_eq "Case 6: an unknown remote_sha falls back to the parent base" \
    "$(_gate_base_for_ref "$FIVE_C2" "refs/heads/issue/five" "$ZERO" 2>/dev/null)" \
    "$(_gate_base_for_ref "$FIVE_C2" "refs/heads/issue/five" "$FAKE_SHA" 2>/dev/null)"

# --- Case 7: rebased branch with a stale remote_sha -----------------------
# The tree diff against the pre-rebase tip includes the milestone's change
# too: runs more, never less.
git checkout -q -b issue/seven "$MS_C1"
write_file app/own7.php "<?php // seven v1"
SEVEN_OLD="$(commit_all "seven: add app/own7.php")"
git rebase -q --onto "$MS_C2" "$MS_C1" issue/seven >/dev/null 2>&1
write_file app/own7.php "<?php // seven v2, reworked during the rebase"
SEVEN_NEW="$(commit_all "seven: rework app/own7.php")"
BASE7="$(_gate_base_for_ref "$SEVEN_NEW" "refs/heads/issue/seven" "$SEVEN_OLD" 2>/dev/null)"
assert_eq "Case 7: a rebased branch with a stale remote_sha is gated" \
    "app/msonly.php
app/own7.php" "$(_gated_files_for_ref "$BASE7" "$SEVEN_NEW")"

# --- Case 8: refs/heads/main diffs against its own remote tip -------------
BASE8="$(_gate_base_for_ref "$MAIN_C3" "refs/heads/main" "$MAIN_C2" 2>/dev/null)"
assert_eq "Case 8a: refs/heads/main resolves to its remote tip" "$MAIN_C2" "$BASE8"
assert_eq "Case 8b: main's own app/ change vs its remote tip is gated" \
    "app/mainonly.php" "$(_gated_files_for_ref "$BASE8" "$MAIN_C3")"
BASE8C="$(_gate_base_for_ref "$MAIN_C3" "refs/heads/main" "$ZERO" 2>/dev/null)"
assert_eq "Case 8c: refs/heads/main with a zero remote tip uses the merge-base with origin/main" \
    "$(git merge-base origin/main "$MAIN_C3")" "$BASE8C"

# --- Case 9: pushing milestone/m after merging main -----------------------
git checkout -q milestone/m
MS_REMOTE_BEFORE="$MS_C2"
git merge -q --no-ff -m "milestone: merge main" "$MAIN_C3" >/dev/null 2>&1
write_file app/msonly.php "<?php // milestone only v3"
MS_C3="$(commit_all "milestone: edit app/msonly.php again")"
BASE9="$(_gate_base_for_ref "$MS_C3" "refs/heads/milestone/m" "$MS_REMOTE_BEFORE" 2>/dev/null)"
assert_eq "Case 9: pushing milestone/m gates main's merged change and its own" \
    "app/mainonly.php
app/msonly.php" "$(_gated_files_for_ref "$BASE9" "$MS_C3")"

# --- Case 21: independent edit that converges with main ------------------
# main later lands identical content; the branch's edit must stay gated.
git checkout -q -b issue/conv "$MAIN_C3"
write_file app/conv.php "<?php // converged content"
CONV_C1="$(commit_all "conv: add app/conv.php")"
git checkout -q main-side
write_file app/conv.php "<?php // converged content"
MAIN_C4="$(commit_all "main: identical app/conv.php")"
git update-ref refs/remotes/origin/main "$MAIN_C4"
BASE21="$(_gate_base_for_ref "$CONV_C1" "refs/heads/issue/conv" "$ZERO" 2>/dev/null)"
assert_eq "Case 21: an independent edit identical to origin/main stays gated" \
    "app/conv.php" "$(_gated_files_for_ref "$BASE21" "$CONV_C1")"
git update-ref refs/remotes/origin/main "$MAIN_C3"

# --- Case 22: non-ASCII and quote-requiring file names ------------
git checkout -q -b issue/cafe "$MAIN_C3"
write_file "app/café.php" "<?php // cafe"
CAFE_C1="$(commit_all "cafe: add app/café.php")"
BASE22="$(_gate_base_for_ref "$CAFE_C1" "refs/heads/issue/cafe" "$ZERO" 2>/dev/null)"
assert_eq "Case 22a: a non-ASCII app/ path is gated (unquoted)" \
    "app/café.php" "$(_gated_files_for_ref "$BASE22" "$CAFE_C1")"
write_file 'app/we"ird.php' "<?php // quote in name"
WEIRD_C1="$(commit_all "cafe: add a path git must quote")"
GATED22B="$(_gated_files_for_ref "$BASE22" "$WEIRD_C1")"
if printf '%s\n' "$GATED22B" | grep -q 'ird\.php'; then
    pass "Case 22b: a path git still C-quotes is gated"
else
    fail "Case 22b: a path git still C-quotes is gated" "gated: [$GATED22B]"
fi

# --- Case 23: a failing git diff is not read as "no changes" -------
OUT23="$(_gated_files_for_ref "$FAKE_SHA" "$CAFE_C1" 2>&1)"
STATUS23=$?
if [ "$STATUS23" -ne 0 ] && printf '%s' "$OUT23" | grep -q 'WARNING'; then
    pass "Case 23: a git diff failure returns non-zero with a WARNING"
else
    fail "Case 23: a git diff failure returns non-zero with a WARNING" \
        "status: $STATUS23" "output: [$OUT23]"
fi

# =========================================================================
# Cache scenarios
# =========================================================================

git checkout -q issue/one
HEAD_SHA="$(git rev-parse HEAD)"

# --- Case 10: key = tree + DB_NAME; missing .env.test.local -> empty ------
mv "$REPO/.env.test.local" "$TMPROOT/env.saved"
assert_eq "Case 10a: missing .env.test.local disables caching (empty key)" \
    "" "$(_integration_cache_key "$HEAD_SHA")"
clear_pass
_integration_cache_record "$(_integration_cache_key "$HEAD_SHA")" "$HEAD_SHA"
if [ -f "$CACHE_FILE" ]; then
    fail "Case 10b: no cache file is written when the key is empty"
else
    pass "Case 10b: no cache file is written when the key is empty"
fi
mv "$TMPROOT/env.saved" "$REPO/.env.test.local"

assert_eq "Case 10c: key is '<tree> <DB_NAME>' with quotes stripped" \
    "$(git rev-parse "${HEAD_SHA}^{tree}") elan_test" \
    "$(_integration_cache_key "$HEAD_SHA")"

# --- Case 11: record refused when local_sha != HEAD -----------------------
clear_pass
KEY_OTHER="$(_integration_cache_key "$MS_C3")"
if _integration_cache_record "$KEY_OTHER" "$MS_C3"; then
    fail "Case 11: record refused when local_sha != HEAD" "record unexpectedly succeeded"
elif [ -f "$CACHE_FILE" ]; then
    fail "Case 11: record refused when local_sha != HEAD" "cache file was written anyway"
else
    pass "Case 11: record refused when local_sha != HEAD"
fi

# --- Case 12: record refused with a dirty tracked file --------------------
clear_pass
write_file app/own.php "<?php // dirty edit, uncommitted"
KEY_HEAD="$(_integration_cache_key "$HEAD_SHA")"
if _integration_cache_record "$KEY_HEAD" "$HEAD_SHA"; then
    fail "Case 12: record refused when the tracked tree is dirty" "record unexpectedly succeeded"
elif [ -f "$CACHE_FILE" ]; then
    fail "Case 12: record refused when the tracked tree is dirty" "cache file was written anyway"
else
    pass "Case 12: record refused when the tracked tree is dirty"
fi
git checkout -q -- app/own.php

# --- Case 12b: record refused with an untracked, non-ignored file ---------
# The suite tests the working tree, so a pass that depended on an un-added
# app/*.php must not be cached against a committed tree that lacks it.
clear_pass
write_file app/untracked_helper.php "<?php // never git-added"
if _integration_cache_record "$KEY_HEAD" "$HEAD_SHA"; then
    fail "Case 12b: record refused with an untracked file" "record unexpectedly succeeded"
elif [ -f "$CACHE_FILE" ]; then
    fail "Case 12b: record refused with an untracked file" "cache file was written anyway"
else
    pass "Case 12b: record refused with an untracked file"
fi
rm -f app/untracked_helper.php

# --- Case 13: record on clean; hit for same tree; miss after changes -------
clear_pass
KEY_HEAD="$(_integration_cache_key "$HEAD_SHA")"
if _integration_cache_record "$KEY_HEAD" "$HEAD_SHA" && _integration_cache_hit "$KEY_HEAD"; then
    pass "Case 13a: a clean pass is recorded and hits for the same tree"
else
    fail "Case 13a: a clean pass is recorded and hits for the same tree" \
        "key: [$KEY_HEAD]" "file: [$(cat "$CACHE_FILE" 2>/dev/null)]"
fi

printf 'DB_HOST=localhost\nDB_NAME=elan_test_other\n' > "$REPO/.env.test.local"
if _integration_cache_hit "$(_integration_cache_key "$HEAD_SHA")"; then
    fail "Case 13b: changing DB_NAME invalidates the cache" "still a hit"
else
    pass "Case 13b: changing DB_NAME invalidates the cache"
fi
printf 'DB_HOST=localhost\nDB_NAME="elan_test"\n' > "$REPO/.env.test.local"

write_file app/own.php "<?php // issue own v2"
NEW_HEAD="$(commit_all "issue: new commit invalidates the cache")"
if _integration_cache_hit "$(_integration_cache_key "$NEW_HEAD")"; then
    fail "Case 13c: a new commit (new tree) invalidates the cache" "still a hit"
else
    pass "Case 13c: a new commit (new tree) invalidates the cache"
fi

# =========================================================================
# Whole-hook scenarios (stub composer, crafted stdin)
# =========================================================================

# .env.test.local and the hook copy are gitignored (see setup), so the
# tracked tree stays clean for cache recording.
clear_pass
HEAD_SHA="$(git rev-parse HEAD)"
PUSH_LINE="refs/heads/issue/one $HEAD_SHA refs/heads/issue/one $ISSUE_C4"

# --- Case 14: stub exit 0 -> hook exit 0, cache recorded ------------------
STUB_EXIT=0 run_hook "$PUSH_LINE" >/dev/null
CALLS14="$(composer_calls)"
if [ "$(hook_exit)" -eq 0 ] && [ "$CALLS14" -eq 1 ] && [ -f "$CACHE_FILE" ]; then
    pass "Case 14: passing suite -> hook exit 0 and the cache file is written"
else
    fail "Case 14: passing suite -> hook exit 0 and the cache file is written" \
        "exit: $(hook_exit)" "composer calls: $CALLS14" "cache present: $([ -f "$CACHE_FILE" ] && echo yes || echo no)"
fi

# --- Case 15: identical push again -> cache hit, stub not called ----------
OUT15="$(STUB_EXIT=0 run_hook "$PUSH_LINE")"
CALLS15="$(composer_calls)"
if [ "$(hook_exit)" -eq 0 ] && [ "$CALLS15" -eq 0 ] \
    && printf '%s' "$OUT15" | grep -qi 'skip' \
    && printf '%s' "$OUT15" | grep -q 'rm "\$(git rev-parse --git-path integration-passed)"'; then
    pass "Case 15: a second identical push hits the cache and prints the rm hint"
else
    fail "Case 15: a second identical push hits the cache and prints the rm hint" \
        "exit: $(hook_exit)" "composer calls: $CALLS15" "output: [$OUT15]"
fi

# --- Case 16: stub exit 1 -> hook exit 1, no cache ------------------------
clear_pass
STUB_EXIT=1 run_hook "$PUSH_LINE" >/dev/null
if [ "$(hook_exit)" -eq 1 ] && [ ! -f "$CACHE_FILE" ]; then
    pass "Case 16: failing suite -> hook exit 1 and nothing is cached"
else
    fail "Case 16: failing suite -> hook exit 1 and nothing is cached" \
        "exit: $(hook_exit)" "cache present: $([ -f "$CACHE_FILE" ] && echo yes || echo no)"
fi

# --- Case 17: SKIP_INTEGRATION_GATE=1 -------------------------------------
# Uses a NEW issue/* ref (remote all-zeros) so the /review-pr reminder, which
# must remain unaffected by the bypass, is expected in the output too.
clear_pass
NEW_REF_LINE="refs/heads/issue/one $HEAD_SHA refs/heads/issue/one $ZERO"
OUT17="$(SKIP_INTEGRATION_GATE=1 STUB_EXIT=0 run_hook "$NEW_REF_LINE")"
CALLS17="$(composer_calls)"
if [ "$(hook_exit)" -eq 0 ] && [ "$CALLS17" -eq 0 ] \
    && printf '%s' "$OUT17" | grep -q 'SKIP_INTEGRATION_GATE=1' \
    && printf '%s' "$OUT17" | grep -q 'review-pr'; then
    pass "Case 17: SKIP_INTEGRATION_GATE=1 bypasses the suite but keeps the reminder"
else
    fail "Case 17: SKIP_INTEGRATION_GATE=1 bypasses the suite but keeps the reminder" \
        "exit: $(hook_exit)" "composer calls: $CALLS17" "output: [$OUT17]"
fi

# --- Case 17b: only the exact value "1" bypasses ---------------------------
clear_pass
OUT17B="$(SKIP_INTEGRATION_GATE=true STUB_EXIT=0 run_hook "$NEW_REF_LINE")"
assert_hook "Case 17b: SKIP_INTEGRATION_GATE=true does not bypass the gate" \
    1 0 "$OUT17B" "" "SKIP_INTEGRATION_GATE=1"

# --- Case 18: two gated refs -> the suite runs once -----------------------
clear_pass
TWO_REFS="refs/heads/issue/one $HEAD_SHA refs/heads/issue/one $ISSUE_C4
refs/heads/issue/seven $SEVEN_NEW refs/heads/issue/seven $SEVEN_OLD"
OUT18="$(STUB_EXIT=0 run_hook "$TWO_REFS")"
assert_hook "Case 18: two gated refs in one push run the suite exactly once" \
    1 0 "$OUT18"

# --- Case 18b: two gated refs, failing suite -> blocked, run once ---------
clear_pass
OUT18B="$(STUB_EXIT=1 run_hook "$TWO_REFS")"
assert_hook "Case 18b: two gated refs with a failing suite -> exit 1, suite run once" \
    1 1 "$OUT18B" "BLOCKED"
if [ -f "$CACHE_FILE" ]; then
    fail "Case 18c: a failing two-ref push caches nothing"
else
    pass "Case 18c: a failing two-ref push caches nothing"
fi

# --- Case 18d: a suite that reads stdin can't swallow later refs ----------
# The ref loop reads from stdin; the second ref must still be evaluated even
# if the suite's process drains whatever stdin it inherits.
clear_pass
DRAIN_REFS="refs/heads/issue/seven $SEVEN_NEW refs/heads/issue/seven $SEVEN_OLD
refs/heads/issue/docs $ISSUE_C4 refs/heads/issue/docs $ISSUE_C3"
OUT18D="$(STUB_DRAIN=1 STUB_EXIT=0 run_hook "$DRAIN_REFS")"
assert_hook "Case 18d: a stdin-draining suite does not end the ref loop early" \
    1 0 "$OUT18D" "no gated changes on 'issue/docs'"

# --- Case 19: a non-origin (deploy) remote is never gated -----------------
clear_pass
OUT19="$(STUB_EXIT=0 run_hook "$PUSH_LINE" "prod")"
assert_hook "Case 19: pushing to the 'prod' remote never runs the suite" 0 0 "$OUT19"

# --- Case 20: a branch deletion is never gated ----------------------------
clear_pass
DELETE_LINE="(delete) $ZERO refs/heads/issue/one $ISSUE_C4"
OUT20="$(STUB_EXIT=0 run_hook "$DELETE_LINE")"
assert_hook "Case 20: a ref deletion never runs the suite" 0 0 "$OUT20"

# --- Case 24: docs-only follow-up to an existing ref skips ---------------
git checkout -q issue/one
clear_pass
P1="$(git rev-parse HEAD)"
OUT24="$(STUB_EXIT=0 run_hook "refs/heads/issue/one $P1 refs/heads/issue/one $ISSUE_C4")"
assert_hook "Case 24a: an app/ change vs the remote tip runs the suite" 1 0 "$OUT24"
write_file docs/readme.md "docs v3"
P2="$(commit_all "issue: docs follow-up")"
clear_pass
OUT24="$(STUB_EXIT=0 run_hook "refs/heads/issue/one $P2 refs/heads/issue/one $P1")"
assert_hook "Case 24b: a docs-only follow-up skips even with no recorded pass" \
    0 0 "$OUT24" "no gated changes"

# --- Case 25: merging main / the parent into an existing ref runs ---------
clear_pass
OUT25="$(STUB_EXIT=0 run_hook "refs/heads/issue/one $ISSUE_C2 refs/heads/issue/one $ISSUE_C1")"
assert_hook "Case 25a: pushing a merge of origin/main runs the suite" 1 0 "$OUT25"
clear_pass
OUT25="$(STUB_EXIT=0 run_hook "refs/heads/issue/one $ISSUE_C3 refs/heads/issue/one $ISSUE_C2")"
assert_hook "Case 25b: pushing a merge of the parent milestone runs the suite" 1 0 "$OUT25"

# --- Case 26: a failing git diff runs the suite ---------------------------
write_file docs/readme.md "docs v5"
P5="$(commit_all "issue: docs, git diff will fail")"
OUT26="$(STUB_EXIT=0 run_hook "refs/heads/issue/one $P5 refs/heads/issue/one $P2")"
assert_hook "Case 26a (control): the same push skips when git works" 0 0 "$OUT26" "no gated changes"
OUT26="$(EXTRA_PATH="$GITSTUBDIR" STUB_EXIT=0 run_hook "refs/heads/issue/one $P5 refs/heads/issue/one $P2")"
assert_hook "Case 26b: a failing git diff runs the suite (fail-safe)" 1 0 "$OUT26" "fail-safe"

# --- Case 27: blank stdin lines do nothing ------------------------
clear_pass
OUT27="$(STUB_EXIT=0 run_hook "")"
assert_hook "Case 27a: an empty stdin line runs nothing and is not a fail-safe" \
    0 0 "$OUT27" "" "fail-safe"
OUT27="$(STUB_EXIT=0 run_hook "   ")"
assert_hook "Case 27b: a whitespace-only stdin line runs nothing and is not a fail-safe" \
    0 0 "$OUT27" "" "fail-safe"

# --- Case 28: local milestone fast-forwarded to the issue tip -----
clear_pass
git checkout -q -b issue/w3 "$MAIN_C3"
write_file app/w3.php "<?php // w3"
W3_C1="$(commit_all "w3: add app/w3.php")"
git branch -f milestone/w3 "$W3_C1" >/dev/null 2>&1
OUT28="$(STUB_EXIT=0 run_hook "refs/heads/issue/w3 $W3_C1 refs/heads/issue/w3 $ZERO")"
assert_hook "Case 28: a milestone branch at the issue's exact tip does not zero the diff" \
    1 0 "$OUT28" "" "via closest branch: milestone/w3"
git branch -D milestone/w3 >/dev/null 2>&1

# --- Case 29: a non-HEAD push warns that HEAD is what's tested ----
clear_pass
OUT29="$(STUB_EXIT=0 run_hook "refs/heads/issue/seven $SEVEN_NEW refs/heads/issue/seven $SEVEN_OLD")"
assert_hook "Case 29: pushing a ref that is not HEAD prints the working-tree warning" \
    1 0 "$OUT29" "is not HEAD"

# --- Case 30: feature:milestone/x never resolves to itself --------
clear_pass
git checkout -q -b feature "$MAIN_C3"
write_file app/feat.php "<?php // feature"
FEAT_C1="$(commit_all "feature: add app/feat.php")"
git update-ref refs/remotes/origin/milestone/x "$FEAT_C1"
BASE30="$(_gate_base_for_ref "$FEAT_C1" "refs/heads/milestone/x" "$ZERO" "feature" 2>/dev/null)"
assert_eq "Case 30a: feature:milestone/x resolves past origin/milestone/x to origin/main" \
    "$MAIN_C3" "$BASE30"
OUT30="$(STUB_EXIT=0 run_hook "refs/heads/feature $FEAT_C1 refs/heads/milestone/x $ZERO")"
assert_hook "Case 30b: pushing feature:milestone/x runs the suite" \
    1 0 "$OUT30" "" "via closest branch: .*milestone/x"
git update-ref -d refs/remotes/origin/milestone/x

# --- Case 31: pushes to main are gated against the remote tip -----
clear_pass
OUT31="$(STUB_EXIT=0 run_hook "refs/heads/main $MAIN_C3 refs/heads/main $MAIN_C2")"
assert_hook "Case 31a: a push to main with an app/ change runs the suite" 1 0 "$OUT31"
git checkout -q main-side
write_file docs/readme.md "docs on main"
MAIN_D="$(commit_all "main: docs only")"
OUT31="$(STUB_EXIT=0 run_hook "refs/heads/main $MAIN_D refs/heads/main $MAIN_C4")"
assert_hook "Case 31b: a docs-only push to main skips" 0 0 "$OUT31" "no gated changes"

# =========================================================================
# Wrongful-skip regressions (#2160 review): each push must RUN the suite.
# =========================================================================

# --- Case F: revert a previously pushed gated file to its base content ----
git checkout -q -b issue/f "$MAIN_C3"
write_file app/base.php "<?php // f edit"
write_file app/mainonly.php "<?php // f edit"
F1="$(commit_all "f: edit two app/ files")"
git checkout -q "$MAIN_C3" -- app/base.php
F2="$(commit_all "f: revert app/base.php")"
record_pass "$F1"
OUTF="$(STUB_EXIT=0 run_hook "refs/heads/issue/f $F2 refs/heads/issue/f $F1")"
assert_hook "Case F: reverting a pushed gated file runs the suite" 1 0 "$OUTF"

# --- Case G: delete a previously added gated file -------------------------
git checkout -q -b issue/g "$MAIN_C3"
write_file app/gnew.php "<?php // g"
G1="$(commit_all "g: add app/gnew.php")"
git rm -q app/gnew.php
G2="$(commit_all "g: delete app/gnew.php")"
record_pass "$G1"
OUTG="$(STUB_EXIT=0 run_hook "refs/heads/issue/g $G2 refs/heads/issue/g $G1")"
assert_hook "Case G: deleting a pushed gated file runs the suite" 1 0 "$OUTG"

# --- Case H: first push reverting a milestone edit to main's content ------
clear_pass
git checkout -q -b milestone/h "$MAIN_C3"
write_file app/base.php "<?php // milestone h edit"
commit_all "milestone/h: edit app/base.php" >/dev/null
git checkout -q -b issue/h
git checkout -q "$MAIN_C3" -- app/base.php
H1="$(commit_all "h: revert app/base.php to main")"
OUTH="$(STUB_EXIT=0 run_hook "refs/heads/issue/h $H1 refs/heads/issue/h $ZERO")"
assert_hook "Case H: reverting a milestone edit to main's content runs the suite" \
    1 0 "$OUTH" "via closest branch: milestone/h"
git checkout -q main-side
git branch -D milestone/h issue/h >/dev/null 2>&1

# --- Case I: git mv app/base.php lib/base.php ------------------------------
clear_pass
git checkout -q -b issue/i "$MAIN_C3"
mkdir -p lib
git mv app/base.php lib/base.php
I1="$(commit_all "i: move app/base.php out of app/")"
OUTI="$(STUB_EXIT=0 run_hook "refs/heads/issue/i $I1 refs/heads/issue/i $ZERO")"
assert_hook "Case I: moving a gated file out of the gated paths runs the suite" 1 0 "$OUTI"

# --- Case J: rename app/base.php -> app/c.inc -----------------------------
clear_pass
git checkout -q -b issue/j "$MAIN_C3"
git mv app/base.php app/c.inc
J1="$(commit_all "j: rename app/base.php to a non-.php name")"
OUTJ="$(STUB_EXIT=0 run_hook "refs/heads/issue/j $J1 refs/heads/issue/j $ZERO")"
assert_hook "Case J: renaming a gated file to a non-.php name runs the suite" 1 0 "$OUTJ"

# --- Case 32: unresolvable parent -> fail-safe run ------------------------
# Destructive to the topology, so it runs last.
clear_pass
git for-each-ref --format='%(refname)' refs/remotes/origin/ refs/heads/milestone/ \
    | while IFS= read -r ref; do git update-ref -d "$ref"; done
git checkout -q -b issue/orphaned "$MAIN_C3"
write_file docs/readme.md "docs only, but no parent can be resolved"
ORPH_C1="$(commit_all "orphaned: docs")"
OUT32="$(STUB_EXIT=0 run_hook "refs/heads/issue/orphaned $ORPH_C1 refs/heads/issue/orphaned $ZERO")"
assert_hook "Case 32: an unresolvable parent runs the suite once (fail-safe)" \
    1 0 "$OUT32" "fail-safe"

# --- Report ---------------------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
