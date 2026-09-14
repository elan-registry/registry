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
#   2 = no review comment found to check (caller should treat this as "can't verify", not "clean")

set -euo pipefail

PR_NUM="${1:?Usage: check-blocking-findings.sh <pr-number> [--include-important]}"
INCLUDE_IMPORTANT="${2:-}"

REVIEW_BODY=$(gh api "repos/elan-registry/registry/issues/${PR_NUM}/comments" \
  --jq '[.[] | select(.body | test("#{1,6}\\s+Strengths|\\*\\*Strengths\\*\\*"))] | last | .body // ""' \
  2>/dev/null || echo "")

if [ -z "$REVIEW_BODY" ]; then
  echo "No posted review comment found (no Strengths-anchored comment) — cannot verify Blocking/Important status." >&2
  exit 2
fi

# Same regex as claude-code-review.yml: match a Blocking heading (markdown
# heading or bold form, allowing trailing words like "Blocking findings"),
# exclude lines that are a recap of an already-resolved prior round.
HEADING_PATTERN='#{1,6}[[:space:]]+Blocking([[:space:]]|$)|\*\*Blocking\*\*([[:space:]]|$)'
if [ "$INCLUDE_IMPORTANT" = "--include-important" ]; then
  HEADING_PATTERN="${HEADING_PATTERN}|#{1,6}[[:space:]]+Important([[:space:]]|$)|\\*\\*Important\\*\\*([[:space:]]|$)"
fi

MATCHES=$(echo "$REVIEW_BODY" \
  | grep -E "^(${HEADING_PATTERN})" \
  | grep -viE 'resolved|previous round|prior round|earlier round' || true)

if [ -n "$MATCHES" ]; then
  echo "Unresolved finding heading(s):"
  echo "$MATCHES"
  exit 1
fi

exit 0
