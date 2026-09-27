#!/usr/bin/env bash
# Fetches every review finding on a PR — review/issue comments, inline code
# comments, and CI check annotations for failed checks — and prints them as
# one JSON object. /address-pr-comments used to re-derive this same
# sequence of `gh`/`gh api` calls inline; it's centralized here so the
# fetch logic has one place to fix instead of several.
#
# Usage: scripts/fetch-pr-findings.sh <pr-number>
#
# Output (stdout): a single JSON object:
#   {
#     "reviews_and_comments": [...],   // gh pr view --json reviews,comments
#     "inline_comments": [...],        // path, line, body, user
#     "failed_checks": [               // one entry per non-passing check
#       {
#         "name": ..., "conclusion": ..., "id": ...,
#         "output_summary": ...,
#         "annotations": [ { path, start_line, message, annotation_level }, ... ]
#       }, ...
#     ]
#   }
#
# Exit codes:
#   0 = fetched successfully (an empty findings set is a valid, clean result)
#   1 = could not query GitHub at all (auth/network/rate-limit/bad PR
#       number) — NOT the same as "no findings"; the caller must not treat
#       this as a clean PR
set -uo pipefail

REPO="elan-registry/registry"
PR_NUM="${1:?Usage: fetch-pr-findings.sh <pr-number>}"

fail() {
  echo "$1" >&2
  exit 1
}

REVIEWS_COMMENTS="$(gh pr view "$PR_NUM" --repo "$REPO" --json reviews,comments 2>/tmp/fetch-pr-findings.err)" \
  || fail "gh pr view failed for PR #${PR_NUM}: $(cat /tmp/fetch-pr-findings.err)"

INLINE_COMMENTS="$(gh api "repos/${REPO}/pulls/${PR_NUM}/comments" \
  --jq '[.[] | {path, line, body, user: .user.login}]' 2>/tmp/fetch-pr-findings.err)" \
  || fail "gh api pulls/comments failed for PR #${PR_NUM}: $(cat /tmp/fetch-pr-findings.err)"

HEAD_SHA="$(gh pr view "$PR_NUM" --repo "$REPO" --json headRefOid --jq .headRefOid 2>/tmp/fetch-pr-findings.err)" \
  || fail "gh pr view (headRefOid) failed for PR #${PR_NUM}: $(cat /tmp/fetch-pr-findings.err)"

CHECK_RUNS="$(gh api "repos/${REPO}/commits/${HEAD_SHA}/check-runs" \
  --jq '[.check_runs[] | select(.conclusion != "success" and .conclusion != "neutral" and .conclusion != "skipped") | {name, conclusion, id, output_summary: .output.summary}]' \
  2>/tmp/fetch-pr-findings.err)" \
  || fail "gh api check-runs failed for PR #${PR_NUM}: $(cat /tmp/fetch-pr-findings.err)"

FAILED_CHECKS="[]"
while IFS= read -r run; do
  [ -z "$run" ] && continue
  RUN_ID="$(printf '%s' "$run" | jq -r '.id')"
  ANNOTATIONS="$(gh api "repos/${REPO}/check-runs/${RUN_ID}/annotations" \
    --jq '[.[] | {path, start_line, message, annotation_level}]' 2>/tmp/fetch-pr-findings.err)" \
    || fail "gh api annotations failed for check run ${RUN_ID}: $(cat /tmp/fetch-pr-findings.err)"
  ENRICHED="$(printf '%s' "$run" | jq --argjson ann "$ANNOTATIONS" '. + {annotations: $ann}')"
  FAILED_CHECKS="$(printf '%s' "$FAILED_CHECKS" | jq --argjson item "$ENRICHED" '. + [$item]')"
done < <(printf '%s' "$CHECK_RUNS" | jq -c '.[]')

rm -f /tmp/fetch-pr-findings.err

jq -n \
  --argjson reviews_and_comments "$REVIEWS_COMMENTS" \
  --argjson inline_comments "$INLINE_COMMENTS" \
  --argjson failed_checks "$FAILED_CHECKS" \
  '{reviews_and_comments: $reviews_and_comments, inline_comments: $inline_comments, failed_checks: $failed_checks}'
