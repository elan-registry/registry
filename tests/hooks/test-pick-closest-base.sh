#!/bin/bash
#
# Regression test for .githooks/pre-push's _pick_closest_base() function.
#
# This function has regressed three times across separate PRs (#1751,
# #1767, #2029) with zero automated coverage — each regression was only
# caught by a reviewer hand-building synthetic git topology during review.
# This script pins the same four scenarios verified manually during #2024
# so a future change that breaks any of them fails loudly here instead of
# silently shipping a broken pre-push gate.
#
# Runs against the CURRENT repo's real origin/main and (if present) real
# milestone/* branches, plus synthetic commits/branches/tags it creates and
# always cleans up (even on failure, via a trap). Does not modify any
# existing ref. Safe to run repeatedly; does not require a specific branch
# to be checked out.
#
# Usage: bash tests/hooks/test-pick-closest-base.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

# Run from inside a hook or rebase, these would redirect every git command
# below (including the synthetic ref creation and cleanup) to another repo.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY

# git commit-tree requires a resolvable author/committer identity. A
# developer's machine has one configured, but a fresh CI runner does not —
# confirmed live in CI (#2024 review): commit-tree fails with "Please tell
# me who you are", leaving $TARGET_TIP empty and cascading a bogus failure
# into an otherwise-unrelated scenario. Exporting these only for this
# script's own process (not `git config`, global or local) avoids touching
# any real identity or leaving repo/global config mutated.
export GIT_AUTHOR_NAME="test-pick-closest-base"
export GIT_AUTHOR_EMAIL="test-pick-closest-base@localhost"
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME"
export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT" || exit 1

# --- Load the function under test in isolation ------------------------------
# Sourcing .githooks/pre-push directly would execute its main body (which
# reads from stdin and expects git's pre-push argument protocol) — instead,
# extract just the function definition, matching the manual verification
# approach used during #2024's own review.
FN_FILE="$(mktemp)"
sed -n '/^_pick_closest_base() {/,/^}/p' .githooks/pre-push > "$FN_FILE"
if [ ! -s "$FN_FILE" ]; then
    echo "FAIL: could not extract _pick_closest_base() from .githooks/pre-push" >&2
    rm -f "$FN_FILE"
    exit 1
fi
# shellcheck source=/dev/null
source "$FN_FILE"
rm -f "$FN_FILE"

# --- Test scaffolding ---------------------------------------------------

TESTS_RUN=0
TESTS_FAILED=0

# Synthetic refs this script creates, tracked for cleanup.
SYNTH_TAGS=()
SYNTH_BRANCHES=()
# Full refnames (e.g. refs/remotes/origin/milestone/*) that must be removed
# with `git update-ref -d` rather than `git branch -D`.
SYNTH_REFS=()

cleanup() {
    local ref
    for ref in "${SYNTH_TAGS[@]:-}"; do
        [ -n "$ref" ] && git tag -d "$ref" >/dev/null 2>&1
    done
    for ref in "${SYNTH_BRANCHES[@]:-}"; do
        [ -n "$ref" ] && git branch -D "$ref" >/dev/null 2>&1
    done
    for ref in "${SYNTH_REFS[@]:-}"; do
        [ -n "$ref" ] && git update-ref -d "$ref" >/dev/null 2>&1
    done
}
trap cleanup EXIT

# make_commit_on <parent-commit-ish> <message> — creates a new commit with
# the same tree as its parent (no actual file changes needed for these
# topology tests) and echoes its SHA. Fails loudly (script exit, via the
# `set -u`-safe guard below) rather than letting a git-commit-tree failure
# silently propagate an empty SHA into a downstream scenario as a
# misleading assertion failure — this exact failure mode was hit in CI
# (#2024 review) when commit-tree failed for an unrelated reason (no
# author identity configured) and produced a confusing "expected: <sha>,
# actual: <empty>" instead of a clear "could not construct test fixture."
make_commit_on() {
    local parent="$1" message="$2" sha
    sha="$(git commit-tree "${parent}^{tree}" -p "$parent" -m "$message")" || {
        echo "FATAL: git commit-tree failed while building test fixture (parent=$parent)" >&2
        exit 1
    }
    if [ -z "$sha" ]; then
        echo "FATAL: git commit-tree produced no output while building test fixture (parent=$parent)" >&2
        exit 1
    fi
    printf '%s\n' "$sha"
}

# assert_resolves_to <description> <target> <expected-base-sha>
#                    [self-remote-name] [self-local-name]
assert_resolves_to() {
    local description="$1" target="$2" expected="$3"
    local self_remote="${4:-}" self_local="${5:-}"
    local actual
    TESTS_RUN=$((TESTS_RUN + 1))
    actual="$(_pick_closest_base "$target" "$self_remote" "$self_local" 2>/dev/null)"
    if [ "$actual" = "$expected" ]; then
        echo "PASS: $description"
    else
        echo "FAIL: $description"
        echo "      target:   $target"
        echo "      expected: $expected"
        echo "      actual:   ${actual:-<empty/failed>}"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

# assert_not_resolving_to <description> <target> <self-branch> <forbidden-sha>
# Asserts _pick_closest_base "$target" "$self_branch" resolves to SOMETHING
# other than <forbidden-sha> — used for the self-exclusion rule, where the
# failure mode is resolving to the pushed branch's own tip (empty diff, gate
# silently skipped) rather than resolving to one specific correct answer.
# An empty result fails too: it would mean nothing was checked.
assert_not_resolving_to() {
    local description="$1" target="$2" self_branch="$3" forbidden="$4"
    local actual
    TESTS_RUN=$((TESTS_RUN + 1))
    actual="$(_pick_closest_base "$target" "$self_branch" 2>/dev/null)"
    if [ -n "$actual" ] && [ "$actual" != "$forbidden" ]; then
        echo "PASS: $description"
    else
        echo "FAIL: $description"
        echo "      target:    $target"
        echo "      self:      $self_branch"
        echo "      forbidden: $forbidden"
        echo "      actual:    ${actual:-<empty/failed>}"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

# --- Preconditions ---------------------------------------------------

MAIN_TIP="$(git rev-parse origin/main 2>/dev/null)"
if [ -z "$MAIN_TIP" ]; then
    # Fail rather than exit 0: a green run that executed zero scenarios would
    # silently disable this suite (e.g. after a switch to a shallow checkout).
    echo "FAIL: origin/main not resolvable in this checkout (no fetch yet?) — cannot run these scenarios." >&2
    exit 1
fi

# --- Scenario 1: the original #2024 bug ------------------------------------
# A branch whose tip IS origin/main's tip exactly (freshly cut, zero
# commits of its own yet) must resolve via origin/main itself, not fall
# through to an unrelated milestone branch. Uses a synthetic tag rather
# than the real origin/main ref as the "target" so this doesn't depend on
# any specific milestone branch existing/not existing in the checkout.
assert_resolves_to \
    "Scenario 1 (#2024): target's tip == origin/main's tip resolves via main" \
    "$MAIN_TIP" \
    "$MAIN_TIP"

# --- Scenario 2: strict-descendant exclusion still works --------------------
# A genuine scratch branch cut FROM a target's own tip (i.e. the target is
# a strict ancestor of it) must still be excluded as a candidate — this is
# the original, pre-#2024 exclusion rule's own purpose (#1751/#1767) and
# must not be broken by the #2024 equality-guard fix.
TARGET_TIP="$(make_commit_on "$MAIN_TIP" "synthetic: target branch, 1 commit ahead of main")"
# The tag is never read again below (assert_resolves_to uses $TARGET_TIP
# directly) — it exists solely to root this loose commit against garbage
# collection for the lifetime of the script, since a commit-tree commit
# with no ref pointing to it is otherwise GC-eligible immediately.
git tag -f __test_target "$TARGET_TIP" >/dev/null 2>&1
SYNTH_TAGS+=("__test_target")
SCRATCH_TIP="$(make_commit_on "$TARGET_TIP" "synthetic: scratch branch cut FROM target after the fact")"
git branch -f milestone/__test_scratch_from_target "$SCRATCH_TIP" >/dev/null 2>&1
SYNTH_BRANCHES+=("milestone/__test_scratch_from_target")
# With the scratch branch correctly excluded, main remains the closest
# valid candidate (1 commit ahead) — the scratch branch must NOT win by
# appearing to be "0 commits ahead" of a merge-base that is actually
# target's own tip.
assert_resolves_to \
    "Scenario 2 (#1751/#1767): scratch branch cut FROM target is still excluded" \
    "$TARGET_TIP" \
    "$MAIN_TIP"

# --- Scenario 3: PR #2029's counter-case (the regression the first #2024 fix attempt introduced) ---
# A milestone branch cut from main's CURRENT tip (zero main-side divergence
# since), with an issue branch one commit ahead of THAT milestone branch,
# must resolve via the milestone branch — not via main, even though main is
# transitively an ancestor of the issue branch's history.
MS_TIP="$(make_commit_on "$MAIN_TIP" "synthetic: milestone branch cut from main's current tip")"
git branch -f milestone/__test_active "$MS_TIP" >/dev/null 2>&1
SYNTH_BRANCHES+=("milestone/__test_active")
ISSUE_TIP="$(make_commit_on "$MS_TIP" "synthetic: issue branch cut from the milestone branch")"
assert_resolves_to \
    "Scenario 3 (PR #2029 regression case): milestone branch wins over main when it's the true closer parent" \
    "$ISSUE_TIP" \
    "$MS_TIP"

# --- Scenario 4 (#2160): a milestone branch at the target's exact tip is rejected ---
# Only origin/main keeps the tip-equality exemption (Scenario 1). A local or
# remote milestone branch whose tip IS the target's tip (e.g. fast-forwarded
# to the issue branch) would otherwise win with 0 commits ahead, give an
# empty diff and silently skip the gate. It must fall through to main.
# (Before #2160 this scenario asserted the opposite.)
MS_TIP_2="$(make_commit_on "$MAIN_TIP" "synthetic: another milestone branch")"
git branch -f milestone/__test_equality "$MS_TIP_2" >/dev/null 2>&1
SYNTH_BRANCHES+=("milestone/__test_equality")
assert_resolves_to \
    "Scenario 4a (#2160): a local milestone branch at the target's exact tip is rejected" \
    "$MS_TIP_2" \
    "$MAIN_TIP"
EQ_REMOTE_TIP="$(make_commit_on "$MAIN_TIP" "synthetic: remote milestone at the target's tip")"
git update-ref refs/remotes/origin/milestone/__test_equality_remote "$EQ_REMOTE_TIP" >/dev/null 2>&1
SYNTH_REFS+=("refs/remotes/origin/milestone/__test_equality_remote")
assert_resolves_to \
    "Scenario 4b (#2160): a remote milestone branch at the target's exact tip is rejected" \
    "$EQ_REMOTE_TIP" \
    "$MAIN_TIP"

# --- Scenario 5 (#2160): remote-only milestone branch is a candidate --------
# A milestone branch that exists only as refs/remotes/origin/milestone/* —
# no local branch of that name — must be rankable. Before #2160 the
# candidate list was local refs/heads/milestone/* plus origin/main only, so
# an issue branch cut from a teammate's (or a not-yet-checked-out) milestone
# branch fell all the way back to main and gated on the milestone's entire
# accumulated diff.
REMOTE_MS_TIP="$(make_commit_on "$MAIN_TIP" "synthetic: remote-only milestone branch")"
git update-ref refs/remotes/origin/milestone/__test_remote "$REMOTE_MS_TIP" >/dev/null 2>&1
SYNTH_REFS+=("refs/remotes/origin/milestone/__test_remote")
REMOTE_ISSUE_TIP="$(make_commit_on "$REMOTE_MS_TIP" "synthetic: issue branch cut from the remote-only milestone")"
assert_resolves_to \
    "Scenario 5 (#2160): remote-only origin/milestone/* branch is a valid candidate" \
    "$REMOTE_ISSUE_TIP" \
    "$REMOTE_MS_TIP"

# --- Scenario 6 (#2160): a milestone branch never resolves to itself --------
# Pushing milestone/__test_active itself must not pick milestone/__test_active
# as its own parent — that would make the merge-base the branch's own tip,
# yield an empty diff, and silently skip the gate on every milestone push.
assert_not_resolving_to \
    "Scenario 6 (#2160): pushing a milestone/* branch does not resolve to itself" \
    "$MS_TIP" \
    "milestone/__test_active" \
    "$MS_TIP"

# --- Scenario 7 (#2160): the LOCAL branch name is excluded too -------------
# `git push origin milestone/__test_active:milestone/__test_elsewhere` —
# the remote name alone would not exclude the local milestone branch. Here
# the target is one commit past it, so without the local-name exclusion it
# would win as the parent (1 commit ahead) instead of origin/main (2 ahead).
assert_resolves_to \
    "Scenario 7 (#2160): the pushed LOCAL branch name is excluded as a candidate" \
    "$ISSUE_TIP" \
    "$MAIN_TIP" \
    "milestone/__test_elsewhere" \
    "milestone/__test_active"

# --- Report ---------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
