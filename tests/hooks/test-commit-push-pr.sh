#!/bin/bash
#
# Regression test for scripts/commit-push-pr.sh — the mechanical half of
# /commit-push-pr (branch-safety refusal, forbidden-path refusal, and the
# dry-run command sequence for commit/push/PR create-or-reuse).
#
# Runs entirely with --dry-run: no commit, push, or `gh` call is ever
# executed for real. Creates a synthetic scratch branch and worktree-local
# test files, and always cleans them up (even on failure, via a trap).
# Does not modify any existing branch, ref, or file outside its own scratch
# area. Safe to run repeatedly; does not require a specific branch to be
# checked out (it returns to whatever branch was checked out before the run).
#
# Usage: bash tests/hooks/test-commit-push-pr.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT" || exit 1

SCRIPT="scripts/commit-push-pr.sh"
if [ ! -f "$SCRIPT" ]; then
    echo "FAIL: $SCRIPT not found" >&2
    exit 1
fi

TESTS_RUN=0
TESTS_FAILED=0

ORIGINAL_BRANCH="$(git branch --show-current)"
SYNTH_BRANCHES=()
SCRATCH_FILES=()
TMP_FILES=()

cleanup() {
    # Return to the original branch first so branch deletion below doesn't
    # refuse (git won't delete the branch you're currently on).
    if [ -n "$ORIGINAL_BRANCH" ]; then
        git checkout "$ORIGINAL_BRANCH" >/dev/null 2>&1
    fi
    local ref
    for ref in "${SYNTH_BRANCHES[@]:-}"; do
        [ -n "$ref" ] && git branch -D "$ref" >/dev/null 2>&1
    done
    local f
    for f in "${SCRATCH_FILES[@]:-}"; do
        [ -n "$f" ] && rm -f "$f"
    done
    for f in "${TMP_FILES[@]:-}"; do
        [ -n "$f" ] && rm -f "$f"
    done
}
trap cleanup EXIT

MESSAGE_FILE="$(mktemp)"
TMP_FILES+=("$MESSAGE_FILE")
printf 'test: synthetic commit message for test-commit-push-pr\n' > "$MESSAGE_FILE"

BODY_FILE="$(mktemp)"
TMP_FILES+=("$BODY_FILE")
printf 'Synthetic PR body for test-commit-push-pr.\n' > "$BODY_FILE"

# assert_exit <description> <expected-exit-code> -- <args...>
assert_exit() {
    local description="$1" expected="$2"
    shift 2
    # skip the literal "--" separator
    [ "${1:-}" = "--" ] && shift
    local output actual
    TESTS_RUN=$((TESTS_RUN + 1))
    output="$(bash "$SCRIPT" "$@" 2>&1)"
    actual=$?
    if [ "$actual" = "$expected" ]; then
        echo "PASS: $description"
    else
        echo "FAIL: $description"
        echo "      expected exit: $expected"
        echo "      actual exit:   $actual"
        echo "      output:"
        printf '        %s\n' "${output//$'\n'/$'\n'        }"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

# assert_output_matches <description> <grep-pattern> -- <args...>
assert_output_matches() {
    local description="$1" pattern="$2"
    shift 2
    [ "${1:-}" = "--" ] && shift
    local output
    TESTS_RUN=$((TESTS_RUN + 1))
    output="$(bash "$SCRIPT" "$@" 2>&1)"
    if echo "$output" | grep -q -- "$pattern"; then
        echo "PASS: $description"
    else
        echo "FAIL: $description"
        echo "      expected output to match: $pattern"
        echo "      actual output:"
        printf '        %s\n' "${output//$'\n'/$'\n'        }"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

# assert_output_not_matches <description> <grep-pattern> -- <args...>
assert_output_not_matches() {
    local description="$1" pattern="$2"
    shift 2
    [ "${1:-}" = "--" ] && shift
    local output
    TESTS_RUN=$((TESTS_RUN + 1))
    output="$(bash "$SCRIPT" "$@" 2>&1)"
    if echo "$output" | grep -q -- "$pattern"; then
        echo "FAIL: $description"
        echo "      expected output NOT to match: $pattern"
        echo "      actual output:"
        printf '        %s\n' "${output//$'\n'/$'\n'        }"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    else
        echo "PASS: $description"
    fi
}

# --- Scenario 1: refuses to run on main/master/milestone/* ------------------
# `git checkout main` (a real, pre-existing branch with different committed
# content in every file) fails outright with a dirty working tree — normal
# for a checkout mid-milestone with other agents' changes in flight — so
# this scenario cannot always check out real `main`/`master` to test them.
# `git checkout -b <new-branch>` (branching forward from the current HEAD)
# does not have this problem, so the milestone/* case is tested end-to-end
# via a real checkout, same as every other scenario in this file. The
# main/master names are instead tested with is_refused_branch() extracted
# the same way is_forbidden_path() is tested in Scenario 4 — this avoids
# ever needing to check out the genuinely-existing `main`/`master` branches.

FN_FILE2="$(mktemp)"
TMP_FILES+=("$FN_FILE2")
sed -n '/^is_refused_branch() {/,/^}/p' "$SCRIPT" > "$FN_FILE2"
TESTS_RUN=$((TESTS_RUN + 1))
if [ ! -s "$FN_FILE2" ]; then
    echo "FAIL: Scenario 1a: could not extract is_refused_branch() from $SCRIPT"
    TESTS_FAILED=$((TESTS_FAILED + 1))
else
    # shellcheck source=/dev/null
    source "$FN_FILE2"
    scenario1a_ok=1
    for bad in main master milestone/v2.30.0 milestone/anything; do
        if ! is_refused_branch "$bad"; then
            echo "FAIL: Scenario 1a: is_refused_branch did not reject '$bad'"
            scenario1a_ok=0
        fi
    done
    for good in chore/claude-update issue/123-foo __test_scratch; do
        if is_refused_branch "$good"; then
            echo "FAIL: Scenario 1a: is_refused_branch incorrectly rejected '$good'"
            scenario1a_ok=0
        fi
    done
    if [ "$scenario1a_ok" -eq 1 ]; then
        echo "PASS: Scenario 1a: is_refused_branch accepts/rejects the right branch names"
    else
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
fi

git checkout -b milestone/__test_refused >/dev/null 2>&1
SYNTH_BRANCHES+=("milestone/__test_refused")
assert_exit \
    "Scenario 1b: refuses to run on 'milestone/__test_refused' with no --branch" \
    1 \
    -- --dry-run --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE"
git checkout "$ORIGINAL_BRANCH" >/dev/null 2>&1

# --- Scenario 2: --branch on a refused branch is accepted (dry-run) --------

git checkout -b milestone/__test_refused2 >/dev/null 2>&1
SYNTH_BRANCHES+=("milestone/__test_refused2")
# scripts/resolve-base-branch.sh (owned by a parallel task) is expected to
# exist and succeed in a normal checkout, so the full dry-run sequence
# should reach the PR-create step and exit 0. If that script is missing or
# fails, this script exits 3 instead — assert on the *branch* step
# succeeding (Scenario 2b) rather than pin the exit code here, so this
# scenario does not depend on that other script's state.
assert_output_matches \
    "Scenario 2a: --branch off a refused branch proceeds past the branch check" \
    "checkout -b __test_scratch_ok" \
    -- --dry-run --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" --branch __test_scratch_ok
assert_output_not_matches \
    "Scenario 2b: --branch off a refused branch does not print a branch refusal" \
    "^Refused: current branch" \
    -- --dry-run --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" --branch __test_scratch_ok

git checkout "$ORIGINAL_BRANCH" >/dev/null 2>&1

# --- Scenario 3: --branch itself refused is rejected ------------------------

git checkout -b milestone/__test_refused3 >/dev/null 2>&1
SYNTH_BRANCHES+=("milestone/__test_refused3")
assert_exit \
    "Scenario 3: --branch milestone/foo is rejected even from a refused branch" \
    1 \
    -- --dry-run --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" --branch milestone/__test_still_refused
git checkout "$ORIGINAL_BRANCH" >/dev/null 2>&1

# --- Scenario 4: is_forbidden_path() rejects docs/plans/ and _noupload/ ----
# docs/plans/ and _noupload/ are gitignored, and a repo-level pre-tool hook
# already blocks `git add -f` on them (see .claude/rules/planning-docs.md),
# so a real forbidden path never reaches `git status --porcelain` in a live
# checkout — there is no way to trigger the end-to-end refusal from this
# test without fighting that hook. Instead, extract and test the pure
# is_forbidden_path() function the script uses to build its FORBIDDEN list,
# the same technique test-pick-closest-base.sh uses for _pick_closest_base().
FN_FILE="$(mktemp)"
TMP_FILES+=("$FN_FILE")
sed -n '/^is_forbidden_path() {/,/^}/p' "$SCRIPT" > "$FN_FILE"
TESTS_RUN=$((TESTS_RUN + 1))
if [ ! -s "$FN_FILE" ]; then
    echo "FAIL: Scenario 4: could not extract is_forbidden_path() from $SCRIPT"
    TESTS_FAILED=$((TESTS_FAILED + 1))
else
    # shellcheck source=/dev/null
    source "$FN_FILE"
    scenario4_ok=1
    for bad in "docs/plans/analysis/x.md" "docs/plans/sprints/v2.30.md" "_noupload/secret.txt"; do
        if ! is_forbidden_path "$bad"; then
            echo "FAIL: Scenario 4: is_forbidden_path did not reject '$bad'"
            scenario4_ok=0
        fi
    done
    for good in "app/api/cars/index.php" "docs/development/DATABASE.md" "tests/hooks/test-commit-push-pr.sh"; do
        if is_forbidden_path "$good"; then
            echo "FAIL: Scenario 4: is_forbidden_path incorrectly rejected '$good'"
            scenario4_ok=0
        fi
    done
    if [ "$scenario4_ok" -eq 1 ]; then
        echo "PASS: Scenario 4: is_forbidden_path accepts/rejects the right paths"
    else
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
fi

# --- Scenario 5: missing message/body file is a usage error -----------------

assert_exit \
    "Scenario 5: missing --message-file argument value fails validation" \
    1 \
    -- --dry-run --message-file /no/such/file --title "t" --body-file "$BODY_FILE"

# --- Scenario 6: dry-run never mutates git state ---------------------------

git checkout -b __test_scratch_dryrun >/dev/null 2>&1
SYNTH_BRANCHES+=("__test_scratch_dryrun")
BEFORE_SHA="$(git rev-parse HEAD)"
bash "$SCRIPT" --dry-run --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" >/dev/null 2>&1
AFTER_SHA="$(git rev-parse HEAD)"
TESTS_RUN=$((TESTS_RUN + 1))
if [ "$BEFORE_SHA" = "$AFTER_SHA" ]; then
    echo "PASS: Scenario 6: --dry-run does not create a commit"
else
    echo "FAIL: Scenario 6: --dry-run does not create a commit"
    echo "      HEAD moved from $BEFORE_SHA to $AFTER_SHA"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi
git checkout "$ORIGINAL_BRANCH" >/dev/null 2>&1

# --- Report ---------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
