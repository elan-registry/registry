#!/usr/bin/env bash
# Detects unresolved Blocking (and optionally Important) findings in a PR's
# posted review comments, using the exact same heading regex and
# recap-exclusion logic as claude-code-review.yml's own merge gate
# (both pr-to-milestone-review and milestone-review jobs) — so a command-line
# check and CI's gate can never silently disagree about the same comment.
#
# Why not just eyeball the raw comment bodies: a plain read can mistake a
# recap heading ("### Blocking finding from the previous round: resolved")
# for a live finding, or miss a genuine section after an earlier recap in
# the same comment (#1843) — both are exactly why CI's gate uses a specific,
# hardened grep instead of free-text judgment, and why this script exists
# rather than reimplementing that judgment ad hoc per caller.
#
# Usage: scripts/check-blocking-findings.sh <pr-number> [--include-important]
#
# Arguments are recognized by shape, not position, so `--include-important`
# may come before or after the PR number.
#
# Without --include-important: matches CI's gate contract exactly — only
# `Blocking` headings count (Important is advisory in CI; the prompt's own
# calibration is "Blocking = verified only, suspicions go under Important").
# With --include-important: also treats `Important` headings as blocking —
# this repo's pre-merge commands (/release-milestone, /review-milestone) are
# intentionally stricter than CI here and should pass this flag.
#
# Checks the LAST comment matching the "Strengths" anchor (the same anchor
# check-review-posted.sh uses to identify a genuine posted review, as
# opposed to an arbitrary human comment that happens to contain the word
# "Blocking") — not every comment on the PR.
#
# Prints one line per non-excluded match found (for context) and exits:
#   0 = clean (no unresolved findings)
#   1 = at least one unresolved Blocking (or Important, if requested) finding
#   2 = can't verify — no review comment found, the gh API call itself
#       failed (auth/network/rate-limit/404), or the arguments were invalid.
#       Never treat exit 2 as "clean" — it means the check did not run.

set -euo pipefail

PR_NUM=""
INCLUDE_IMPORTANT=0

for arg in "$@"; do
  case "$arg" in
    --include-important)
      INCLUDE_IMPORTANT=1
      ;;
    ''|*[!0-9]*)
      echo "Unrecognized argument '$arg' (expected a numeric PR number or --include-important)." >&2
      exit 2
      ;;
    *)
      if [ -n "$PR_NUM" ]; then
        echo "Two PR numbers given ('$PR_NUM' and '$arg') — usage: check-blocking-findings.sh <pr-number> [--include-important]" >&2
        exit 2
      fi
      PR_NUM="$arg"
      ;;
  esac
done

if [ -z "$PR_NUM" ]; then
  echo "Usage: check-blocking-findings.sh <pr-number> [--include-important]" >&2
  exit 2
fi

GH_ERR="$(mktemp)"
trap 'rm -f "$GH_ERR"' EXIT

if ! REVIEW_BODY=$(gh api "repos/elan-registry/registry/issues/${PR_NUM}/comments" \
    --jq '[.[] | select(.body | test("#{1,6}\\s+Strengths|\\*\\*Strengths\\*\\*"))] | last | .body // ""' \
    2>"$GH_ERR"); then
  echo "gh api call failed for PR #${PR_NUM} — cannot verify Blocking/Important status (NOT evidence of a clean PR):" >&2
  cat "$GH_ERR" >&2
  exit 2
fi

if [ -z "$REVIEW_BODY" ]; then
  echo "No Strengths-anchored review comment found on PR #${PR_NUM} — cannot verify Blocking/Important status." >&2
  exit 2
fi

# Same regex as claude-code-review.yml: match a Blocking heading (markdown
# heading or bold form, allowing trailing words like "Blocking findings"),
# exclude lines that are a recap of an already-resolved prior round.
#
# The exclusion is intentionally narrow — anchored to actual recap phrasing
# ("previous/prior/earlier round", or the heading ending in "resolved") —
# rather than a bare `resolved` substring match, which would also exclude a
# live, forward-looking heading like "### Blocking issues, unresolved" (the
# substring "resolved" occurs inside "unresolved" too).
HEADING_PATTERN='#{1,6}[[:space:]]+Blocking([[:space:]]|$)|\*\*Blocking\*\*([[:space:]]|$)'
if [ "$INCLUDE_IMPORTANT" -eq 1 ]; then
  HEADING_PATTERN="${HEADING_PATTERN}"'|#{1,6}[[:space:]]+Important([[:space:]]|$)|\*\*Important\*\*([[:space:]]|$)'
fi

EXCLUSION_PATTERN='(previous|prior|earlier)[[:space:]]+round|:[[:space:]]*resolved[[:space:]]*$|\(resolved\)'

MATCHES=$(printf '%s\n' "$REVIEW_BODY" \
  | grep -E "^(${HEADING_PATTERN})" \
  | { grep -viE "$EXCLUSION_PATTERN" || [ $? -eq 1 ]; })

if [ -n "$MATCHES" ]; then
  echo "Unresolved finding heading(s):"
  printf '%s\n' "$MATCHES"
  exit 1
fi

exit 0
