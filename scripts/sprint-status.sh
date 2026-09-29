#!/usr/bin/env bash
# Renders the current milestone's derived state: theme, issue status
# buckets, blocked items, and PR/branch lookups — the read-only report
# /sprint-status.md prints as-is. sprint-status.md keeps only the one step
# that needs judgment (the "theme sentence true yet?" call) and any
# disambiguation between multiple open milestones; every mechanical lookup
# moved here so both places can't drift on the same derivation.
#
# Usage: scripts/sprint-status.sh [version]
#   [version] — optional milestone title match (e.g. v2.30.0). Omit to use
#   the single open milestone. If more than one milestone is open and no
#   version was given, this script lists them and exits 3 rather than
#   guessing which one to report on.
#
# Output (stdout): the rendered report, in the exact section layout
# sprint-status.md's own Step 4 documents (Done / In review / In progress /
# Ready / Blocked / Needs attention), one issue per line.
#
# Exit codes:
#   0 = report rendered
#   1 = a `gh` API call failed (auth/network/rate-limit) — this is NOT the
#       same as "milestone has no issues"; nothing was printed
#   2 = usage error
#   3 = no version given and more than one milestone is open — the
#       candidate titles are printed to stdout for the caller to disambiguate
#   4 = named milestone (or, with no argument, the only open one) not found
set -uo pipefail

if [ "${1:-}" = "-h" ] || [ "${1:-}" = "--help" ]; then
  echo "Usage: scripts/sprint-status.sh [version]" >&2
  exit 2
fi

VERSION="${1:-}"
REPO="elan-registry/registry"

GH_ERR="$(mktemp)"
trap 'rm -f "$GH_ERR"' EXIT

if ! MILESTONES_JSON="$(gh api "repos/${REPO}/milestones" \
    --jq '[.[] | select(.state == "open")]' 2>"$GH_ERR")"; then
  echo "gh api call failed listing open milestones:" >&2
  cat "$GH_ERR" >&2
  exit 1
fi

OPEN_COUNT="$(echo "$MILESTONES_JSON" | jq 'length')"

if [ -n "$VERSION" ]; then
  MILESTONE="$(echo "$MILESTONES_JSON" | jq --arg v "$VERSION" \
    '[.[] | select(.title | startswith($v))] | .[0] // empty')"
  if [ -z "$MILESTONE" ]; then
    echo "No open milestone matching '${VERSION}'." >&2
    exit 4
  fi
elif [ "$OPEN_COUNT" -eq 1 ]; then
  MILESTONE="$(echo "$MILESTONES_JSON" | jq '.[0]')"
elif [ "$OPEN_COUNT" -eq 0 ]; then
  echo "No open milestones found." >&2
  exit 4
else
  echo "More than one milestone is open — pass a version to disambiguate:"
  echo "$MILESTONES_JSON" | jq -r '.[] | .title'
  exit 3
fi

MILESTONE_NUM="$(echo "$MILESTONE" | jq -r '.number')"
MILESTONE_TITLE="$(echo "$MILESTONE" | jq -r '.title')"
MILESTONE_DESC="$(echo "$MILESTONE" | jq -r '.description // "(no theme set)"')"

if ! ISSUES_JSON="$(gh api "repos/${REPO}/issues?milestone=${MILESTONE_NUM}&state=all&per_page=100" \
    --jq '[.[] | {number, title, state, labels: [.labels[].name]}]' 2>"$GH_ERR")"; then
  echo "gh api call failed listing issues for milestone #${MILESTONE_NUM}:" >&2
  cat "$GH_ERR" >&2
  exit 1
fi

echo "## Sprint Status — ${MILESTONE_TITLE}"
echo
echo "Theme: \"${MILESTONE_DESC}\""
echo

# --- Done ---------------------------------------------------------------
echo "### Done"
echo "$ISSUES_JSON" | jq -r '.[] | select(.state == "closed") | "- #\(.number) \(.title)"'
echo

# --- Open issues: classify each one --------------------------------------
OPEN_NUMBERS="$(echo "$ISSUES_JSON" | jq -r '.[] | select(.state == "open") | .number')"

BLOCKED=()
IN_REVIEW=()
IN_PROGRESS=()
READY=()
NEEDS_ATTENTION=()

for NUM in $OPEN_NUMBERS; do
  ISSUE="$(echo "$ISSUES_JSON" | jq --argjson n "$NUM" '.[] | select(.number == $n)')"
  TITLE="$(echo "$ISSUE" | jq -r '.title')"
  LABELS="$(echo "$ISSUE" | jq -r '.labels[]' 2>/dev/null)"

  if echo "$LABELS" | grep -qx "status:blocked"; then
    BLOCKED+=("- #${NUM} ${TITLE}")
    continue
  fi

  if ! PR_JSON="$(gh pr list --repo "$REPO" --search "linked:${NUM} is:open" \
      --json number,title,url 2>"$GH_ERR")"; then
    echo "gh pr list failed for issue #${NUM}:" >&2
    cat "$GH_ERR" >&2
    exit 1
  fi
  PR_NUM="$(echo "$PR_JSON" | jq -r '.[0].number // empty')"
  if [ -n "$PR_NUM" ]; then
    IN_REVIEW+=("- #${NUM} ${TITLE} — PR #${PR_NUM}")
    continue
  fi

  BRANCH="$(git ls-remote --heads origin "issue/${NUM}-*" 2>/dev/null | head -1 \
    | sed 's#.*refs/heads/##')"
  if [ -n "$BRANCH" ]; then
    IN_PROGRESS+=("- #${NUM} ${TITLE} — branch ${BRANCH}")
    continue
  fi

  if echo "$LABELS" | grep -qx "status:ready"; then
    READY+=("- #${NUM} ${TITLE}")
    continue
  fi

  NEEDS_ATTENTION+=("- #${NUM} ${TITLE} — no status label, entered milestone outside /plan-milestone")
done

echo "### In review"
printf '%s\n' "${IN_REVIEW[@]:-}" | grep -v '^$' || true
echo
echo "### In progress"
printf '%s\n' "${IN_PROGRESS[@]:-}" | grep -v '^$' || true
echo
echo "### Ready"
printf '%s\n' "${READY[@]:-}" | grep -v '^$' || true
echo
echo "### Blocked"
printf '%s\n' "${BLOCKED[@]:-}" | grep -v '^$' || true
echo
echo "### Needs attention"
printf '%s\n' "${NEEDS_ATTENTION[@]:-}" | grep -v '^$' || true

exit 0
