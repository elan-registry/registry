#!/bin/bash
#
# Sourceable library: _pick_closest_base().
#
# WHY THIS IS A SEPARATE FILE. .githooks/pre-push and scripts/resolve-base-branch.sh
# both need this exact function. Before this file existed, the logic lived
# only in .githooks/pre-push, and tests/hooks/test-pick-closest-base.sh
# extracted it at test time by sed-ing the function body out of that file
# (see its own header). Keeping one copy here, sourced by both callers,
# removes the risk of the two copies drifting apart.
#
# This file defines a function. It does not run anything by itself.
# Source it, then call _pick_closest_base — do not execute it directly.

# Picks the closest true parent branch of $1 and echoes its merge-base with
# $1. Issue branches fork from a `milestone/*` branch, not `main`, so every
# candidate — local `refs/heads/milestone/*`, `refs/remotes/origin/milestone/*`
# and origin/main — is ranked together, and the one with the FEWEST commits
# between its merge-base and $1 wins. Local and remote branches of the same
# name are not deduped; the ranking already prefers the closer one.
#
# Rejected candidates:
#   - one whose tip EQUALS $1, except origin/main (#2024: a branch with no
#     commits beyond main must still resolve via main). Any other candidate
#     at $1's tip (e.g. a milestone branch fast-forwarded to the issue branch)
#     would win with 0 commits and empty the diff (#2160);
#   - one that has $1 as an ancestor, i.e. was cut from $1's own tip.
# A candidate that moved on after $1 forked from it is still accepted — it is
# $1's true parent even though $1 is not reachable from it (PR #1767).
#
# $2 and $3, both optional, are the remote and local names of the branch
# being pushed (they differ for `git push origin feature:milestone/x`). Any
# candidate equal to either, or to origin/<either>, is skipped so a
# `milestone/*` push never resolves to itself (#2160).
#
# Echoes the winning base SHA, and the winning candidate + commit count to
# stderr. Returns non-zero (echoing nothing) if no candidate resolves.
_pick_closest_base() {
    local target="$1"
    local self_remote="${2:-}" self_local="${3:-}"
    local best_base="" best_count="" best_candidate=""
    # Also exposed to a caller that does not use a subshell, so
    # scripts/resolve-base-branch.sh can print the winning ref name.
    # shellcheck disable=SC2034  # read by scripts/resolve-base-branch.sh
    PICK_CLOSEST_CANDIDATE=""
    local candidate base count candidate_sha target_sha

    target_sha="$(git rev-parse --verify --quiet "${target}^{commit}" 2>/dev/null)" || return 1
    [ -n "$target_sha" ] || return 1

    while IFS= read -r candidate; do
        [ -z "$candidate" ] && continue
        # Never rank the pushed branch against itself — see above.
        case "$candidate" in
            "$self_remote"|"origin/$self_remote"|"$self_local"|"origin/$self_local")
                continue
                ;;
        esac
        base="$(git merge-base "$target_sha" "$candidate" 2>/dev/null)" || continue
        candidate_sha="$(git rev-parse "$candidate" 2>/dev/null)" || continue
        # See the function comment above for both rejection rules.
        if [ "$target_sha" = "$candidate_sha" ]; then
            [ "$candidate" = "origin/main" ] || continue
        elif git merge-base --is-ancestor "$target_sha" "$candidate" 2>/dev/null; then
            continue
        fi
        count="$(git rev-list --count "$base".."$target_sha" 2>/dev/null)" || continue
        case "$count" in
            ''|*[!0-9]*)
                echo "  WARNING: unexpected 'git rev-list --count' output for candidate '$candidate': '$count' — skipping it." >&2
                continue
                ;;
        esac
        if [ -z "$best_count" ] || [ "$count" -lt "$best_count" ]; then
            best_base="$base"
            best_count="$count"
            best_candidate="$candidate"
        fi
    done <<< "$(git for-each-ref --format='%(refname:short)' 'refs/heads/milestone/*' 'refs/remotes/origin/milestone/*' 2>/dev/null; echo 'origin/main')"

    if [ -n "$best_base" ]; then
        # shellcheck disable=SC2034
        PICK_CLOSEST_CANDIDATE="$best_candidate"
        echo "  (merge-base resolved via closest branch: $best_candidate, $best_count commit(s) ahead)" >&2
        printf '%s\n' "$best_base"
        return 0
    fi
    return 1
}
