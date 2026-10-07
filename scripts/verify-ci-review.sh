#!/usr/bin/env bash
# Verifies a CI code review actually posted on a PR, recovering once if it
# didn't, then checks the posted review for unresolved Blocking findings
# (and Important findings too, with --include-important).
#
# WHY THIS SCRIPT EXISTS: a "successful" claude-code-review.yml run is not
# proof a review was posted. Three independent failure modes can each leave
# a job at conclusion: success with zero comment:
#   1. GitHub's abuse/rate throttle can silently suppress the triggering
#      webhook event, so no run is ever created (hit on PR #1718 — #1724).
#   2. The action's own workflow-file-must-match-default-branch guard skips
#      execution on PRs that modify claude-code-review.yml itself — this is
#      intentional security behavior, not a bug, and it does not clear until
#      the PR merges to main.
#   3. The agent can exhaust its turns before calling `gh pr comment`.
# The only reliable signal that a review posted is the comment itself (the
# "Strengths" heading every real review includes) — never the job's own
# conclusion field. This script is the single place that checks the comment
# and drives the one-shot recovery; callers must not re-derive this logic.
#
# It wraps three scripts that stay separately testable and separately used
# elsewhere: check-review-posted.sh (does a matching comment exist),
# poll-review-posted.sh (wait for one), check-blocking-findings.sh (does
# the posted review contain an unresolved Blocking/Important finding).
#
# Usage:
#   scripts/verify-ci-review.sh <pr-number> <interval-s> <timeout-s> \
#       --trigger=<workflow|label|none> [--include-important] [--check-skip-tag]
#
#   --trigger=workflow   recovery re-runs: gh workflow run claude-code-review.yml
#   --trigger=label      recovery re-applies the `deep-review` label (it
#                        removes the label first when the PR has it)
#   --trigger=none       no recovery trigger is attempted; a missing comment
#                        after the poll window is reported as-is (exit 4)
#   --include-important  after a comment is confirmed, also fail (exit 2) on
#                        an unresolved `Important` heading, not only `Blocking`
#   --check-skip-tag     before recovering, check the PR title for
#                        `[skip-review]` — milestone-review honors this tag
#                        and deliberately posts nothing; treat that as
#                        exit 3, not a failure
#
# A comment counts only when it is for the PR's head commit: the newest
# review comment must be created after the head commit reached the branch
# (see review_is_fresh). An older comment reviewed an earlier head. The
# script then waits, and recovers, the same as when no comment exists.
#
# Exit codes:
#   0 = comment for the head commit confirmed, and no unresolved Blocking
#       finding (nor, with --include-important, an unresolved Important
#       finding)
#   1 = could not verify at all — a `gh` call failed (auth, network, rate
#       limit) or arguments were invalid. NOT the same as "no review posted."
#   2 = comment confirmed, but an unresolved Blocking (or, with
#       --include-important, Important) finding remains. See stdout for the
#       matched heading(s).
#   3 = no comment, but the PR title carries `[skip-review]` — the correct,
#       by-design outcome (--check-skip-tag only)
#   4 = no comment for the head commit after poll + one recovery attempt
#       (or after poll alone, with --trigger=none) — genuine "did not post"
#       outcome
#   5 = reserved (not currently emitted; --include-important failures use
#       exit 2 for a uniform "unresolved finding" contract)
#
# Recovery runs at most once. If recovery itself can't apply (e.g.
# --trigger=workflow but the PR's diff touches claude-code-review.yml, the
# documented self-referential skip that a re-run cannot clear pre-merge),
# this prints why and exits 4 rather than looping.
#
# History: #1724 (throttle silently ate PR #1718's review event), #1843
# (a plain-text "resolved" search matched a live "unresolved" heading).

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

# Is the newest review comment for the PR's head commit? A comment created
# before the head commit reached the branch reviewed an older head, so a
# later run must not accept it (#2314). The comment body does not
# name the commit it reviewed, and a committer date can be older than the
# push, so the time comes from the repository activity API: the newest
# entry whose `after` is the head SHA.
# Returns 0 = fresh, 1 = stale (or no comment), 2 = could not verify.
review_is_fresh() {
  local head_info head_sha head_ref cross pushed_at comment_times comment_at
  if ! head_info=$(gh pr view "$PR_NUM" --repo "$REPO" \
      --json headRefOid,headRefName,isCrossRepository \
      --jq '[.headRefOid, .headRefName, (.isCrossRepository | tostring)] | @tsv' 2>&1); then
    echo "verify-ci-review.sh: could not read the PR head commit: $head_info" >&2
    return 2
  fi
  IFS=$'\t' read -r head_sha head_ref cross <<< "$head_info"

  # The activity API covers only branches in this repository.
  if [ "$cross" = "true" ]; then
    echo "verify-ci-review.sh: warning: the PR head is in a fork. The check that the review is for the head commit does not apply." >&2
    return 0
  fi
  case "$head_sha" in
    ''|*[!0-9a-f]*)
      echo "verify-ci-review.sh: could not read the PR head commit (got '$head_sha')." >&2
      return 2
      ;;
  esac
  if [ "${#head_sha}" -ne 40 ]; then
    echo "verify-ci-review.sh: could not read the PR head commit (got '$head_sha')." >&2
    return 2
  fi
  # The ref goes into a URL query, so allow only plain branch-name characters.
  case "$head_ref" in
    ''|*[!A-Za-z0-9._/-]*)
      echo "verify-ci-review.sh: the PR head branch name '$head_ref' is empty or has characters this check does not accept." >&2
      return 2
      ;;
  esac

  if ! pushed_at=$(gh api "repos/${REPO}/activity?ref=refs/heads/${head_ref}&per_page=100" \
      --jq "[.[] | select(.after == \"${head_sha}\")] | first | .timestamp // \"\""); then
    echo "verify-ci-review.sh: could not read when the head commit reached ${head_ref}." >&2
    return 2
  fi
  if [ -z "$pushed_at" ]; then
    echo "verify-ci-review.sh: the activity of ${head_ref} has no entry for the head commit ${head_sha}." >&2
    return 2
  fi

  # --paginate: the newest review can be after the first page of comments.
  if ! comment_times=$(gh api "repos/${REPO}/issues/${PR_NUM}/comments" --paginate \
      --jq '.[] | select(.body | test("#{1,6}\\s+Strengths|\\*\\*Strengths\\*\\*")) | .created_at'); then
    echo "verify-ci-review.sh: could not read the review comments of PR #${PR_NUM}." >&2
    return 2
  fi
  comment_at="${comment_times##*$'\n'}"
  if [ -z "$comment_at" ]; then
    return 1
  fi

  # Compare as integers (YYYYMMDDHHMMSS). A string compare follows the
  # locale's collation.
  local pushed_num="${pushed_at//[!0-9]/}" comment_num="${comment_at//[!0-9]/}"
  if [ "${#pushed_num}" -ne 14 ] || [ "${#comment_num}" -ne 14 ]; then
    echo "verify-ci-review.sh: could not read the times (head reached the branch: '$pushed_at', review comment: '$comment_at')." >&2
    return 2
  fi
  if [ "$comment_num" -lt "$pushed_num" ]; then
    echo "The newest review comment (${comment_at}) is older than the head commit ${head_sha:0:8} on ${head_ref} (${pushed_at})." >&2
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
