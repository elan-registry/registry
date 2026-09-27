#!/usr/bin/env bash
# Lists one-time fix scripts left over from the previous release, and
# applies a decided action (delete or promote) once the developer has
# classified one.
#
# Fix scripts under app/admin/scripts/fix/ are meant to run once against
# production, then leave (git history is the permanent record — see
# docs/development/FIX_SCRIPTS.md). start-milestone.md Step 3.5 asks the
# developer to classify each leftover script; that per-script judgment call
# ("confirmed ran on production?") stays in the command. This script only
# does the mechanical parts: listing candidates with metadata, and running
# the git operation once a decision is made.
#
# Usage:
#   scripts/list-fix-scripts.sh
#     Lists candidate scripts (excludes _TEMPLATE_Fix-Script.php), one per
#     line, tab-separated: <path>\t<first commit date>\t<first commit subject>
#
#   scripts/list-fix-scripts.sh --apply <file> delete
#     `git rm` the named script (developer confirmed it ran on production).
#
#   scripts/list-fix-scripts.sh --apply <file> promote
#     `git mv` the named script to app/admin/scripts/maintenance/ (safe to
#     re-run after future releases).
#
# Exit codes:
#   0 = listing printed (may legitimately be empty — no leftover scripts),
#       or the requested apply action completed
#   2 = usage error (bad action, file not found, file not under
#       app/admin/scripts/fix/)
#   3 = `git rm` or `git mv` itself failed
set -uo pipefail

FIX_DIR="app/admin/scripts/fix"

list_candidates() {
  find "$FIX_DIR" -maxdepth 1 -name "*.php" ! -name "_TEMPLATE_Fix-Script.php" 2>/dev/null | sort | while IFS= read -r FILE; do
    [ -z "$FILE" ] && continue
    FIRST_COMMIT="$(git log --diff-filter=A --format='%ad %s' --date=short -- "$FILE" | tail -1)"
    printf '%s\t%s\n' "$FILE" "${FIRST_COMMIT:-(no history found)}"
  done
  exit 0
}

if [ "${1:-}" != "--apply" ]; then
  list_candidates
fi

FILE="${2:?Usage: list-fix-scripts.sh --apply <file> delete|promote}"
ACTION="${3:?Usage: list-fix-scripts.sh --apply <file> delete|promote}"

case "$FILE" in
  "$FIX_DIR"/*.php) ;;
  *)
    echo "'$FILE' is not under ${FIX_DIR}/ — refusing to touch it." >&2
    exit 2
    ;;
esac

if [ ! -f "$FILE" ]; then
  echo "File not found: $FILE" >&2
  exit 2
fi

case "$ACTION" in
  delete)
    if ! git rm "$FILE"; then
      echo "git rm failed for $FILE" >&2
      exit 3
    fi
    ;;
  promote)
    MAINT_DIR="app/admin/scripts/maintenance"
    if ! git mv "$FILE" "$MAINT_DIR/"; then
      echo "git mv failed for $FILE -> $MAINT_DIR/" >&2
      exit 3
    fi
    ;;
  *)
    echo "Unknown action '$ACTION' — expected delete or promote." >&2
    exit 2
    ;;
esac

exit 0
