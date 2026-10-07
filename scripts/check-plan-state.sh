#!/bin/bash
#
# Reports the state of an issue's plan file: its path, whether it is
# approved, and its checklist progress. execute-plan.md Step 1 and
# finish-issue.md's plan-file lookup each repeated the same ls/grep pattern
# separately — this script gives both one shared, tested check.
#
# Usage: scripts/check-plan-state.sh [issue-number]
#   issue-number  Optional. If omitted, this script derives it from the
#                 current branch name (e.g. issue/423-car-data-export -> 423,
#                 bug/512-negative-price -> 512, feature/423-export -> 423).
#
# Looks for docs/plans/issues/issue-<NUMBER>-*.md (the current location) and,
# only if that has no match, the older docs/plans/issue-<NUMBER>-*.md.
#
# On stdout, one line per field:
#   path: <file path, or (none)>
#   approved: yes|no
#   checklist: <done>/<total>   (an item marked `- [ ] <item> — N/A: <reason>`
#                                counts as done)
#
# Exit codes:
#   0  Plan file found and its Status line is exactly
#      "Approved — ready for /execute-plan".
#   1  No plan file found for the issue. A note on stderr names the
#      directory searched and the wrong-clone-or-worktree cause.
#   2  Plan file found but not approved (any other Status line value).
#   3  No issue number given and none could be derived from the branch name.
#
# Multiple matching files (e.g. a stale plan from an earlier, differently
# named attempt) are all listed on the path: line, comma-separated, and the
# rest of the report is computed from the first match — same ambiguity
# execute-plan.md already asks the user about, so this script surfaces it
# rather than silently picking one.

set -euo pipefail

if [ "$#" -gt 1 ]; then
    echo "Usage: $(basename "$0") [issue-number]" >&2
    exit 3
fi

issue_number="${1:-}"

if [ -z "$issue_number" ]; then
    branch="$(git branch --show-current 2>/dev/null)" || true
    case "$branch" in
        issue/[0-9]*-*|bug/[0-9]*-*|feature/[0-9]*-*)
            rest="${branch#*/}"
            issue_number="${rest%%-*}"
            ;;
        *)
            echo "check-plan-state.sh: no issue number given, and could not derive one from branch '${branch:-<none>}'." >&2
            exit 3
            ;;
    esac
fi

case "$issue_number" in
    ''|*[!0-9]*)
        echo "check-plan-state.sh: derived/given issue number '$issue_number' is not numeric." >&2
        exit 3
        ;;
esac

shopt -s nullglob
candidates=(docs/plans/issues/issue-"${issue_number}"-*.md docs/plans/issue-"${issue_number}"-*.md)
shopt -u nullglob

if [ "${#candidates[@]}" -eq 0 ]; then
    echo "path: (none)"
    echo "approved: no"
    echo "checklist: 0/0"
    echo "check-plan-state.sh: no plan file for issue #${issue_number} under $(pwd)/docs/plans/. docs/plans/ is gitignored, so each clone and worktree has its own copy: check that this is the clone or worktree where the issue was planned." >&2
    exit 1
fi

plan_file="${candidates[0]}"

joined="${candidates[0]}"
for f in "${candidates[@]:1}"; do
    joined="${joined}, ${f}"
done
echo "path: ${joined}"

status_line="$(grep -m1 '^\*\*Status:\*\*' "$plan_file" 2>/dev/null || true)"
if [ "$status_line" = "**Status:** Approved — ready for /execute-plan" ]; then
    approved="yes"
else
    approved="no"
fi
echo "approved: ${approved}"

total="$(grep -c -E '^- \[[ xX]\]' "$plan_file" 2>/dev/null || true)"
done_count="$(grep -c -E '^- \[[xX]\]' "$plan_file" 2>/dev/null || true)"
na_count="$(grep -c -E '^- \[ \] .* — N/A: ' "$plan_file" 2>/dev/null || true)"
total="${total:-0}"
done_count=$(( ${done_count:-0} + ${na_count:-0} ))
echo "checklist: ${done_count}/${total}"

if [ "$approved" = "yes" ]; then
    exit 0
fi
exit 2
