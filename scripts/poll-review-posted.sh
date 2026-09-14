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
#   1 = timeout elapsed with no comment found

set -euo pipefail

PR_NUM="${1:?Usage: poll-review-posted.sh <pr-number> <interval-seconds> <timeout-seconds>}"
INTERVAL="${2:?Usage: poll-review-posted.sh <pr-number> <interval-seconds> <timeout-seconds>}"
TIMEOUT="${3:?Usage: poll-review-posted.sh <pr-number> <interval-seconds> <timeout-seconds>}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

elapsed=0
while [ "$elapsed" -lt "$TIMEOUT" ]; do
  COUNT=$("$SCRIPT_DIR/check-review-posted.sh" "$PR_NUM")
  if [ "$COUNT" -gt 0 ]; then
    echo "Review comment found after ${elapsed}s."
    exit 0
  fi
  sleep "$INTERVAL"
  elapsed=$((elapsed + INTERVAL))
done

echo "No review comment found after ${TIMEOUT}s." >&2
exit 1
