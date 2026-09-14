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
# Prints the count of matching comments (0 if none found). Exit code is
# always 0 — callers decide how to react (poll again, treat as failed
# trigger, etc.) per their own workflow step.
#
# Note: this same "Strengths"-heading test is also inlined directly in
# .github/workflows/claude-code-review.yml's own gate steps (both the
# pr-to-milestone-review and milestone-review jobs) — that's GitHub Actions
# YAML, which can't source a repo script mid-workflow the way these command
# files can, so it remains a separate, intentionally-parallel copy. Keep the
# regex here and there in sync if the review action's output format ever
# changes.

set -euo pipefail

PR_NUM="${1:?Usage: check-review-posted.sh <pr-number>}"

gh api "repos/elan-registry/registry/issues/${PR_NUM}/comments" \
  --jq '[.[] | select(.body | test("#{1,6}\\s+Strengths|\\*\\*Strengths\\*\\*"))] | length'
