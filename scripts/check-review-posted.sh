#!/usr/bin/env bash
# Counts the CI review verdicts for the head commit of a PR.
#
# A successful claude-code-review.yml run is not proof that a review posted.
# The action's workflow-file guard can skip the run, and the model can stop
# before it returns a result. So this script reads the comment itself.
#
# The workflow step "Post review" posts the review as github-actions[bot].
# The last line of that comment is a hidden verdict line:
#
#   <!-- claude-review-verdict sha=<head SHA> blocking=<n> important=<n> -->
#
# A comment counts only when all of these are true:
#   - The author is github-actions[bot]. Only a workflow run that holds the
#     GITHUB_TOKEN of this repo posts as that account, and that needs write
#     access to the repo. A fork PR gets a read-only token, so it cannot post.
#   - The last line of the body is a verdict line with the exact format.
#   - The SHA in the verdict is the current head commit of the PR.
# A verdict for an older commit reviewed old code, so it does not count.
# Text after the verdict line makes the comment not count. A human comment,
# a claude[bot] reply, or an old-format review (no verdict line) does not
# count.
#
# Usage: scripts/check-review-posted.sh <pr-number>
#
# On success, the script prints the count of verdicts for the head commit and
# exits 0. A count of 0 is a normal state while a caller polls. It is not an
# error.
#
# When a gh call fails (auth, rate limit, network, bad PR number), or the head
# SHA is not valid, the script prints nothing to stdout. It prints the error to
# stderr and exits 1. Callers must check the exit code. Exit 1 means "could
# not verify". It does not mean "no review posted". If a caller reads exit 1 as
# a count of 0, an expired token looks like a missing review.

set -uo pipefail

PR_NUM="${1:?Usage: check-review-posted.sh <pr-number>}"

# --- Verdict rule -----------------------------------------------------------
# The same rule is in check-review-posted.sh, check-blocking-findings.sh and
# verify-ci-review.sh. The "Post review" step of
# .github/workflows/claude-code-review.yml writes the verdict line. When one
# copy changes, change all of them.
#
# Explicit characters, not ranges: a range such as a-f can follow the locale.
VERDICT_RE='^<!-- claude-review-verdict sha=([0123456789abcdef]{40}) blocking=([0123456789]+) important=([0123456789]+) -->$'

# Prints one line per verdict for the PR head commit, oldest first:
# created_at, comment ID, blocking count, important count (tab-separated).
# Returns 1 when a gh call fails or the head SHA is not valid.
head_verdicts() {
  local pr="$1" head_sha records created id last_line found=""
  if ! head_sha=$(gh pr view "$pr" --repo elan-registry/registry --json headRefOid -q .headRefOid); then
    echo "Could not read the head commit of PR #${pr}." >&2
    return 1
  fi
  if ! [[ $head_sha =~ ^[0123456789abcdef]{40}$ ]]; then
    echo "The head commit of PR #${pr} is not a 40-character lowercase SHA (got '${head_sha}')." >&2
    return 1
  fi
  # gh runs the --jq filter on each page separately (--paginate). So the
  # filter prints one record per comment, and never a total. The record holds
  # the last line of the body, after the filter removes each CR and one
  # trailing newline. The filter is one line, so a log of the call is one line.
  local filter='.[] | select(.user.login == "github-actions[bot]")'
  filter+=' | [(.created_at // ""), (.id | tostring), ((.body // "") | gsub("\r"; "")'
  filter+=' | (if endswith("\n") then .[:-1] else . end) | split("\n") | (last // ""))] | @tsv'
  if ! records=$(gh api "repos/elan-registry/registry/issues/${pr}/comments" --paginate \
      --jq "$filter"); then
    echo "Could not read the comments of PR #${pr}." >&2
    return 1
  fi
  while IFS=$'\t' read -r created id last_line; do
    [[ $last_line =~ $VERDICT_RE ]] || continue
    [ "${BASH_REMATCH[1]}" = "$head_sha" ] || continue
    found+="${created}"$'\t'"${id}"$'\t'"${BASH_REMATCH[2]}"$'\t'"${BASH_REMATCH[3]}"$'\n'
  done <<< "$records"
  if [ -n "$found" ]; then
    printf '%s' "$found" | LC_ALL=C sort -t $'\t' -k1,1 -k2,2n
  fi
  return 0
}
# --- End of verdict rule ----------------------------------------------------

if ! VERDICTS=$(head_verdicts "$PR_NUM"); then
  echo "check-review-posted.sh: cannot verify the review status of PR #${PR_NUM}. This is NOT the same as 'no review posted'." >&2
  exit 1
fi

COUNT=0
while IFS= read -r verdict; do
  [ -n "$verdict" ] && COUNT=$((COUNT + 1))
done <<< "$VERDICTS"

echo "$COUNT"
