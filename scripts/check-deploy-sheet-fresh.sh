#!/usr/bin/env bash
# Checks whether a rendered deploy sheet is stale: whether a deploy input
# changed on the milestone branch after the sheet was rendered.
#
# /finish-milestone Step 6.6 writes a sidecar stamp file next to the
# rendered deploy sheet. The stamp records the commit the sheet was
# rendered against. /finish-milestone, /review-milestone and
# /release-milestone call this script to decide whether to warn the user.
#
# A commit after the stamp makes the sheet stale only when it changes a
# deploy input. The deploy inputs are the paths that
# scripts/render-deploy-sheet.sh reads, plus the template the sheet is
# rendered from:
#   - database/migrations/ (any change)
#   - scripts/server-hooks/post-receive
#   - .env.example
#   - app/admin/scripts/fix/ and app/admin/scripts/maintenance/
#   - docs/development/RELEASE_INSTRUCTIONS_TEMPLATE.md
#   - an added or deleted .php file outside tests/, database/, scripts/,
#     vendor/ and users/ that calls securePage( (a new page needs
#     permission registration)
# Release notes, CLAUDE.md, review fixes and other code commits do not
# change the sheet, so they do not make it stale. The one input this
# script cannot see is a manual procedure in a merged PR's body (the
# release-actions condition). A new merged PR also changes files, so it
# shows here only if it touches a path above.
#
# The comparison is a tree diff from the stamped commit to the tip. A merge
# of main into the milestone branch after the stamp can bring in a main
# migration and report stale. That is a false alarm on the safe side: the
# sheet is rendered again.
#
# Usage: scripts/check-deploy-sheet-fresh.sh <version>
#
# Reads docs/plans/releases/<version>-deploy.md.sha (the stamp) and compares
# it with milestone/<version>'s current tip.
#
# Exit codes:
#   0 = fresh (no deploy input changed since the stamped commit)
#   1 = stale (a deploy input changed; stderr lists the changed paths)
#   2 = can't verify — no stamp file, the stamp is not a single valid
#       commit SHA (empty, corrupt, or more than one line), the stamped
#       commit or the milestone branch does not resolve in this repo, or a
#       git command failed. Never treat exit 2 as either fresh or stale.

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
  echo "Cannot verify freshness. Re-render the sheet and its stamp by hand from /finish-milestone Step 6.6, or type /finish-milestone ${VERSION} to run the whole command again." >&2
  exit 2
fi

if ! git cat-file -e "${STAMPED_SHA}^{commit}" 2>/dev/null; then
  echo "Stamped commit ${STAMPED_SHA} is not present in this repo (shallow clone? wrong checkout?) — cannot verify freshness." >&2
  exit 2
fi

if ! CURRENT_TIP="$(git rev-parse --verify --quiet "milestone/${VERSION}^{commit}")"; then
  echo "Could not resolve milestone/${VERSION}." >&2
  echo "Branch missing, not fetched locally, or this isn't a git repo — cannot verify freshness." >&2
  exit 2
fi

if [ "$STAMPED_SHA" = "$CURRENT_TIP" ]; then
  echo "Deploy sheet is fresh (rendered against ${STAMPED_SHA}, still the branch tip)."
  exit 0
fi

# --no-renames: a rename shows as a delete plus an add, so both paths are
# checked. core.quotePath=false keeps non-ASCII paths unquoted.
if ! NAME_STATUS="$(git -c core.quotePath=false diff --no-renames --name-status "$STAMPED_SHA" "$CURRENT_TIP" 2>&1)"; then
  echo "Could not diff ${STAMPED_SHA}..${CURRENT_TIP}: ${NAME_STATUS}" >&2
  exit 2
fi

# Sets CONTENT to the file at the given commit. A failed read exits 2:
# reading it as "no securePage(" would hide a stale sheet.
read_file_at() {
  if ! CONTENT="$(git show "${1}:${2}" 2>&1)"; then
    echo "Could not read ${1}:${2}: ${CONTENT}" >&2
    exit 2
  fi
}

CHANGED_INPUTS=""
while IFS=$'\t' read -r status path; do
  [ -n "$path" ] || continue
  case "$path" in
    database/migrations/*|scripts/server-hooks/post-receive|.env.example|app/admin/scripts/fix/*|app/admin/scripts/maintenance/*|docs/development/RELEASE_INSTRUCTIONS_TEMPLATE.md)
      CHANGED_INPUTS+="${status} ${path}"$'\n'
      continue
      ;;
  esac
  [[ "$path" =~ \.php$ ]] || continue
  [[ "$path" =~ ^(tests|database|scripts|vendor|users)/ ]] && continue
  case "$status" in
    A) read_file_at "$CURRENT_TIP" "$path" ;;
    D) read_file_at "$STAMPED_SHA" "$path" ;;
    *) continue ;;
  esac
  if grep -q 'securePage(' <<<"$CONTENT"; then
    CHANGED_INPUTS+="${status} ${path}"$'\n'
  fi
done <<<"$NAME_STATUS"

if [ -z "$CHANGED_INPUTS" ]; then
  echo "Deploy sheet is fresh (rendered against ${STAMPED_SHA}; milestone/${VERSION} moved to ${CURRENT_TIP}, but no deploy input changed)."
  exit 0
fi

echo "Deploy sheet is STALE — rendered against ${STAMPED_SHA}, and these deploy inputs changed on milestone/${VERSION} (now ${CURRENT_TIP}):" >&2
printf '%s' "$CHANGED_INPUTS" | sed 's/^/  /' >&2
exit 1
