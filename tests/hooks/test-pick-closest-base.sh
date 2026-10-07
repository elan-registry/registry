#!/bin/bash
#
# Regression test for _pick_closest_base() in scripts/lib/pick-closest-base.sh
# (used by .githooks/pre-push and scripts/resolve-base-branch.sh). It
# regressed three times in review (#1751, #1767, #2029) before this test.
#
# Uses this repo's real origin/main plus synthetic commits, branches and refs
# that cleanup() removes. It never changes an existing ref.
#
# Usage: bash tests/hooks/test-pick-closest-base.sh

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

# Run from inside a hook or rebase, these would redirect every git command
# below (including the synthetic ref creation and cleanup) to another repo.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY

# A fresh CI runner has no git identity, and git commit-tree fails without
# one. Set it for this process only, never in git config.
export GIT_AUTHOR_NAME="test-pick-closest-base"
export GIT_AUTHOR_EMAIL="test-pick-closest-base@localhost"
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME"
export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT" || exit 1

# Source the library, not .githooks/pre-push: sourcing the hook runs its
# main body, which reads git's pre-push protocol from stdin.
LIB_FILE="scripts/lib/pick-closest-base.sh"
if [ ! -s "$LIB_FILE" ]; then
    echo "FAIL: $LIB_FILE not found or empty" >&2
    exit 1
fi
# shellcheck source=/dev/null
source "$LIB_FILE"

# Synthetic refs this script creates, tracked for cleanup.
SYNTH_TAGS=()
SYNTH_BRANCHES=()
# Full refnames (e.g. refs/remotes/origin/milestone/*) that must be removed
# with `git update-ref -d` rather than `git branch -D`.
SYNTH_REFS=()

# shellcheck disable=SC2329 # called only through the EXIT trap in lib/harness.sh
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

# make_commit_on <parent-commit-ish> <message>: echoes a new commit with the
# parent's tree. Exits on failure, so an empty SHA cannot show up later as a
# misleading assertion failure.
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
    actual="$(_pick_closest_base "$target" "$self_remote" "$self_local" 2>/dev/null)"
    if [ "$actual" = "$expected" ]; then
        pass "$description"
    else
        fail "$description" "target:   $target" "expected: $expected" \
            "actual:   ${actual:-<empty/failed>}"
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
    actual="$(_pick_closest_base "$target" "$self_branch" 2>/dev/null)"
    if [ -n "$actual" ] && [ "$actual" != "$forbidden" ]; then
        pass "$description"
    else
        fail "$description" "target:    $target" "self:      $self_branch" \
            "forbidden: $forbidden" "actual:    ${actual:-<empty/failed>}"
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
# no local branch of that name — must be rankable. Otherwise an issue branch
# cut from a teammate's milestone branch falls back to main and gates on the
# milestone's entire diff.
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

harness_report
