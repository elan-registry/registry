#!/usr/bin/env bash
# Checks whether a Claude Code review comment actually landed on a PR.
#
# A "successful" claude-code-review.yml job run is not proof a review was
# posted — the action's workflow-file-match guard can silently skip
# execution, or the agent can exhaust its turns before calling `gh pr
# comment`. The only reliable signal is the comment itself. Every Claude
# review (issue-level and milestone-level) includes a "Strengths" section,
# so its presence is the ground truth this script checks — same anchor
# claude-code-review.yml's own gate steps use (see #1724).
#
# Usage: scripts/check-review-posted.sh <pr-number>
#
# On success, prints the count of matching comments to stdout (0 if none
# found yet — that is a normal, expected state while polling, not an error)
# and exits 0.
#
# On failure to even query GitHub (auth expired, rate-limited, network
# down, bad PR number), prints nothing to stdout, prints the actual `gh`
# error to stderr, and exits 1. Callers MUST check the exit code — a
# nonzero exit means "could not verify," which is a different, more urgent
# problem than "count is 0, review just hasn't posted yet." Treating a
# nonzero exit as if it printed "0" silently converts a real operational
# failure (e.g. an expired token) into a false "review never posted"
# diagnosis.
#
# Note: this same "Strengths"-heading test is also inlined directly in
# .github/workflows/claude-code-review.yml's own gate steps (both the
# pr-to-milestone-review and milestone-review jobs) — that's GitHub Actions
# YAML, which can't source a repo script mid-workflow the way these command
# files can, so it remains a separate, intentionally-parallel copy. Keep the
# regex here and there in sync if the review action's output format ever
# changes.

set -uo pipefail

PR_NUM="${1:?Usage: check-review-posted.sh <pr-number>}"

GH_ERR="$(mktemp)"
trap 'rm -f "$GH_ERR"' EXIT

if ! COUNT=$(gh api "repos/elan-registry/registry/issues/${PR_NUM}/comments" \
    --jq '[.[] | select(.body | test("#{1,6}\\s+Strengths|\\*\\*Strengths\\*\\*"))] | length' \
    2>"$GH_ERR"); then
  echo "gh api call failed for PR #${PR_NUM} — cannot verify review status (this is NOT the same as 'no review posted'):" >&2
  cat "$GH_ERR" >&2
  exit 1
fi

echo "$COUNT"
