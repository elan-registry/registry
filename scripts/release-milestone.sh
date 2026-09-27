#!/usr/bin/env bash
# Runs the deterministic merge/tag/publish sequence for /release-milestone
# Steps 6-14 — release-notes cleanup, main sync, PR merge, tag, draft
# release, milestone close. Everything here is mechanical (fixed command
# order, no judgment calls); /release-milestone keeps the human confirmation
# gate (its own Step 5) before calling this script.
#
# Usage: scripts/release-milestone.sh [--dry-run] <version> <pr-number> <milestone-number>
#
# <version>: e.g. v2.17.0
# <pr-number>: the milestone PR number (numeric, no leading '#')
# <milestone-number>: the GitHub milestone number to close
#
# --dry-run: print every command this script would run, in order, and exit
#   0 without running any of them (no git, gh, or filesystem mutation).
#
# Exit codes:
#   0 = all steps completed (or --dry-run printed the plan)
#   1 = a precondition failed (bad args, wrong remote, dirty tree, stray
#       local commits) — nothing was mutated, or only steps before the
#       failure point ran
#   2 = a step itself failed after mutation began (e.g. merge conflict,
#       push rejected) — stop and investigate by hand; do not re-run blindly

set -euo pipefail

DRY_RUN=0
if [ "${1:-}" = "--dry-run" ]; then
  DRY_RUN=1
  shift
fi

VERSION="${1:?Usage: release-milestone.sh [--dry-run] <version> <pr-number> <milestone-number>}"
PR_NUMBER="${2:?Usage: release-milestone.sh [--dry-run] <version> <pr-number> <milestone-number>}"
MILESTONE_NUMBER="${3:?Usage: release-milestone.sh [--dry-run] <version> <pr-number> <milestone-number>}"

REPO="elan-registry/registry"
BRANCH="milestone/${VERSION}"
NOTES_FILE="docs/releases/RELEASE_NOTES_${VERSION}.md"
TMP_NOTES="/tmp/release-notes-${VERSION}.md"

run() {
  if [ "$DRY_RUN" -eq 1 ]; then
    printf '[dry-run] %s\n' "$*"
  else
    "$@"
  fi
}

# Refuse to ever push to a deploy remote — this script only ever pushes to
# `origin`. This guard exists so a typo'd remote argument can't silently
# deploy; it never receives one today, but the check costs nothing.
for remote in "$@"; do
  case "$remote" in
    prod|test)
      echo "Refusing: '$remote' is a deploy remote. This script only pushes to origin." >&2
      exit 1
      ;;
  esac
done

step() { echo "== $* =="; }

# --- Step 6: stage release notes, delete the file, push to the milestone branch ---
step "Step 6: remove release notes from milestone branch"
run git checkout "$BRANCH"
run git pull origin "$BRANCH"
run cp "$NOTES_FILE" "$TMP_NOTES"
run git rm "$NOTES_FILE"
run git commit -m "chore: remove v${VERSION#v} release notes — published to GitHub Releases"
run git push origin "$BRANCH"

if [ "$DRY_RUN" -eq 0 ]; then
  MERGEABLE="$(gh pr view "$PR_NUMBER" --json mergeable --jq '.mergeable')"
  if [ "$MERGEABLE" != "MERGEABLE" ]; then
    echo "PR #${PR_NUMBER} is not cleanly mergeable after Step 6's push (state: ${MERGEABLE}). Stop and resolve." >&2
    exit 2
  fi
else
  echo "[dry-run] gh pr view $PR_NUMBER --json mergeable --jq '.mergeable'"
fi

# --- Step 7: switch to main, pull, verify clean local state ---
step "Step 7: sync main"
run git checkout main
run git fetch origin --prune
run git pull origin main

if [ "$DRY_RUN" -eq 0 ]; then
  COUNTS="$(git rev-list --left-right --count origin/main...main)"
  RIGHT="$(echo "$COUNTS" | awk '{print $2}')"
  if [ "$RIGHT" != "0" ]; then
    echo "Local main has ${RIGHT} commit(s) not on origin/main. Stop — park them on a side branch first." >&2
    exit 1
  fi
else
  echo "[dry-run] git rev-list --left-right --count origin/main...main"
fi

# --- Step 8: merge the PR ---
step "Step 8: merge PR #${PR_NUMBER}"
run gh pr merge "$PR_NUMBER" --merge --delete-branch

# --- Step 9: pull the merge commit ---
step "Step 9: pull merge commit"
run git pull origin main

# --- Step 10: delete local milestone branch, if present ---
step "Step 10: delete local milestone branch"
if [ "$DRY_RUN" -eq 1 ]; then
  echo "[dry-run] git branch -d $BRANCH (ignored if absent)"
else
  git branch -d "$BRANCH" 2>/dev/null || true
fi

# --- Step 11: tag the merge commit ---
step "Step 11: create annotated tag"
MILESTONE_TITLE="$(gh api "repos/${REPO}/milestones/${MILESTONE_NUMBER}" --jq .title)"
run git tag -a "${VERSION}" -m "Release ${VERSION}: ${MILESTONE_TITLE}"

if [ "$DRY_RUN" -eq 0 ]; then
  DESCRIBED="$(git describe HEAD)"
  if [ "$DESCRIBED" != "$VERSION" ]; then
    echo "git describe HEAD returned '${DESCRIBED}', expected '${VERSION}' with no suffix." >&2
    echo "The tag is not on a clean merge commit — stop and investigate." >&2
    exit 2
  fi
else
  echo "[dry-run] git describe HEAD  # expect: $VERSION"
fi

# --- Step 12: push the tag ---
step "Step 12: push tag to origin"
run git push origin "${VERSION}"

# --- Step 13: draft GitHub release ---
step "Step 13: create draft GitHub release"
run gh release create "${VERSION}" \
  --repo "$REPO" \
  --title "Elan Registry ${VERSION}" \
  --notes-file "$TMP_NOTES" \
  --verify-tag \
  --draft
run rm -f "$TMP_NOTES"

# --- Step 14: close the GitHub milestone ---
step "Step 14: close milestone #${MILESTONE_NUMBER}"
run gh api "repos/${REPO}/milestones/${MILESTONE_NUMBER}" -X PATCH -f state=closed

echo "Done. Tag ${VERSION} pushed, PR #${PR_NUMBER} merged, milestone #${MILESTONE_NUMBER} closed."
echo "Release is a DRAFT — publish it at prod deploy time (see the deploy sheet's own last step)."
