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
#   Read-only gh calls still run.
#
# Resume: run the script again with the same arguments after it stops. Each
# step checks whether an earlier run already did its work, and skips it:
#   - Step 6 skips when the PR is merged. When the release-notes file is
#     already removed by a commit on the milestone branch, it pushes the
#     branch and continues.
#   - Step 8 skips when the PR is merged.
#   - Step 11 skips when the tag exists on the PR's merge commit.
#   - Step 13 skips when the GitHub release exists.
#   - Steps 7, 9, 10, 12 and 14 are safe to run again.
# Step 6 saves the release notes to docs/plans/releases/<version>-release-notes.md
# (gitignored) before it removes them. Step 13 publishes from that copy. If
# the copy is missing, Step 13 restores it from the commit before the
# removal commit.
#
# Exit codes:
#   0 = all steps completed (or --dry-run printed the plan)
#   1 = a check stopped the run before the failing step changed anything
#       (bad args, deploy remote, closed PR, release notes missing with no
#       removal commit, stray local commits on main). Fix the cause and run
#       the script again.
#   2 = a step itself failed (e.g. merge conflict, push rejected, a pull
#       that is not a fast-forward, tag on the wrong commit). Fix the cause and run the script again. It skips
#       the steps that already completed.

# -E: the ERR trap below must also fire for a failure inside run().
set -Eeuo pipefail

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
KEEP_NOTES="docs/plans/releases/${VERSION}-release-notes.md"

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

# Prints the commit in HEAD's history that removed the release-notes file,
# or nothing. --full-history: main never had the file, so the default
# history simplification drops the merged milestone side of the merge.
notes_removal_commit() {
  git log -1 --full-history --format=%H --diff-filter=D HEAD -- "$NOTES_FILE"
}

PR_STATE="$(gh pr view "$PR_NUMBER" --json state --jq '.state')"
case "$PR_STATE" in
  OPEN|MERGED) ;;
  *)
    echo "PR #${PR_NUMBER} is ${PR_STATE}, not OPEN or MERGED. Stop." >&2
    exit 1
    ;;
esac

trap 'echo "release-milestone: a command failed at line ${LINENO}. Fix the cause, then run the script again. It skips the steps that already completed." >&2; exit 2' ERR

# --- Step 6: save release notes, delete the file, push to the milestone branch ---
step "Step 6: remove release notes from milestone branch"
if [ "$PR_STATE" = "MERGED" ]; then
  echo "PR #${PR_NUMBER} is already merged — skipping."
else
  run git checkout "$BRANCH"
  run git pull --ff-only origin "$BRANCH"
  if [ "$DRY_RUN" -eq 1 ] || [ -f "$NOTES_FILE" ]; then
    run mkdir -p "$(dirname "$KEEP_NOTES")"
    run cp "$NOTES_FILE" "$KEEP_NOTES"
    run git rm "$NOTES_FILE"
    run git commit -m "chore: remove v${VERSION#v} release notes — published to GitHub Releases"
  else
    REMOVED_IN="$(notes_removal_commit)"
    if [ -z "$REMOVED_IN" ]; then
      echo "No ${NOTES_FILE} on ${BRANCH}, and no commit on the branch removed it. Stop." >&2
      exit 1
    fi
    echo "${NOTES_FILE} already removed in ${REMOVED_IN} — continuing."
  fi
  run git push origin "$BRANCH"

  if [ "$DRY_RUN" -eq 0 ]; then
    MERGEABLE="$(gh pr view "$PR_NUMBER" --json mergeable --jq '.mergeable')"
    if [ "$MERGEABLE" != "MERGEABLE" ]; then
      echo "PR #${PR_NUMBER} is not cleanly mergeable after Step 6's push (state: ${MERGEABLE}). Resolve it, then run the script again." >&2
      exit 2
    fi
  else
    echo "[dry-run] gh pr view $PR_NUMBER --json mergeable --jq '.mergeable'"
  fi
fi

# --- Step 7: switch to main, pull, verify clean local state ---
step "Step 7: sync main"
run git checkout main
run git fetch origin --prune

# Check before the pull: on a diverged main, the pull fails with a less
# clear message.
if [ "$DRY_RUN" -eq 0 ]; then
  AHEAD="$(git rev-list --count origin/main..main)"
  if [ "$AHEAD" != "0" ]; then
    echo "Local main has ${AHEAD} commit(s) not on origin/main. Stop — park them on a side branch first." >&2
    exit 1
  fi
else
  echo "[dry-run] git rev-list --count origin/main..main  # expect: 0"
fi
run git pull --ff-only origin main

# --- Step 8: merge the PR ---
step "Step 8: merge PR #${PR_NUMBER}"
if [ "$PR_STATE" = "MERGED" ]; then
  echo "PR #${PR_NUMBER} is already merged — skipping."
else
  run gh pr merge "$PR_NUMBER" --merge --delete-branch
fi

# --- Step 9: pull the merge commit ---
step "Step 9: pull merge commit"
run git pull --ff-only origin main

# --- Step 10: delete local milestone branch, if present ---
step "Step 10: delete local milestone branch"
if [ "$DRY_RUN" -eq 1 ]; then
  echo "[dry-run] git branch -d $BRANCH (ignored if absent)"
else
  git branch -d "$BRANCH" 2>/dev/null || true
fi

# --- Step 11: tag the merge commit ---
# Tag the PR's merge commit by its SHA, not HEAD: a commit pushed to main
# after the merge would make HEAD the wrong commit.
step "Step 11: create annotated tag"
if [ "$DRY_RUN" -eq 1 ]; then
  echo "[dry-run] gh pr view $PR_NUMBER --json mergeCommit --jq .mergeCommit.oid  # MERGE_SHA"
  echo "[dry-run] git tag -a ${VERSION} <MERGE_SHA> -m \"Release ${VERSION}: <milestone title>\""
  echo "[dry-run] git rev-parse ${VERSION}^{commit}  # expect: <MERGE_SHA>"
else
  MERGE_SHA="$(gh pr view "$PR_NUMBER" --json mergeCommit --jq '.mergeCommit.oid')"
  if ! printf '%s' "$MERGE_SHA" | grep -qE '^[0-9a-f]{40}$'; then
    echo "Could not read the merge commit of PR #${PR_NUMBER} (got: '${MERGE_SHA}'). Stop and investigate." >&2
    exit 2
  fi
  if git rev-parse -q --verify "refs/tags/${VERSION}" >/dev/null; then
    TAGGED_SHA="$(git rev-parse "${VERSION}^{commit}")"
    if [ "$TAGGED_SHA" != "$MERGE_SHA" ]; then
      echo "Tag ${VERSION} already exists on ${TAGGED_SHA}, not on the merge commit ${MERGE_SHA}. Stop and investigate." >&2
      exit 2
    fi
    echo "Tag ${VERSION} already exists on the merge commit — skipping."
  else
    MILESTONE_TITLE="$(gh api "repos/${REPO}/milestones/${MILESTONE_NUMBER}" --jq .title)"
    git tag -a "${VERSION}" "${MERGE_SHA}" -m "Release ${VERSION}: ${MILESTONE_TITLE}"
    TAGGED_SHA="$(git rev-parse "${VERSION}^{commit}")"
    if [ "$TAGGED_SHA" != "$MERGE_SHA" ]; then
      echo "Tag ${VERSION} points at ${TAGGED_SHA}, expected the merge commit ${MERGE_SHA}. Stop and investigate." >&2
      exit 2
    fi
  fi
fi

# --- Step 12: push the tag ---
step "Step 12: push tag to origin"
run git push origin "${VERSION}"

# --- Step 13: draft GitHub release ---
step "Step 13: create draft GitHub release"
if [ "$DRY_RUN" -eq 0 ] && gh release view "${VERSION}" --repo "$REPO" >/dev/null 2>&1; then
  echo "Release ${VERSION} already exists — skipping."
else
  if [ "$DRY_RUN" -eq 0 ] && [ ! -f "$KEEP_NOTES" ]; then
    REMOVED_IN="$(notes_removal_commit)"
    if [ -z "$REMOVED_IN" ]; then
      echo "No saved release notes at ${KEEP_NOTES}, and no commit on main removed ${NOTES_FILE}. Stop." >&2
      exit 2
    fi
    mkdir -p "$(dirname "$KEEP_NOTES")"
    # Write to a temp file first, so a failed git show leaves no empty copy
    # for the next run to publish.
    TMP_NOTES="${KEEP_NOTES}.tmp.$$"
    if ! git show "${REMOVED_IN}^:${NOTES_FILE}" > "$TMP_NOTES"; then
      rm -f "$TMP_NOTES"
      echo "Could not restore ${NOTES_FILE} from ${REMOVED_IN}^. Stop." >&2
      exit 2
    fi
    mv "$TMP_NOTES" "$KEEP_NOTES"
    echo "Restored release notes from ${REMOVED_IN}^ to ${KEEP_NOTES}."
  fi
  run gh release create "${VERSION}" \
    --repo "$REPO" \
    --title "Elan Registry ${VERSION}" \
    --notes-file "$KEEP_NOTES" \
    --verify-tag \
    --draft
fi
run rm -f "$KEEP_NOTES"

# --- Step 14: close the GitHub milestone ---
step "Step 14: close milestone #${MILESTONE_NUMBER}"
run gh api "repos/${REPO}/milestones/${MILESTONE_NUMBER}" -X PATCH -f state=closed

echo "Done. Tag ${VERSION} pushed, PR #${PR_NUMBER} merged, milestone #${MILESTONE_NUMBER} closed."
echo "Release is a DRAFT — publish it at prod deploy time (see the deploy sheet's own last step)."
