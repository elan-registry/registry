#!/bin/bash
#
# Tests for scripts/check-deploy-sheet-for-branch.sh: it reads the version
# from the milestone/<version> branch name, needs the rendered sheet, and
# passes the result of check-deploy-sheet-fresh.sh through.
#
# HERMETIC: builds a throwaway git repo under a temp dir, with copies of
# both scripts. Never touches this repo's own branches, history, or
# docs/plans/.
#
# Usage: bash tests/hooks/test-check-deploy-sheet-for-branch.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
for f in check-deploy-sheet-for-branch.sh check-deploy-sheet-fresh.sh; do
    if [ ! -x "$REAL_REPO/scripts/$f" ]; then
        echo "FAIL: $REAL_REPO/scripts/$f not found or not executable" >&2
        exit 1
    fi
done

TMPROOT="$(mktemp -d)" || exit 1

VERSION="v9.9.9"
REPO="$TMPROOT/repo"
SHEET="docs/plans/releases/${VERSION}-deploy.md"
mkdir -p "$REPO/scripts" "$REPO/docs/plans/releases"
cd "$REPO" || exit 1
# The wrapper finds the freshness script next to itself, so copy both.
cp "$REAL_REPO/scripts/check-deploy-sheet-for-branch.sh" "$REAL_REPO/scripts/check-deploy-sheet-fresh.sh" scripts/
SCRIPT="$REPO/scripts/check-deploy-sheet-for-branch.sh"
git init -q
git config user.name "test-check-deploy-sheet-for-branch"
git config user.email "test-check-deploy-sheet-for-branch@localhost"
echo "base" > README.md
git add README.md scripts
git commit -q -m "base"

# expect_status <want> <description> [<grep pattern for output>]
expect_status() {
    local want="$1" desc="$2" pattern="${3:-}" out status
    out="$("$SCRIPT" 2>&1)"
    status=$?
    if [ "$status" -eq "$want" ] && { [ -z "$pattern" ] || printf '%s' "$out" | grep -q -- "$pattern"; }; then
        pass "$desc"
    else
        fail "$desc" "exit: $status (want $want)" "output: [$out]"
    fi
}

# --- Case 1: not on a milestone branch -> 2 ------------------------------
expect_status 2 "Case 1: branch is not milestone/* -> exit 2" "is not milestone/v<version>"

# --- Case 2: milestone branch without a v version -> 2 -------------------
git checkout -q -b milestone/next
expect_status 2 "Case 2: milestone/next (no v) -> exit 2" "is not milestone/v<version>"

# --- Case 3: no sheet -> 2 -----------------------------------------------
git checkout -q -b "milestone/${VERSION}"
expect_status 2 "Case 3: no deploy sheet -> exit 2" "No deploy sheet at ${SHEET}"

# --- Case 4: sheet, no stamp -> 2, with the render pointer ---------------
echo "sheet" > "$SHEET"
expect_status 2 "Case 4: sheet without stamp -> exit 2" "Render the sheet and its stamp again"

# --- Case 5: sheet and stamp at the tip -> 0 -----------------------------
git rev-parse "milestone/${VERSION}" > "${SHEET}.sha"
expect_status 0 "Case 5: stamp equals branch tip -> exit 0" "still the branch tip"

# --- Case 6: deploy input changed after the stamp -> 1 -------------------
mkdir -p database/migrations
echo "<?php" > database/migrations/20990101000000_x.php
git add database
git commit -q -m "migration"
expect_status 1 "Case 6: new migration after stamp -> exit 1" "database/migrations/20990101000000_x.php"
expect_status 1 "Case 6b: stale sheet -> render pointer" "Render the sheet and its stamp again"

# --- Case 7: run from a subdirectory -> paths still resolve --------------
git rev-parse "milestone/${VERSION}" > "${SHEET}.sha"
(cd database/migrations && expect_status 0 "Case 7: run from a subdirectory -> exit 0" "still the branch tip")

# --- Case 8: detached HEAD -> 2 ------------------------------------------
git checkout -q --detach
expect_status 2 "Case 8: detached HEAD -> exit 2" "is not milestone/v<version>"

harness_report
