#!/usr/bin/env bash
# Finds remaining `WIP:` prefixes in a milestone's release notes.
#
# start-milestone.md Step 6 prefixes every issue's "Issues Resolved" entry
# with `WIP:` at milestone creation, since none are resolved yet.
# /execute-plan fills in each issue's real changes as it implements, and
# /finish-issue strips that issue's own `WIP:` prefix once its PR merges
# and the issue closes. A `WIP:` still present by the time
# finish-milestone.md Step 6 or review-milestone.md Step 1 runs means
# either that issue's PR never actually merged, or its /finish-issue run
# skipped the strip step — both callers need the identical match list to
# ask the user the same question, so this script is the one place that
# derives it.
#
# Usage: scripts/check-wip-markers.sh <version>
#   <version> — the milestone name, e.g. v2.30.0. Reads
#   docs/releases/RELEASE_NOTES_<version>.md.
#
# Output (stdout): one line per remaining `WIP:` marker, in `grep -n`
# format (<line number>:<line text>).
#
# Exit codes:
#   0 = ran to completion, zero WIP markers found (see stdout: empty)
#   1 = one or more WIP markers found (see stdout for the list) — this is
#       NOT a hard failure, it's the signal callers act on
#   2 = usage error, or the release notes file does not exist at the
#       expected path
set -uo pipefail

VERSION="${1:?Usage: check-wip-markers.sh <version>}"
NOTES_FILE="docs/releases/RELEASE_NOTES_${VERSION}.md"

if [ ! -f "$NOTES_FILE" ]; then
  echo "Release notes file not found: ${NOTES_FILE}" >&2
  exit 2
fi

MATCHES="$(grep -n "WIP:" "$NOTES_FILE" || true)"

if [ -z "$MATCHES" ]; then
  exit 0
fi

printf '%s\n' "$MATCHES"
exit 1
