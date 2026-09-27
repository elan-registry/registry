#!/bin/bash
#
# Prints the review/diff base ref for a branch: the milestone branch it
# forked from, or origin/main if none applies. Several commands
# (security-review.md, review-pr.md, address-pr-comments.md) each derived
# this ref with their own ad hoc shell, mostly a simpler, less-tested version
# of .githooks/pre-push's _pick_closest_base(). This script gives every
# caller the same, already-regression-tested logic instead
# (tests/hooks/test-pick-closest-base.sh).
#
# Usage: scripts/resolve-base-branch.sh [branch]
#   branch  Optional. The branch to resolve a base for. Defaults to the
#           current branch (git branch --show-current).
#
# Prints the resolved base ref on stdout, one of:
#   - a `milestone/*` branch name (local or origin/milestone/*) when the
#     given branch is closest to one
#   - origin/main otherwise
#
# Exit codes:
#   0  Base ref found; printed on stdout.
#   1  Could not determine a base ref (no origin/main, no milestone/*
#      branch, or git itself failed). Nothing is printed on stdout.
#
# WHY A REF NAME, NOT A SHA. _pick_closest_base() (in
# scripts/lib/pick-closest-base.sh) returns a merge-base SHA, tuned for
# .githooks/pre-push's diff-since-push use. Callers of this script mostly
# want a symbolic ref for `git diff <ref>...HEAD` or a PR base name, so this
# script re-derives the winning ref name (not just its SHA) using the same
# candidate list and ranking.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib/pick-closest-base.sh
source "${SCRIPT_DIR}/lib/pick-closest-base.sh"

if [ "$#" -gt 1 ]; then
    echo "Usage: $(basename "$0") [branch]" >&2
    exit 1
fi

branch="${1:-}"
if [ -z "$branch" ]; then
    branch="$(git branch --show-current 2>/dev/null)" || true
fi

if [ -z "$branch" ]; then
    echo "resolve-base-branch.sh: could not determine the current branch (detached HEAD?) and none was given." >&2
    exit 1
fi

if [ "$branch" = "main" ]; then
    if git rev-parse --verify --quiet 'origin/main^{commit}' >/dev/null; then
        printf '%s\n' "origin/main"
        exit 0
    fi
    echo "resolve-base-branch.sh: origin/main not resolvable." >&2
    exit 1
fi

target_sha="$(git rev-parse --verify --quiet "${branch}^{commit}" 2>/dev/null)" || true
if [ -z "$target_sha" ]; then
    echo "resolve-base-branch.sh: branch '$branch' does not resolve to a commit." >&2
    exit 1
fi

# Re-run the same ranking _pick_closest_base uses, but keep the candidate's
# NAME (not only its merge-base SHA) — this loop is intentionally a thin
# mirror of that function's internals rather than a second copy of the
# rejection rules, so any behavior change there (the tested contract) stays
# the single source of truth; only the "what do we print" step differs here.
best_candidate=""
best_count=""
while IFS= read -r candidate; do
    [ -z "$candidate" ] && continue
    case "$candidate" in
        "$branch"|"origin/$branch")
            continue
            ;;
    esac
    base="$(git merge-base "$target_sha" "$candidate" 2>/dev/null)" || continue
    candidate_sha="$(git rev-parse "$candidate" 2>/dev/null)" || continue
    if [ "$target_sha" = "$candidate_sha" ]; then
        [ "$candidate" = "origin/main" ] || continue
    elif git merge-base --is-ancestor "$target_sha" "$candidate" 2>/dev/null; then
        continue
    fi
    count="$(git rev-list --count "$base".."$target_sha" 2>/dev/null)" || continue
    case "$count" in
        ''|*[!0-9]*)
            continue
            ;;
    esac
    if [ -z "$best_count" ] || [ "$count" -lt "$best_count" ]; then
        best_count="$count"
        best_candidate="$candidate"
    fi
done <<< "$(git for-each-ref --format='%(refname:short)' 'refs/heads/milestone/*' 'refs/remotes/origin/milestone/*' 2>/dev/null; echo 'origin/main')"

if [ -n "$best_candidate" ]; then
    printf '%s\n' "$best_candidate"
    exit 0
fi

echo "resolve-base-branch.sh: could not resolve a base for '$branch' (no origin/main, no milestone/* branch?)." >&2
exit 1
