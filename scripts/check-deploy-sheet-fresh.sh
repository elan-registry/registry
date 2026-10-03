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
#   2 = can't verify — no stamp file, the stamp isn't a single valid commit
#       SHA (empty/corrupt/multi-line — e.g. from an interrupted write), or
#       the stamped commit/milestone branch can't be resolved in this repo.
#       Never treat exit 2 as either fresh or stale — an empty or corrupt
#       stamp must not be silently read as "STALE" (a corrupt stamp file
#       trivially fails a string-equality check against the real tip,
#       which would otherwise misreport a fine deploy sheet as stale).

set -euo pipefail

VERSION="${1:?Usage: check-deploy-sheet-fresh.sh <version>}"
STAMP_FILE="docs/plans/releases/${VERSION}-deploy.md.sha"

if [ ! -f "$STAMP_FILE" ]; then
  echo "No stamp file at $STAMP_FILE — cannot verify freshness (sheet may predate this check)." >&2
  exit 2
fi

STAMPED_SHA="$(tr -d '[:space:]' < "$STAMP_FILE")"

if ! printf '%s' "$STAMPED_SHA" | grep -qE '^[0-9a-f]{40}$'; then
  echo "Stamp file $STAMP_FILE does not contain a single valid 40-char SHA (got: '${STAMPED_SHA}')." >&2
  echo "Cannot verify freshness — re-run /finish-milestone Step 6.6 to re-render the sheet and its stamp." >&2
  exit 2
fi

if ! git cat-file -e "${STAMPED_SHA}^{commit}" 2>/dev/null; then
  echo "Stamped commit ${STAMPED_SHA} is not present in this repo (shallow clone? wrong checkout?) — cannot verify freshness." >&2
  exit 2
fi

if ! CURRENT_TIP="$(git rev-parse "milestone/${VERSION}" 2>&1)"; then
  echo "Could not resolve milestone/${VERSION}: ${CURRENT_TIP}" >&2
  echo "Branch missing, not fetched locally, or this isn't a git repo — cannot verify freshness." >&2
  exit 2
fi

if [ "$STAMPED_SHA" = "$CURRENT_TIP" ]; then
  echo "Deploy sheet is fresh (rendered against ${STAMPED_SHA}, still the branch tip)."
  exit 0
else
  echo "Deploy sheet is STALE — rendered against ${STAMPED_SHA}, but milestone/${VERSION} is now at ${CURRENT_TIP}." >&2
  if ! git log --oneline "${STAMPED_SHA}..${CURRENT_TIP}" >&2; then
    echo "(could not list the commits since — the stamped commit may not be an ancestor of the current tip)" >&2
  fi
  exit 1
fi
