#!/bin/bash
#
# Regression test for scripts/commit-push-pr.sh — the mechanical half of
# /commit-push-pr (branch-safety refusal, forbidden-path refusal, and the
# dry-run command sequence for commit/push/PR create-or-reuse).
#
# Hermetic: every scenario runs inside a throwaway `git clone --local` of
# this repo in a temp directory. No `git checkout`, `commit`, or `branch` is
# ever run against the real working tree this test was launched from — only
# read-only queries (`git rev-parse --show-toplevel`) touch it, to find the
# repo to clone. `git clone --local` sets the clone's "origin" to the real
# repo's working directory, so this test repoints "origin" at a throwaway
# bare repo right after cloning — otherwise a regression in the script under
# test (a real `git push`) would land a branch in the real repo. A stub `gh`
# is put first on PATH so no scenario reaches the real GitHub CLI either.
# The clone, the bare repo, and all temp files are removed on exit (even on
# failure, via a trap), so this test is safe to run concurrently with other
# work in the real checkout.
#
# Usage: bash tests/hooks/test-commit-push-pr.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

SOURCE_REPO_ROOT="$(git rev-parse --show-toplevel)"

SCRIPT_REL="scripts/commit-push-pr.sh"
if [ ! -f "$SOURCE_REPO_ROOT/$SCRIPT_REL" ]; then
    echo "FAIL: $SCRIPT_REL not found in $SOURCE_REPO_ROOT" >&2
    exit 1
fi

TESTS_RUN=0
TESTS_FAILED=0

SCRATCH_PARENT=""
BARE_ORIGIN_DIR=""
STUB_BIN_DIR=""
TMP_FILES=()

# shellcheck disable=SC2329 # the EXIT trap calls it
cleanup() {
    if [ -n "$SCRATCH_PARENT" ]; then
        rm -rf "$SCRATCH_PARENT"
    fi
    local f
    for f in "${TMP_FILES[@]:-}"; do
        [ -n "$f" ] && rm -f "$f"
    done
}
trap cleanup EXIT

SCRATCH_PARENT="$(mktemp -d)"
CLONE_DIR="$SCRATCH_PARENT/repo"
if ! git clone --quiet --local --no-hardlinks "$SOURCE_REPO_ROOT" "$CLONE_DIR" >/dev/null 2>&1; then
    echo "FAIL: could not clone $SOURCE_REPO_ROOT into $CLONE_DIR" >&2
    exit 1
fi

# `git clone --local` points the clone's "origin" at $SOURCE_REPO_ROOT — the
# real checkout this test was launched from. If the script under test ever
# regresses to a real `git push` (instead of staying inside --dry-run or a
# throwaway scratch branch), that push would land in the real repo. Replace
# "origin" with a throwaway bare repo so every push in this test — including
# one from a bug this test is meant to catch — lands somewhere disposable.
BARE_ORIGIN_DIR="$SCRATCH_PARENT/origin.git"
if ! git init --quiet --bare "$BARE_ORIGIN_DIR" >/dev/null 2>&1; then
    echo "FAIL: could not create bare origin at $BARE_ORIGIN_DIR" >&2
    exit 1
fi
# Scenarios need origin/main to exist (scripts/resolve-base-branch.sh
# resolves a PR base against it), so seed the bare origin with main before
# retargeting "origin". Seed from the source checkout, not the clone: the
# clone's remote refs are the source's LOCAL branches, and a CI runner
# (actions/checkout) has no local main, only refs/remotes/origin/main.
MAIN_REF="refs/remotes/origin/main"
git -C "$SOURCE_REPO_ROOT" rev-parse --verify --quiet "$MAIN_REF" >/dev/null || MAIN_REF="refs/heads/main"
if ! git -C "$SOURCE_REPO_ROOT" push --quiet "$BARE_ORIGIN_DIR" "$MAIN_REF:refs/heads/main" >/dev/null 2>&1; then
    echo "FAIL: could not push main to the throwaway bare origin" >&2
    exit 1
fi
git -C "$CLONE_DIR" remote set-url origin "$BARE_ORIGIN_DIR"
git -C "$CLONE_DIR" fetch --quiet origin >/dev/null 2>&1

# `git clone` only copies committed history, not uncommitted edits in
# SOURCE_REPO_ROOT's working tree. Overlay the working-tree copy of the
# script under test so this test exercises in-progress changes, not just
# what's committed.
cp "$SOURCE_REPO_ROOT/$SCRIPT_REL" "$CLONE_DIR/$SCRIPT_REL"

# Stub `gh` on PATH ahead of the real one so no scenario reaches GitHub,
# even a scenario that runs the script for real (not --dry-run).
STUB_BIN_DIR="$SCRATCH_PARENT/stub-bin"
mkdir -p "$STUB_BIN_DIR"
cat > "$STUB_BIN_DIR/gh" <<'EOF'
#!/bin/bash
# Stub gh for test-commit-push-pr.sh. No scenario reaches the real GitHub CLI.
# STUB_PRS names a file of "<branch> <STATE> <url>" lines (STATE is OPEN,
# MERGED or CLOSED). Unset means no PRs. The stub prints what the real
# command prints after its --jq filter.
#   pr list --head <b> --state open  first OPEN PR for <b>, or nothing
#   pr view <b>                       last PR for <b> in any state, as the
#                                     real gh does, or exit 1
# STUB_GH_FAIL=1 makes every call exit 1.
[ "${STUB_GH_FAIL:-0}" = 1 ] && { echo "stub gh: synthetic failure" >&2; exit 1; }
sub="$1 $2"
shift 2
head="" state="open" positional=""
while [ "$#" -gt 0 ]; do
    case "$1" in
        --head) head="$2"; shift 2 ;;
        --state) state="$2"; shift 2 ;;
        --json|--jq|--base|--title|--body-file) shift 2 ;;
        --*) shift ;;
        *) positional="$1"; shift ;;
    esac
done
prs="${STUB_PRS:-/dev/null}"
case "$sub" in
    "pr list")
        want="$(printf '%s' "$state" | tr '[:lower:]' '[:upper:]')"
        while read -r b s u; do
            if [ "$b" = "$head" ] && { [ "$want" = ALL ] || [ "$s" = "$want" ]; }; then
                echo "$u"
                break
            fi
        done < "$prs"
        exit 0
        ;;
    "pr view")
        found=""
        while read -r b s u; do
            [ "$b" = "$positional" ] && found="$u"
        done < "$prs"
        [ -n "$found" ] || { echo "no pull requests found for branch \"$positional\"" >&2; exit 1; }
        echo "$found"
        exit 0
        ;;
    "pr create") echo "https://example.invalid/pr/stub" ; exit 0 ;;
    *) exit 1 ;;
esac
EOF
chmod +x "$STUB_BIN_DIR/gh"
export PATH="$STUB_BIN_DIR:$PATH"

cd "$CLONE_DIR" || exit 1

SCRIPT="scripts/commit-push-pr.sh"
ORIGINAL_BRANCH="$(git branch --show-current)"
SYNTH_BRANCHES=()

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
# All checkouts below run in the throwaway clone at $CLONE_DIR, never in the
# real repo this test was launched from.

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

# --- Scenario 3b: --branch names a branch that already exists --------------
# Bug #2245: `git checkout -b` on an existing branch name fails, but the old
# code did not check the result — it set CURRENT_BRANCH to the new name
# anyway and went on to commit on the still-checked-out (refused) branch,
# push, and open a PR. This scenario runs the script for real (no --dry-run)
# in the throwaway clone, so it exercises the actual `git checkout -b`
# failure. `--dry-run` would skip the real checkout and hide the bug.
# TO_STAGE is empty in this clone (nothing staged/changed), so if the bug
# were present the script would skip `git commit` and go straight to `git
# push`. The clone's "origin" is a throwaway bare repo (see the setup at the
# top of this file), so even that push cannot reach the real checkout. The
# test asserts the exit code and "no new commit" directly.

git checkout -b milestone/__test_refused4 >/dev/null 2>&1
SYNTH_BRANCHES+=("milestone/__test_refused4")
git checkout -b __test_existing_target >/dev/null 2>&1
SYNTH_BRANCHES+=("__test_existing_target")
BEFORE_SHA_S3B="$(git rev-parse __test_existing_target)"
git checkout milestone/__test_refused4 >/dev/null 2>&1

assert_exit \
    "Scenario 3b: --branch naming an already-existing branch fails (does not silently continue)" \
    2 \
    -- --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" --branch __test_existing_target

git checkout milestone/__test_refused4 >/dev/null 2>&1
CURRENT_SHA_S3B="$(git rev-parse HEAD)"
TESTS_RUN=$((TESTS_RUN + 1))
if [ "$CURRENT_SHA_S3B" = "$(git rev-parse milestone/__test_refused4)" ]; then
    echo "PASS: Scenario 3b: no commit landed on the starting branch"
else
    echo "FAIL: Scenario 3b: no commit landed on the starting branch"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

TESTS_RUN=$((TESTS_RUN + 1))
if [ "$(git rev-parse __test_existing_target)" = "$BEFORE_SHA_S3B" ]; then
    echo "PASS: Scenario 3b: the existing target branch was not moved"
else
    echo "FAIL: Scenario 3b: the existing target branch was not moved"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

assert_output_matches \
    "Scenario 3b: prints a clear checkout-failure error to stderr" \
    "git checkout -b '__test_existing_target' failed" \
    -- --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" --branch __test_existing_target

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

# --- Scenario 4b: parse_porcelain_z() handles renames and spaces -----------
# Regression for the #2209 review: line-mode porcelain turned a rename into
# the single bogus path "old -> new", and a move INTO docs/plans/ slipped
# past is_forbidden_path() because the string started with the old path.
PZ_FILE="$(mktemp)"
TMP_FILES+=("$PZ_FILE")
sed -n '/^parse_porcelain_z() {/,/^}/p' "$SCRIPT" > "$PZ_FILE"
TESTS_RUN=$((TESTS_RUN + 1))
if [ ! -s "$PZ_FILE" ]; then
    echo "FAIL: Scenario 4b: could not extract parse_porcelain_z() from $SCRIPT"
    TESTS_FAILED=$((TESTS_FAILED + 1))
else
    # shellcheck source=/dev/null
    source "$PZ_FILE"
    got="$(printf 'R  docs/plans/moved.md\0app/old.php\0 M has space.txt\0?? new.txt\0' | parse_porcelain_z)"
    want="$(printf 'docs/plans/moved.md\napp/old.php\nhas space.txt\nnew.txt')"
    scenario4b_ok=1
    [ "$got" = "$want" ] || { echo "FAIL: Scenario 4b: got [$got], want [$want]"; scenario4b_ok=0; }
    forbidden_hit=0
    while IFS= read -r f; do is_forbidden_path "$f" && forbidden_hit=1; done <<< "$got"
    [ "$forbidden_hit" -eq 1 ] || { echo "FAIL: Scenario 4b: a rename into docs/plans/ was not flagged"; scenario4b_ok=0; }
    if [ "$scenario4b_ok" -eq 1 ]; then
        echo "PASS: Scenario 4b: parse_porcelain_z splits renames and flags a move into docs/plans/"
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

# --- Scenario 7: a failing `git status` is not swallowed --------------------
# Bug #2245: `git status --porcelain` ran inside a process substitution, so a
# failing `git status` was lost. The script then reported "Nothing to
# commit; pushing existing commits", pushed, opened a PR, and exited 0
# without the change. This scenario puts a `git` wrapper first on PATH that
# fails only for the `status` subcommand and passes every other subcommand
# through to the real `git`, then asserts the script exits 2 before it
# pushes or opens a PR.

GIT_STATUS_FAIL_DIR="$SCRATCH_PARENT/git-status-fail-bin"
mkdir -p "$GIT_STATUS_FAIL_DIR"
REAL_GIT="$(command -v git)"
cat > "$GIT_STATUS_FAIL_DIR/git" <<EOF
#!/bin/bash
if [ "\$1" = "status" ]; then
    echo "git: fatal: synthetic status failure for test-commit-push-pr Scenario 7" >&2
    exit 128
fi
exec "$REAL_GIT" "\$@"
EOF
chmod +x "$GIT_STATUS_FAIL_DIR/git"

git checkout -b __test_scratch_statusfail >/dev/null 2>&1
SYNTH_BRANCHES+=("__test_scratch_statusfail")
BEFORE_SHA_S7="$(git rev-parse HEAD)"
BEFORE_ORIGIN_HEAD_S7="$(git ls-remote origin main | cut -f1)"

TESTS_RUN=$((TESTS_RUN + 1))
OUTPUT_S7="$(PATH="$GIT_STATUS_FAIL_DIR:$PATH" bash "$SCRIPT" --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" 2>&1)"
ACTUAL_S7=$?
if [ "$ACTUAL_S7" = 2 ]; then
    echo "PASS: Scenario 7: a failing git status exits 2"
else
    echo "FAIL: Scenario 7: a failing git status exits 2"
    echo "      expected exit: 2"
    echo "      actual exit:   $ACTUAL_S7"
    echo "      output:"
    printf '        %s\n' "${OUTPUT_S7//$'\n'/$'\n'        }"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

AFTER_SHA_S7="$(git rev-parse HEAD)"
AFTER_ORIGIN_HEAD_S7="$(git ls-remote origin main | cut -f1)"
TESTS_RUN=$((TESTS_RUN + 1))
if [ "$BEFORE_SHA_S7" = "$AFTER_SHA_S7" ] && [ "$BEFORE_ORIGIN_HEAD_S7" = "$AFTER_ORIGIN_HEAD_S7" ]; then
    echo "PASS: Scenario 7: no commit and no push happened after a failing git status"
else
    echo "FAIL: Scenario 7: no commit and no push happened after a failing git status"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

TESTS_RUN=$((TESTS_RUN + 1))
if printf '%s' "$OUTPUT_S7" | grep -qE '^(git status failed|Refused:)'; then
    echo "PASS: Scenario 7: prints a clear error rather than 'Nothing to commit'"
else
    echo "FAIL: Scenario 7: prints a clear error rather than 'Nothing to commit'"
    echo "      output: $OUTPUT_S7"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

TESTS_RUN=$((TESTS_RUN + 1))
if printf '%s' "$OUTPUT_S7" | grep -q "Nothing to commit"; then
    echo "FAIL: Scenario 7: must not report 'Nothing to commit' when git status itself failed"
    TESTS_FAILED=$((TESTS_FAILED + 1))
else
    echo "PASS: Scenario 7: does not report 'Nothing to commit' when git status itself failed"
fi

git checkout "$ORIGINAL_BRANCH" >/dev/null 2>&1

# --- Scenario 8: several changed files stay separate paths -----------------
# `git status -z` separates records with NUL bytes. Bash command substitution
# drops NUL bytes, so a version that stored the status output in a variable
# merged every changed path into one bogus path (PR #2249 review). That
# breaks both the forbidden-path check and `git add`. Scenarios 3b and 7 run
# with a clean tree, so they cannot catch it. This scenario creates three
# untracked files, one with a space in its name, and checks that the dry-run
# `git add` receives each path as its own argument.

S8_FILES=("s8-a.txt" "s8 b.txt" "s8-c.txt")
for f in "${S8_FILES[@]}"; do
    printf 'scenario 8\n' > "$f"
done

TESTS_RUN=$((TESTS_RUN + 1))
OUTPUT_S8="$(bash "$SCRIPT" --dry-run --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" --branch __test_scratch_multi 2>&1)"
# The dry-run prints each argument with printf %q, so a space shows as "\ ".
# Match only the three scenario paths on the `git add` line: the overlaid
# copy of the script can also show as changed when it has uncommitted edits.
# shellcheck disable=SC1003 # a literal backslash in the expected %q output
S8_EXPECTED='s8\ b.txt s8-a.txt s8-c.txt'
if printf '%s\n' "$OUTPUT_S8" | grep -F -- 'git add -A -- ' | grep -qF -- " $S8_EXPECTED"; then
    echo "PASS: Scenario 8: each changed file is staged as its own path"
else
    echo "FAIL: Scenario 8: each changed file is staged as its own path"
    echo "      expected output to contain: $S8_EXPECTED"
    echo "      output:"
    printf '        %s\n' "${OUTPUT_S8//$'\n'/$'\n'        }"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi
rm -f -- "${S8_FILES[@]}"

# --- Scenario 9: reuse only an OPEN PR for the branch ----------------------
# Round-5 review of PR #2315: `gh pr view <branch>` also returns a merged or
# closed PR. A reused branch name then printed the old merged PR URL, exited
# 0, and opened no new PR. The stub gh returns a merged PR from `pr view`,
# as the real gh does, so a return to `gh pr view` fails 9a.
# --base main skips scripts/resolve-base-branch.sh, so its state cannot
# change the result.

git checkout -b __test_reused_branch >/dev/null 2>&1
SYNTH_BRANCHES+=("__test_reused_branch")
S9_PRS="$(mktemp)"
TMP_FILES+=("$S9_PRS")
export STUB_PRS="$S9_PRS"

printf '__test_reused_branch MERGED https://example.invalid/pr/merged\n' > "$S9_PRS"
assert_output_matches \
    "Scenario 9a: a branch with only a merged PR gets a new PR" \
    "gh pr create" \
    -- --dry-run --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" --base main
assert_output_not_matches \
    "Scenario 9a: a branch with only a merged PR does not report the merged PR" \
    "pr/merged" \
    -- --dry-run --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" --base main

printf '__test_reused_branch MERGED https://example.invalid/pr/merged\n__test_reused_branch OPEN https://example.invalid/pr/open\n' > "$S9_PRS"
assert_output_matches \
    "Scenario 9b: a branch with an open PR reuses it" \
    "pr/open" \
    -- --dry-run --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" --base main
assert_output_not_matches \
    "Scenario 9b: a branch with an open PR does not create another PR" \
    "gh pr create" \
    -- --dry-run --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" --base main

STUB_GH_FAIL=1 assert_exit \
    "Scenario 9c: a failing gh pr list exits 2 and creates no PR" \
    2 \
    -- --dry-run --message-file "$MESSAGE_FILE" --title "t" --body-file "$BODY_FILE" --base main

unset STUB_PRS
git checkout "$ORIGINAL_BRANCH" >/dev/null 2>&1

# --- Report ---------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
