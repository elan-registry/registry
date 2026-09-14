#!/usr/bin/env bash
# Polls check-review-posted.sh until a review comment appears or the
# timeout elapses. Wraps the same "the job's success status is not proof
# a comment was posted" check that /address-pr-comments and
# /review-milestone each poll for independently, at different intervals —
# this is the shared loop so a caller only has to pick its own timeout
# profile, not re-derive a bash polling loop.
#
# Usage: scripts/poll-review-posted.sh <pr-number> <interval-seconds> <timeout-seconds>
#
# Exit codes:
#   0 = a review comment appeared within the timeout
#   1 = timeout elapsed with no comment found — a normal "not posted yet"
#       outcome, distinct from exit 2 below
#   2 = could not verify — check-review-posted.sh itself failed (gh
#       auth/network/rate-limit), or the arguments were invalid. Never
#       treat this the same as exit 1: it means the poll never got a real
#       answer, not that it got a negative one.

set -uo pipefail

PR_NUM="${1:?Usage: poll-review-posted.sh <pr-number> <interval-seconds> <timeout-seconds>}"
INTERVAL="${2:?Usage: poll-review-posted.sh <pr-number> <interval-seconds> <timeout-seconds>}"
TIMEOUT="${3:?Usage: poll-review-posted.sh <pr-number> <interval-seconds> <timeout-seconds>}"

for var_name in INTERVAL TIMEOUT; do
  val="${!var_name}"
  case "$val" in
    ''|*[!0-9]*)
      echo "$var_name must be a positive integer (got '$val')." >&2
      exit 2
      ;;
  esac
  if [ "$val" -le 0 ]; then
    echo "$var_name must be greater than zero (got '$val')." >&2
    exit 2
  fi
done

if [ "$TIMEOUT" -lt "$INTERVAL" ]; then
  echo "TIMEOUT ($TIMEOUT) is less than INTERVAL ($INTERVAL) — arguments likely swapped." >&2
  exit 2
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

elapsed=0
while [ "$elapsed" -lt "$TIMEOUT" ]; do
  if ! COUNT=$("$SCRIPT_DIR/check-review-posted.sh" "$PR_NUM"); then
    echo "check-review-posted.sh could not query PR #${PR_NUM} — aborting poll (this is NOT 'no review posted')." >&2
    exit 2
  fi
  case "$COUNT" in
    ''|*[!0-9]*)
      echo "check-review-posted.sh returned non-numeric output: '${COUNT}' — aborting poll." >&2
      exit 2
      ;;
  esac
  if [ "$COUNT" -gt 0 ]; then
    echo "Review comment found after ${elapsed}s."
    exit 0
  fi
  sleep "$INTERVAL"
  elapsed=$((elapsed + INTERVAL))
done

echo "No review comment found after ${TIMEOUT}s." >&2
exit 1
