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
#   --trigger=label      recovery re-applies the `deep-review` label
#   --trigger=none       no recovery trigger is attempted; a missing comment
#                        after the poll window is reported as-is (exit 4)
#   --include-important  after a comment is confirmed, also fail (exit 2) on
#                        an unresolved `Important` heading, not only `Blocking`
#   --check-skip-tag     before recovering, check the PR title for
#                        `[skip-review]` — milestone-review honors this tag
#                        and deliberately posts nothing; treat that as
#                        exit 3, not a failure
#
# Exit codes:
#   0 = comment confirmed, and no unresolved Blocking finding (nor, with
#       --include-important, an unresolved Important finding)
#   1 = could not verify at all — a `gh` call failed (auth, network, rate
#       limit) or arguments were invalid. NOT the same as "no review posted."
#   2 = comment confirmed, but an unresolved Blocking (or, with
#       --include-important, Important) finding remains. See stdout for the
#       matched heading(s).
#   3 = no comment, but the PR title carries `[skip-review]` — the correct,
#       by-design outcome (--check-skip-tag only)
#   4 = no comment after poll + one recovery attempt (or after poll alone,
#       with --trigger=none) — genuine "did not post" outcome
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

poll() {
  "$SCRIPT_DIR/poll-review-posted.sh" "$PR_NUM" "$INTERVAL" "$TIMEOUT"
}

# Initial poll.
if poll; then
  : # comment found
else
  poll_status=$?
  if [ "$poll_status" -eq 2 ]; then
    echo "verify-ci-review.sh: could not verify (see poll-review-posted.sh output above)." >&2
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
