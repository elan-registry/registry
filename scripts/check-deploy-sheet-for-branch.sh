#!/usr/bin/env bash
# Runs check-deploy-sheet-fresh.sh for the milestone that is checked out.
#
# .claude/project.md points milestone_checks (/flow:finish-milestone
# Step 3.5) and release_checks (/flow:release-milestone Step 4) at this
# script. The devkit runs those keys as plain commands and passes no
# version, but both commands run on milestone/<version>, so the branch name
# gives the version.
#
# This script does not render the deploy sheet. Rendering needs a person or
# a model to read the merged PR bodies (the release-actions condition). See
# docs/development/RELEASE_INSTRUCTIONS_TEMPLATE.md, "Render the sheet".
#
# Usage: scripts/check-deploy-sheet-for-branch.sh
#
# Exit codes:
#   0 = fresh
#   1 = stale (stderr lists the changed paths)
#   2 = can't verify: not on a milestone/v* branch, no sheet or stamp, or
#       any other exit 2 from check-deploy-sheet-fresh.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# The sheet and stamp paths are relative to the repo root.
TOPLEVEL="$(git rev-parse --show-toplevel)" || {
  echo "Not inside a git work tree — cannot verify the deploy sheet." >&2
  exit 2
}
cd "$TOPLEVEL"

BRANCH="$(git branch --show-current)" || {
  echo "Could not read the current branch — cannot verify the deploy sheet." >&2
  exit 2
}
VERSION="${BRANCH#milestone/}"

if [ "$VERSION" = "$BRANCH" ] || [ "${VERSION#v}" = "$VERSION" ]; then
  echo "Current branch '${BRANCH}' is not milestone/v<version> — cannot find the version for the deploy sheet." >&2
  exit 2
fi

SHEET="docs/plans/releases/${VERSION}-deploy.md"
if [ ! -f "$SHEET" ]; then
  echo "No deploy sheet at ${SHEET}." >&2
  echo "Render it and its stamp from docs/development/RELEASE_INSTRUCTIONS_TEMPLATE.md, \"Render the sheet\". Then run the command again." >&2
  exit 2
fi

status=0
"$SCRIPT_DIR/check-deploy-sheet-fresh.sh" "$VERSION" || status=$?
if [ "$status" -ne 0 ]; then
  echo "Render the sheet and its stamp again from docs/development/RELEASE_INSTRUCTIONS_TEMPLATE.md, \"Render the sheet\"." >&2
fi
exit "$status"
