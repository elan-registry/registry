#!/usr/bin/env bash
# Gathers the MECHANICAL inputs for the deploy sheet
# (docs/development/RELEASE_INSTRUCTIONS_TEMPLATE.md's conditional blocks)
# by diffing a milestone branch against the latest known main (origin/main
# whenever that ref exists — the script fetches it first, but if the fetch
# fails the ref may be stale and a warning is printed; local main otherwise).
# It does not render the template itself — deciding which PRs document a
# manual verification procedure (the "release-actions" condition) needs
# reading PR bodies and judgment, which stays a model step in
# /finish-milestone Step 6.6.
#
# Usage: scripts/render-deploy-sheet.sh <version>
#   <version> — the milestone name, e.g. v2.30.0 (branch milestone/<version>
#   must exist and be checked out or fetched locally)
#
# Output (stdout): one line per condition that is TRUE, or CHECK for a
# condition that needs a manual look (migration-modified), in the form
# "CONDITION: TRUE|CHECK", usually followed by indented detail lines. A
# condition with nothing to report is omitted, not printed false. The
# release-actions reminder always goes to stderr.
#
# Exit codes:
#   0 = ran to completion (output may legitimately be empty — that means no
#       mechanical conditions triggered, not that the script failed)
#   2 = could not diff (milestone branch not found, no usable base ref, not
#       a git repo, etc.)
set -uo pipefail

VERSION="${1:?Usage: render-deploy-sheet.sh <version>}"
BRANCH="milestone/${VERSION}"

if ! git rev-parse --verify "$BRANCH" >/dev/null 2>&1; then
  echo "Branch $BRANCH not found locally — fetch it first." >&2
  exit 2
fi

# Prefer origin/main so a stale local main does not hide released commits
# from the diff. Fall back to local main only when origin is unavailable.
BASE=""
if git remote get-url origin >/dev/null 2>&1; then
  # A hanging auth prompt would block the script indefinitely; disabling it
  # makes an unreachable/unauthenticated remote fail fast into the fallback.
  if ! GIT_TERMINAL_PROMPT=0 git fetch -q origin main >&2; then
    echo "Warning: 'git fetch origin main' failed; origin/main may be stale." >&2
  fi
  if git rev-parse --verify origin/main >/dev/null 2>&1; then
    BASE="origin/main"
  fi
fi

if [ -z "$BASE" ]; then
  if git rev-parse --verify main >/dev/null 2>&1; then
    BASE="main"
    echo "Warning: origin/main not available; using local main as the base." >&2
  else
    echo "Neither origin/main nor local main resolves — cannot diff." >&2
    exit 2
  fi
fi
echo "base: ${BASE} ($(git rev-parse --short "$BASE"))" >&2

# One --name-status walk gives every list below. --no-renames: without it,
# a renamed/copied-then-deleted migration or moved page has status R and
# drops out of both the added and modified lists. With it, a rename shows
# as a delete plus an add, so the new path is flagged as added.
# -c core.quotePath=false: without it, git quotes a non-ASCII path, and a
# quoted path would not match the plain-text patterns below.
if ! NAME_STATUS="$(git -c core.quotePath=false diff --no-renames --name-status "${BASE}...${BRANCH}" 2>&1)"; then
  echo "Could not diff ${BASE}...${BRANCH}: $NAME_STATUS" >&2
  exit 2
fi
DIFF_FILES="$(cut -f2 <<<"$NAME_STATUS")"
ADDED_FILES="$(awk -F'\t' '$1 == "A" { print $2 }' <<<"$NAME_STATUS")"
MODIFIED_FILES="$(awk -F'\t' '$1 == "M" { print $2 }' <<<"$NAME_STATUS")"

# Sets CONTENT to the file's content on the milestone branch. It reads the
# branch, not the working tree, which may have a different branch checked
# out. A failed read exits: reading it as "no match" would drop a condition.
read_branch_file() {
  if ! CONTENT="$(git show "${BRANCH}:${1}" 2>&1)"; then
    echo "Could not read ${BRANCH}:${1}: $CONTENT" >&2
    exit 2
  fi
}

# Checks on these lists and on file content use a here-string, not a pipe:
# a piped `grep -q` that stops at an early match SIGPIPEs the writer, and
# pipefail reads that 141 as "no match" (#2225).
NEW_MIGRATIONS="$(grep '^database/migrations/' <<<"$ADDED_FILES")"
if [ -n "$NEW_MIGRATIONS" ]; then
  echo "migration: TRUE"
  printf '%s\n' "$NEW_MIGRATIONS" | sed 's/^/  - /'

  TRIGGER_MIGRATION=false
  while IFS= read -r f; do
    read_branch_file "$f"
    if grep -q 'CREATE TRIGGER' <<<"$CONTENT"; then
      TRIGGER_MIGRATION=true
    fi
  done <<<"$NEW_MIGRATIONS"
  if [ "$TRIGGER_MIGRATION" = true ]; then
    echo "trigger-migration: TRUE"
  fi
fi

# A modified migration does not re-run on deploy (deploy applies only new,
# unapplied files), so it is flagged for manual review rather than treated
# as a deploy action like an added migration.
MODIFIED_MIGRATIONS="$(grep '^database/migrations/' <<<"$MODIFIED_FILES")"
if [ -n "$MODIFIED_MIGRATIONS" ]; then
  echo "migration-modified: CHECK"
  printf '%s\n' "$MODIFIED_MIGRATIONS" | sed 's/^/  - /'
fi

if grep -qx 'scripts/server-hooks/post-receive' <<<"$DIFF_FILES"; then
  echo "hook-changed: TRUE (scripts/server-hooks/post-receive)"
fi

# new-pages counts any added .php file whose branch content calls
# securePage(, except under tests/, database/, scripts/, vendor/, users/.
# docs/ pages and root .php pages also call securePage(, so an include list
# of directories missed them; an exclude list flags a new page directory
# even when no script change names it.
NEW_SECURE_PAGES=""
while IFS= read -r f; do
  [[ "$f" =~ \.php$ ]] || continue
  [[ "$f" =~ ^(tests|database|scripts|vendor|users)/ ]] && continue
  read_branch_file "$f"
  if grep -q 'securePage(' <<<"$CONTENT"; then
    NEW_SECURE_PAGES+="${f}"$'\n'
  fi
done <<<"$ADDED_FILES"
if [ -n "$NEW_SECURE_PAGES" ]; then
  echo "new-pages: TRUE"
  printf '%s' "$NEW_SECURE_PAGES" | sed 's/^/  - /'
fi

if grep -qE '^app/admin/scripts/(fix|maintenance)/' <<<"$DIFF_FILES"; then
  echo "admin-scripts: TRUE"
  printf '%s\n' "$DIFF_FILES" | grep -E '^app/admin/scripts/(fix|maintenance)/' | sed 's/^/  - /'
fi

if grep -qx '.env.example' <<<"$DIFF_FILES"; then
  echo "env-vars: TRUE"
  git diff "${BASE}...${BRANCH}" -- .env.example | grep '^+' | grep -v '^+++' | sed 's/^/  /'
fi

echo
echo "release-actions: CHECK MANUALLY — read each merged issue PR's body for a" >&2
echo "documented manual verification/deployment procedure; this cannot be" >&2
echo "detected from the diff alone." >&2
