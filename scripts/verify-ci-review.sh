#!/usr/bin/env bash
# Verifies that a CI code review posted on a PR for its head commit, and
# recovers once if it did not. Then it checks the review for unresolved
# Blocking findings (and Important findings, with --include-important).
#
# WHY THIS SCRIPT EXISTS: a successful claude-code-review.yml run is not proof
# that a review posted. Three failure modes can each give a successful job with
# no review:
#   1. GitHub's abuse or rate throttle can drop the webhook event, so no run
#      starts (PR #1718, #1724).
#   2. The action skips a PR that changes claude-code-review.yml itself,
#      because the workflow file must match the default branch. This is
#      intended security behavior. It does not clear until the PR merges to
#      main.
#   3. The model can stop before it returns a review.
# The only proof is the review comment itself, never the job conclusion. This
# script is the single place that checks the comment and runs the one
# recovery. Callers must not copy this logic.
#
# A review counts only when it has a verdict for the head commit. The workflow
# step "Post review" posts the review as github-actions[bot]. The last line of
# that comment is a hidden verdict line:
#
#   <!-- claude-review-verdict sha=<head SHA> blocking=<n> important=<n> -->
#
# A verdict for an older SHA reviewed an earlier head. The script then waits,
# and recovers, as it does when no review exists. A human comment, a
# claude[bot] reply, and an old-format review (no verdict line) do not count.
# Only a workflow run that holds the GITHUB_TOKEN of this repo posts as
# github-actions[bot], and that needs write access to the repo. A fork PR
# gets a read-only token, so it cannot post.
#
# It wraps three scripts that other callers also use, and that have their own
# tests: check-review-posted.sh (count the verdicts for the head commit),
# poll-review-posted.sh (wait for one), and check-blocking-findings.sh (read
# the counts in the newest verdict).
#
# Usage:
#   scripts/verify-ci-review.sh <pr-number> <interval-s> <timeout-s> \
#       --trigger=<workflow|label|none> [--include-important] [--check-skip-tag]
#
#   --trigger=workflow   recovery re-runs: gh workflow run claude-code-review.yml
#   --trigger=label      recovery re-applies the `deep-review` label (it
#                        removes the label first when the PR has it)
#   --trigger=none       no recovery trigger. A missing review after the poll
#                        window gives exit 4.
#   --include-important  after the review is confirmed, also fail (exit 2)
#                        when the verdict has important > 0
#   --check-skip-tag     before recovery, check the PR title for
#                        `[skip-review]`. milestone-review honors this tag and
#                        posts nothing on purpose. The script gives exit 3,
#                        not a failure.
#
# Exit codes:
#   0 = a verdict for the head commit exists, with blocking=0 (and
#       important=0 with --include-important)
#   1 = could not verify — a `gh` call failed (auth, network, rate limit),
#       the head commit could not be read, or the arguments are not valid.
#       This is NOT the same as "no review posted".
#   2 = a verdict for the head commit exists, but blocking > 0 (or, with
#       --include-important, important > 0). See stdout for the counts.
#   3 = no review, but the PR title has `[skip-review]` — the correct outcome
#       by design (--check-skip-tag only)
#   4 = no verdict for the head commit after the poll and one recovery
#       attempt (or after the poll alone, with --trigger=none)
#   5 = reserved (not used. --include-important failures use exit 2, so
#       every unresolved finding gives the same code)
#
# Recovery runs at most once. Sometimes recovery cannot help. For example,
# with --trigger=workflow, a PR that changes claude-code-review.yml cannot get
# a review before the merge. The script then prints why and exits 4. It does
# not loop.
#
# History: #1724 (a throttle dropped the review event of PR #1718), #2314
# (a review for an older head counted), #2506 (the old heading test missed a
# review that had no anchor heading).

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

PR_NUM=""
INTERVAL=""
TIMEOUT=""
TRIGGER=""
INCLUDE_IMPORTANT=0
CHECK_SKIP_TAG=0

for arg in "$@"; do
  case "$arg" in
    --trigger=*) TRIGGER="${arg#--trigger=}" ;;
    --include-important) INCLUDE_IMPORTANT=1 ;;
    --check-skip-tag) CHECK_SKIP_TAG=1 ;;
    ''|*[!0-9]*)
      echo "Unrecognized argument '$arg'." >&2
      exit 1
      ;;
    *)
      if [ -z "$PR_NUM" ]; then PR_NUM="$arg"
      elif [ -z "$INTERVAL" ]; then INTERVAL="$arg"
      elif [ -z "$TIMEOUT" ]; then TIMEOUT="$arg"
      else
        echo "Too many positional arguments." >&2
        exit 1
      fi
      ;;
  esac
done

if [ -z "$PR_NUM" ] || [ -z "$INTERVAL" ] || [ -z "$TIMEOUT" ]; then
  echo "Usage: verify-ci-review.sh <pr-number> <interval-s> <timeout-s> --trigger=<workflow|label|none> [--include-important] [--check-skip-tag]" >&2
  exit 1
fi

case "$TRIGGER" in
  workflow|label|none) ;;
  *)
    echo "Missing or invalid --trigger=<workflow|label|none>." >&2
    exit 1
    ;;
esac

REPO="elan-registry/registry"

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

# Does a verdict for the PR head commit exist? A verdict for an older SHA
# reviewed an earlier head, so a later run must not accept it (#2314).
# Returns 0 = fresh, 1 = no verdict for the head, 2 = could not verify.
review_is_fresh() {
  local verdicts
  if ! verdicts=$(head_verdicts "$PR_NUM"); then
    return 2
  fi
  if [ -z "$verdicts" ]; then
    echo "PR #${PR_NUM} has no review verdict for its head commit." >&2
    return 1
  fi
  return 0
}

# Waits for a review comment for the PR's head commit.
# Returns 0 = found, 1 = none after the timeout, 2 = could not verify.
poll() {
  local poll_status=0 fresh_status elapsed=0
  "$SCRIPT_DIR/poll-review-posted.sh" "$PR_NUM" "$INTERVAL" "$TIMEOUT" || poll_status=$?
  if [ "$poll_status" -ne 0 ]; then
    return "$poll_status"
  fi
  while :; do
    fresh_status=0
    review_is_fresh || fresh_status=$?
    if [ "$fresh_status" -ne 1 ]; then
      return "$fresh_status"
    fi
    if [ "$elapsed" -ge "$TIMEOUT" ]; then
      echo "No review comment for the head commit after ${TIMEOUT}s." >&2
      return 1
    fi
    sleep "$INTERVAL"
    elapsed=$((elapsed + INTERVAL))
  done
}

# Initial poll.
if poll; then
  : # comment found
else
  poll_status=$?
  if [ "$poll_status" -eq 2 ]; then
    echo "verify-ci-review.sh: could not verify (see the output above)." >&2
    exit 1
  fi

  # poll_status == 1: genuinely no comment yet.
  if [ "$CHECK_SKIP_TAG" -eq 1 ]; then
    TITLE=""
    if ! TITLE=$(gh pr view "$PR_NUM" --repo "$REPO" --json title -q .title 2>&1); then
      echo "verify-ci-review.sh: warning: could not read the PR title ('$TITLE'); skipping the [skip-review] check." >&2
      TITLE=""
    fi
    case "$TITLE" in
      *"[skip-review]"*)
        echo "PR title carries [skip-review] — no comment is the correct, by-design outcome."
        exit 3
        ;;
    esac
  fi

  if [ "$TRIGGER" = "none" ]; then
    echo "No review comment found after ${TIMEOUT}s; --trigger=none, no recovery attempted." >&2
    exit 4
  fi

  # Self-referential-workflow-file check applies only to the workflow trigger:
  # re-running the workflow cannot clear a skip caused by the workflow file
  # itself not yet being the one merged to main.
  if [ "$TRIGGER" = "workflow" ]; then
    # The files API, not `gh pr diff`: the diff endpoint returns HTTP 406 for
    # a diff over 20,000 lines, which a large PR easily has (#2225). The files
    # API lists at most 3,000 files; a PR larger than that is not checked fully.
    if ! PR_FILES=$(gh api "repos/${REPO}/pulls/${PR_NUM}/files" --paginate --jq '.[].filename'); then
      echo "verify-ci-review.sh: could not verify: the PR's file list could not be read, so the workflow-file guard cannot be checked." >&2
      exit 1
    fi
    # A here-string, not a piped `grep -q`: grep -q can exit at its first
    # match while gh still has output queued, SIGPIPEing gh, and pipefail
    # then turns that early exit into rc 141 — read as "no match" (#2225).
    if grep -Fxq '.github/workflows/claude-code-review.yml' <<< "$PR_FILES"; then
      echo "PR diff touches claude-code-review.yml itself — the action's own workflow-file-must-match-main guard blocks a pre-merge re-run from clearing this. Recovery skipped; this requires a merge to main first." >&2
      exit 4
    fi
    if ! RECOVERY_ERR=$(gh workflow run claude-code-review.yml --ref main --field "pr_number=${PR_NUM}" --repo "$REPO" 2>&1); then
      echo "verify-ci-review.sh: could not verify: recovery trigger failed:" >&2
      echo "  gh workflow run claude-code-review.yml --ref main --field pr_number=${PR_NUM} --repo ${REPO}" >&2
      echo "  $RECOVERY_ERR" >&2
      exit 1
    fi
  else
    # Adding a label that the PR already has sends no `labeled` event, so
    # remove it first.
    if ! LABELS=$(gh pr view "$PR_NUM" --repo "$REPO" --json labels --jq '.labels[].name' 2>&1); then
      echo "verify-ci-review.sh: could not verify: could not read the PR labels: $LABELS" >&2
      exit 1
    fi
    if grep -Fxq 'deep-review' <<< "$LABELS"; then
      if ! RECOVERY_ERR=$(gh pr edit "$PR_NUM" --remove-label "deep-review" --repo "$REPO" 2>&1); then
        echo "verify-ci-review.sh: could not verify: recovery trigger failed:" >&2
        echo "  gh pr edit ${PR_NUM} --remove-label deep-review --repo ${REPO}" >&2
        echo "  $RECOVERY_ERR" >&2
        exit 1
      fi
    fi
    if ! RECOVERY_ERR=$(gh pr edit "$PR_NUM" --add-label "deep-review" --repo "$REPO" 2>&1); then
      echo "verify-ci-review.sh: could not verify: recovery trigger failed:" >&2
      echo "  gh pr edit ${PR_NUM} --add-label deep-review --repo ${REPO}" >&2
      echo "  $RECOVERY_ERR" >&2
      exit 1
    fi
  fi

  echo "Recovery trigger sent (--trigger=${TRIGGER}); re-polling once."
  # Capture the status before testing it: `if ! poll; then s=$?` stores the
  # negated result, which is always 0 (#2222).
  poll_status=0
  poll || poll_status=$?
  if [ "$poll_status" -ne 0 ]; then
    if [ "$poll_status" -eq 2 ]; then
      echo "verify-ci-review.sh: could not verify after recovery (see output above)." >&2
      exit 1
    fi
    echo "No review comment found after recovery + a second ${TIMEOUT}s poll." >&2
    exit 4
  fi
fi

echo "Review comment confirmed on PR #${PR_NUM}."

if [ "$INCLUDE_IMPORTANT" -eq 1 ]; then
  FLAG="--include-important"
else
  FLAG=""
fi

check_status=0
"$SCRIPT_DIR/check-blocking-findings.sh" "$PR_NUM" $FLAG || check_status=$?
if [ "$check_status" -ne 0 ]; then
  if [ "$check_status" -eq 2 ]; then
    echo "verify-ci-review.sh: check-blocking-findings.sh could not verify findings." >&2
    exit 1
  fi
  exit 2
fi

exit 0
