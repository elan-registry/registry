#!/usr/bin/env bash
# Gathers the MECHANICAL inputs for the deploy sheet
# (docs/development/RELEASE_INSTRUCTIONS_TEMPLATE.md's conditional blocks)
# by diffing a milestone branch against main. It does not render the
# template itself — deciding which PRs document a manual verification
# procedure (the "release-actions" condition) needs reading PR bodies and
# judgment, which stays a model step in /finish-milestone Step 6.6.
#
# Usage: scripts/render-deploy-sheet.sh <version>
#   <version> — the milestone name, e.g. v2.30.0 (branch milestone/<version>
#   must exist and be checked out or fetched locally)
#
# Output (stdout): one line per mechanical condition that is TRUE for this
# milestone, in the form "CONDITION: detail". A condition with nothing to
# report is omitted, not printed false.
#
# Exit codes:
#   0 = ran to completion (output may legitimately be empty — that means no
#       mechanical conditions triggered, not that the script failed)
#   2 = could not diff (milestone branch not found, not a git repo, etc.)
set -uo pipefail

VERSION="${1:?Usage: render-deploy-sheet.sh <version>}"
BRANCH="milestone/${VERSION}"

if ! git rev-parse --verify "$BRANCH" >/dev/null 2>&1; then
  echo "Branch $BRANCH not found locally — fetch it first." >&2
  exit 2
fi

if ! DIFF_FILES="$(git diff --name-only "main...${BRANCH}" 2>&1)"; then
  echo "Could not diff main...${BRANCH}: $DIFF_FILES" >&2
  exit 2
fi

# A failed diff here would read as "no CREATE TRIGGER", so fail loudly instead.
if ! FULL_DIFF="$(git diff "main...${BRANCH}")"; then
  echo "Could not read the full diff main...${BRANCH}." >&2
  exit 2
fi

# Quiet checks on DIFF_FILES/FULL_DIFF use a here-string: a piped `grep -q`
# that stops at an early match SIGPIPEs the writer, and pipefail reads that 141
# as "no match" (#2225). The per-file pipe in the new-pages loop is one line.
if grep -q '^database/migrations/' <<<"$DIFF_FILES"; then
  NEW_MIGRATIONS="$(printf '%s\n' "$DIFF_FILES" | grep '^database/migrations/')"
  echo "migration: TRUE"
  printf '%s\n' "$NEW_MIGRATIONS" | sed 's/^/  - /'
  if grep -q 'CREATE TRIGGER' <<<"$FULL_DIFF"; then
    echo "trigger-migration: TRUE"
  fi
fi

if grep -qx 'scripts/server-hooks/post-receive' <<<"$DIFF_FILES"; then
  echo "hook-changed: TRUE (scripts/server-hooks/post-receive)"
fi

NEW_SECURE_PAGES=""
for f in $DIFF_FILES; do
  if git diff "main...${BRANCH}" --diff-filter=A --name-only -- "$f" | grep -q . 2>/dev/null; then
    if grep -q 'securePage(' "$f" 2>/dev/null; then
      NEW_SECURE_PAGES="${NEW_SECURE_PAGES}${f}\n"
    fi
  fi
done
if [ -n "$NEW_SECURE_PAGES" ]; then
  echo "new-pages: TRUE"
  printf '%b' "$NEW_SECURE_PAGES" | sed 's/^/  - /'
fi

if grep -qE '^app/admin/scripts/(fix|maintenance)/' <<<"$DIFF_FILES"; then
  echo "admin-scripts: TRUE"
  printf '%s\n' "$DIFF_FILES" | grep -E '^app/admin/scripts/(fix|maintenance)/' | sed 's/^/  - /'
fi

if grep -qx '.env.example' <<<"$DIFF_FILES"; then
  echo "env-vars: TRUE"
  git diff "main...${BRANCH}" -- .env.example | grep '^+' | grep -v '^+++' | sed 's/^/  /'
fi

echo
echo "release-actions: CHECK MANUALLY — read each merged issue PR's body for a" >&2
echo "documented manual verification/deployment procedure; this cannot be" >&2
echo "detected from the diff alone." >&2
