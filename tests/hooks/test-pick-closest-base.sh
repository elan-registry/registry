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

cleanup() {
    local ref
    for ref in "${SYNTH_TAGS[@]:-}"; do
        [ -n "$ref" ] && git tag -d "$ref" >/dev/null 2>&1
    done
    for ref in "${SYNTH_BRANCHES[@]:-}"; do
        [ -n "$ref" ] && git branch -D "$ref" >/dev/null 2>&1
    done
}
trap cleanup EXIT

# make_commit_on <parent-commit-ish> <message> — creates a new commit with
# the same tree as its parent (no actual file changes needed for these
# topology tests) and echoes its SHA.
make_commit_on() {
    local parent="$1" message="$2"
    git commit-tree "${parent}^{tree}" -p "$parent" -m "$message"
}

# assert_resolves_to <description> <target> <expected-base-sha>
assert_resolves_to() {
    local description="$1" target="$2" expected="$3"
    local actual
    TESTS_RUN=$((TESTS_RUN + 1))
    actual="$(_pick_closest_base "$target" 2>/dev/null)"
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

# --- Preconditions ---------------------------------------------------

MAIN_TIP="$(git rev-parse origin/main 2>/dev/null)"
if [ -z "$MAIN_TIP" ]; then
    echo "SKIP: origin/main not resolvable in this checkout (no fetch yet?) — cannot run these scenarios." >&2
    exit 0
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

# --- Scenario 4: milestone-tip-equality (bonus case found during #2024 review) ---
# Symmetric to Scenario 1, but for a milestone branch instead of main: a
# branch whose tip IS a milestone branch's tip exactly must resolve via
# that milestone branch, not fall through to origin/main.
MS_TIP_2="$(make_commit_on "$MAIN_TIP" "synthetic: another milestone branch")"
git branch -f milestone/__test_equality "$MS_TIP_2" >/dev/null 2>&1
SYNTH_BRANCHES+=("milestone/__test_equality")
assert_resolves_to \
    "Scenario 4 (bonus, found during #2024 review): target's tip == a milestone branch's tip resolves via that milestone branch" \
    "$MS_TIP_2" \
    "$MS_TIP_2"

# --- Report ---------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
