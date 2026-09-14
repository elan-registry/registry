#!/usr/bin/env bash
# Checks whether a rendered deploy sheet is stale relative to its milestone
# branch's current tip — a mechanical ancestor check, not something that
# needs "if timing is in doubt" prose-guesswork from an LLM.
#
# /finish-milestone Step 6.6 writes a sidecar stamp file next to the
# rendered deploy sheet recording the commit it was rendered against.
# /release-milestone (and /review-milestone) call this script instead of
# eyeballing git log output to decide whether to warn the user.
#
# Usage: scripts/check-deploy-sheet-fresh.sh <version>
#
# Reads docs/plans/releases/<version>-deploy.md.sha (the stamp) and compares
# it against milestone/<version>'s current tip.
#
# Exit codes:
#   0 = fresh (stamped commit is milestone/<version>'s current tip)
#   1 = stale (milestone branch has moved since the sheet was rendered)
#   2 = no stamp file found (sheet predates this check, or was never
#       stamped) — caller should treat this as "can't verify freshness,"
#       not as either fresh or stale

set -euo pipefail

VERSION="${1:?Usage: check-deploy-sheet-fresh.sh <version>}"
STAMP_FILE="docs/plans/releases/${VERSION}-deploy.md.sha"

if [ ! -f "$STAMP_FILE" ]; then
  echo "No stamp file at $STAMP_FILE — cannot verify freshness (sheet may predate this check)." >&2
  exit 2
fi

STAMPED_SHA="$(cat "$STAMP_FILE")"
CURRENT_TIP="$(git rev-parse "milestone/${VERSION}" 2>/dev/null || true)"

if [ -z "$CURRENT_TIP" ]; then
  echo "Could not resolve milestone/${VERSION} — branch missing or not fetched locally." >&2
  exit 2
fi

if [ "$STAMPED_SHA" = "$CURRENT_TIP" ]; then
  echo "Deploy sheet is fresh (rendered against ${STAMPED_SHA}, still the branch tip)."
  exit 0
else
  echo "Deploy sheet is STALE — rendered against ${STAMPED_SHA}, but milestone/${VERSION} is now at ${CURRENT_TIP}." >&2
  git log --oneline "${STAMPED_SHA}..${CURRENT_TIP}" 2>/dev/null >&2 || true
  exit 1
fi
