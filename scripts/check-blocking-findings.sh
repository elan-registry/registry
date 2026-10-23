#!/usr/bin/env bash
# Checks the CI review verdict of a PR for unresolved Blocking findings, and
# optionally for Important findings.
#
# The workflow step "Post review" in claude-code-review.yml renders the review
# from the structured output of the model. Code counts the findings, so the
# counts cannot disagree with the text. The step posts the review as
# github-actions[bot], and the last line of the comment is a hidden verdict
# line:
#
#   <!-- claude-review-verdict sha=<head SHA> blocking=<n> important=<n> -->
#
# This script reads the counts from that line. It does not read the headings,
# because a model can write any heading text. The CI gate reads the same
# counts from the structured output, so this script and the gate agree.
#
# A verdict counts only when all of these are true:
#   - The author is github-actions[bot]. Only a workflow run that holds the
#     GITHUB_TOKEN of this repo posts as that account, and that needs write
#     access to the repo. A fork PR gets a read-only token, so it cannot post.
#   - The verdict line is the last line of the body, in the exact format.
#   - The SHA in the verdict is the current head commit of the PR.
# When more than one verdict counts, the newest one wins (by created_at, then
# by comment ID). The script reads all pages of comments. A human comment, a
# claude[bot] reply, a verdict for an older commit, and an old-format review
# (no verdict line) do not count.
#
# Usage: scripts/check-blocking-findings.sh <pr-number> [--include-important]
#
# The script finds each argument by its shape, not by its position. So
# `--include-important` can come before or after the PR number.
#
# Without --include-important, only `blocking` counts. This is the CI gate
# contract: CI treats Important findings as advice. With --include-important,
# `important` also counts. The pre-merge commands (/release-milestone,
# /review-milestone) use this flag because they are stricter than CI.
#
# Exit codes:
#   0 = clean: the newest verdict has blocking=0 (and important=0 with
#       --include-important)
#   1 = at least one unresolved Blocking finding (or Important finding, with
#       --include-important). The script prints the counts to stdout.
#   2 = cannot verify: no review comment with a verdict for the head commit
#       was found, a gh call failed (auth, network, rate limit, 404), the head
#       SHA is not valid, or the arguments are not valid.
#       Never treat exit 2 as clean. It means the check did not run.

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
  echo "check-blocking-findings.sh: cannot verify the Blocking/Important status of PR #${PR_NUM} (NOT evidence of a clean PR)." >&2
  exit 2
fi

if [ -z "$VERDICTS" ]; then
  echo "No review comment with a verdict for the head commit of PR #${PR_NUM} — cannot verify the Blocking/Important status." >&2
  exit 2
fi

IFS=$'\t' read -r _ REVIEW_ID BLOCKING IMPORTANT <<< "${VERDICTS##*$'\n'}"

# The counts are digits only (the verdict rule checks this). A string test,
# not arithmetic, so a very long number cannot overflow.
has_findings() {
  case "$1" in
    *[!0]*) return 0 ;;
    *) return 1 ;;
  esac
}

if has_findings "$BLOCKING" || { [ "$INCLUDE_IMPORTANT" -eq 1 ] && has_findings "$IMPORTANT"; }; then
  echo "Unresolved findings in review comment ${REVIEW_ID} on PR #${PR_NUM}: blocking=${BLOCKING} important=${IMPORTANT}"
  exit 1
fi

exit 0
