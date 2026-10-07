#!/usr/bin/env bash
# Checks whether a milestone's current GitHub issue membership still matches
# the issues listed in its release notes' "Issues Resolved" section.
#
# Issues get moved in and out of a milestone over its lifetime: rescoped to a
# different milestone, split off, superseded, or consolidated after their PR
# already merged and their release-notes entry was already written. The
# release notes record scope at the time each issue was worked, so they can
# drift from the milestone's current membership.
#
# /finish-milestone Step 5.5 investigates the same kind of drift by hand,
# with its own inline grep over the whole release-notes file — that is a
# narrower, less careful version of this script's check (it does not
# exclude a same-bullet cross-reference), not the same check. Treat the two
# as independent, possibly-disagreeing gates, not duplicates.
# /release-milestone Step 2 calls this script just before its irreversible
# merge, because the milestone PR can stay open for hours or days after
# /finish-milestone ran and an issue's milestone can change in that window.
#
# Usage: scripts/check-milestone-scope-drift.sh <version> <milestone-number>
#
#   <version>           e.g. v2.30.5 (reads docs/releases/RELEASE_NOTES_<version>.md
#                        from the current working tree — the caller must have
#                        that file checked out and up to date; this script
#                        does not fetch or pull)
#   <milestone-number>  the numeric GitHub milestone number
#
# Membership counts issues in all states (a closed issue can still move to a
# different milestone later). Pull requests are not counted, because the
# GitHub issues API returns them too and a PR never has an "Issues Resolved"
# entry of its own. An empty membership set (0 non-PR issues ever assigned to
# this milestone number) is treated as "can't verify" (exit 2), not as a
# clean match — it usually means the wrong milestone number was passed.
#
# The release-notes side reads only the leading link of each "- [#N](...)"
# bullet under "## Issues Resolved". A cross-reference later in the same
# bullet ("deferred to #1895") is not an entry for that issue, so it must
# not count as one.
#
# Number comparison is done on LC_ALL=C-sorted, lexically-ordered input to
# `comm`, which requires lexical order — `comm` on mismatched-width numeric
# strings (e.g. "95" vs "2300") silently misreports under a numeric sort,
# since "2300" < "95" lexically. Numeric sort is used only for the final
# human-readable count and for display elsewhere.
#
# Exit codes:
#   0 = scope matches (same issue set on both sides)
#   1 = mismatch — prints each issue and which direction it is wrong:
#       "moved out of milestone since release notes were written" (in the
#       notes, not in the milestone) or "added to milestone, missing from
#       release notes" (in the milestone, not in the notes)
#   2 = can't verify — bad arguments, no release-notes file, no "Issues
#       Resolved" entries found in it, the gh API call failed, the milestone
#       has no non-PR issues in any state, or an unexpected tool failure
#       (grep/sed/sort/comm) occurred mid-check.
#       Never treat exit 2 as either "matches" or "mismatch": the check did
#       not run.

set -euo pipefail

trap 'echo "check-milestone-scope-drift: unexpected failure at line ${LINENO}. Cannot verify milestone scope (NOT evidence of a match)." >&2; exit 2' ERR

USAGE="Usage: check-milestone-scope-drift.sh <version> <milestone-number>"

if [ "$#" -ne 2 ]; then
  echo "$USAGE" >&2
  exit 2
fi

VERSION="$1"
MILESTONE_NUM="$2"

case "$MILESTONE_NUM" in
  ''|*[!0-9]*)
    echo "Milestone number must be numeric (got: '${MILESTONE_NUM}'). ${USAGE}" >&2
    exit 2
    ;;
esac

NOTES_FILE="docs/releases/RELEASE_NOTES_${VERSION}.md"

if [ ! -f "$NOTES_FILE" ]; then
  echo "No release notes at $NOTES_FILE — cannot verify milestone scope." >&2
  exit 2
fi

GH_ERR="$(mktemp)"
trap 'rm -f "$GH_ERR"' EXIT

# Tab-separated "number<TAB>state<TAB>title", one line per issue.
if ! MEMBERS=$(gh api --paginate \
    "repos/elan-registry/registry/issues?milestone=${MILESTONE_NUM}&state=all&per_page=100" \
    --jq '.[] | select(.pull_request == null) | "\(.number)\t\(.state)\t\(.title)"' \
    2>"$GH_ERR"); then
  echo "gh api call failed for milestone ${MILESTONE_NUM} — cannot verify milestone scope (NOT evidence of a match):" >&2
  cat "$GH_ERR" >&2
  exit 2
fi

# LC_ALL=C sort -u (lexical), not sort -un: these feed `comm`, which requires
# lexical order. See the header for why numeric order breaks `comm`.
MEMBER_NUMS=$(printf '%s\n' "$MEMBERS" | cut -f1 | { grep -E '^[0-9]+$' || [ $? -eq 1 ]; } | LC_ALL=C sort -u)

if [ -z "$MEMBER_NUMS" ]; then
  echo "Milestone ${MILESTONE_NUM} has no issues in any state — cannot verify milestone scope. Check the milestone number." >&2
  exit 2
fi

# grep -E and sed, not `grep -oP`: macOS ships BSD grep, which has no -P.
NOTES_NUMS=$(awk '/^## Issues Resolved/ { in_section = 1; next } /^## / { in_section = 0 } in_section' "$NOTES_FILE" \
  | { grep -oE '^- \[#[0-9]+\]\(https://github\.com/elan-registry/registry/issues/[0-9]+\)' || [ $? -eq 1 ]; } \
  | sed -E 's|.*/issues/([0-9]+)\)$|\1|' \
  | LC_ALL=C sort -u)

if [ -z "$NOTES_NUMS" ]; then
  echo "No \"- [#N](.../issues/N)\" entries found under \"## Issues Resolved\" in $NOTES_FILE — cannot verify milestone scope." >&2
  exit 2
fi

MOVED_OUT=$(LC_ALL=C comm -23 <(printf '%s\n' "$NOTES_NUMS") <(printf '%s\n' "$MEMBER_NUMS") | sed '/^$/d' | sort -n)
ADDED=$(LC_ALL=C comm -13 <(printf '%s\n' "$NOTES_NUMS") <(printf '%s\n' "$MEMBER_NUMS") | sed '/^$/d' | sort -n)

if [ -z "$MOVED_OUT" ] && [ -z "$ADDED" ]; then
  COUNT=$(printf '%s\n' "$NOTES_NUMS" | wc -l | tr -d '[:space:]')
  echo "Milestone scope matches release notes — ${COUNT} issues."
  exit 0
fi

echo "Milestone ${MILESTONE_NUM} scope does NOT match $NOTES_FILE:"
for n in $MOVED_OUT; do
  echo "  #${n} — moved out of milestone since release notes were written"
done
for n in $ADDED; do
  detail=$(printf '%s\n' "$MEMBERS" | awk -F '\t' -v n="$n" '$1 == n { print $2 ": " $3; exit }')
  echo "  #${n} — added to milestone, missing from release notes (${detail})"
done
exit 1
