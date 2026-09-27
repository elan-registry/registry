#!/usr/bin/env bash
# Finds tests still tagged #[Group('known-broken')] and cross-checks each
# tag's cited issue against its current GitHub state.
#
# `composer test:quick:ci` (the CI-blocking test run) excludes any test
# tagged this way (see tests/README.md's "CI vs. Local Test Runs" section).
# The tag exists so a pre-existing, unrelated, already-tracked bug never
# blocks landing an otherwise-unrelated PR — but it's meant to be
# temporary. finish-milestone.md Step 3.5 and review-milestone.md Step 2
# both need the same list (test name, file, cited issue, that issue's
# state) to ask the user the same accept/resolve/stop question; this
# script is the one place that derives it, so the two callers can't drift.
#
# Usage: scripts/check-known-broken-tests.sh [base]
#   [base] — git ref to diff against, currently UNUSED (the grep runs over
#   the working tree's tests/ directory regardless of ref, matching how
#   both callers already invoke it). Accepted for interface stability with
#   check-wip-markers.sh, which does use it.
#
# Output (stdout): one line per match, tab-separated:
#   <test file>\t<line>\t<cited issue number or (none)>\t<issue state or (unknown)>
#
# Exit codes:
#   0 = ran to completion, zero matches found (see stdout: empty)
#   1 = one or more matches found (see stdout for the list) — this is NOT
#       a hard failure, it's the signal callers act on (present to user,
#       ask for a decision)
#   2 = a `gh issue view` lookup failed for a cited issue (auth/network/
#       rate-limit) — the match list is still printed with "(lookup-failed)"
#       in the state column for that row; the caller should not treat that
#       row's state as "closed" or "open"
set -uo pipefail

MATCHES="$(grep -rn "Group('known-broken')" tests/ 2>/dev/null || true)"

if [ -z "$MATCHES" ]; then
  exit 0
fi

LOOKUP_FAILED=0

while IFS= read -r LINE; do
  [ -z "$LINE" ] && continue
  FILE="$(echo "$LINE" | cut -d: -f1)"
  # NOTE: deliberately not named LINENO — that name is a bash built-in,
  # read-only special variable, and assigning to it silently no-ops under
  # `set -u` (no error), leaving every downstream use holding the
  # interpreter's own current line number instead of the intended value.
  MATCH_LINE="$(echo "$LINE" | cut -d: -f2)"

  # The issue number is cited in the inline comment ABOVE the tag line
  # (e.g. `// #1470 — fails on Linux CI`), not on the tag line itself — so
  # look at both this line and the one just before it.
  PREV_MATCH_LINE=$((MATCH_LINE - 1))
  CONTEXT_LINES="$LINE"
  if [ "$PREV_MATCH_LINE" -ge 1 ]; then
    PREV_TEXT="$(sed -n "${PREV_MATCH_LINE}p" "$FILE" 2>/dev/null)"
    CONTEXT_LINES="${PREV_TEXT}"$'\n'"${LINE}"
  fi
  ISSUE_NUM="$(printf '%s\n' "$CONTEXT_LINES" | grep -oE '#[0-9]+' | head -1 | tr -d '#')"

  if [ -z "$ISSUE_NUM" ]; then
    printf '%s\t%s\t(none)\t(unknown)\n' "$FILE" "$MATCH_LINE"
    continue
  fi

  if STATE="$(gh issue view "$ISSUE_NUM" --repo elan-registry/registry \
      --json state --jq '.state' 2>/dev/null)"; then
    printf '%s\t%s\t%s\t%s\n' "$FILE" "$MATCH_LINE" "$ISSUE_NUM" "$STATE"
  else
    printf '%s\t%s\t%s\t(lookup-failed)\n' "$FILE" "$MATCH_LINE" "$ISSUE_NUM"
    LOOKUP_FAILED=1
  fi
done <<< "$MATCHES"

if [ "$LOOKUP_FAILED" -eq 1 ]; then
  exit 2
fi

exit 1
