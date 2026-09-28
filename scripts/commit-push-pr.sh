#!/usr/bin/env bash
# Commits, pushes, and opens (or updates) a draft PR — the mechanical part
# of /commit-push-pr. The model writes the commit message and PR title/body
# ahead of time and passes them in as files; this script does everything
# else: branch-safety checks, staging, commit, push, and PR create/reuse.
#
# Usage:
#   scripts/commit-push-pr.sh [--dry-run] --message-file <f> --title <t> \
#       --body-file <f> [--base <ref>] [--branch <name>]
#
# Options:
#   --dry-run        Print every command this script would run. Run none of
#                     them, except read-only git queries needed to decide
#                     what to print (e.g. current branch, existing PR check).
#   --message-file    Path to a file holding the commit message.
#   --title           PR title (a single argument, not a file).
#   --body-file       Path to a file holding the PR body.
#   --base            Base branch for the PR. Defaults to the output of
#                     scripts/resolve-base-branch.sh (with any "origin/"
#                     prefix stripped).
#   --branch          Branch name to create when currently on a refused
#                     branch (main/master/milestone/*). If omitted and the
#                     current branch is refused, this script exits 1 instead
#                     of guessing a name — the command is expected to have
#                     branched already, or to pass --branch itself.
#
# Refuses to run (exit 1) when:
#   - The current branch (after any --branch handling) is main, master, or
#     matches milestone/*.
#   - Any staged or to-be-staged path is under docs/plans/ or _noupload/ —
#     both are private/gitignored-by-convention and must never enter a
#     commit.
#
# Exit codes:
#   0 = done (commit created, branch pushed, PR created or already existed)
#   1 = refused (branch rule or forbidden path)
#   2 = a git or gh command failed
#   3 = base branch could not be resolved (resolve-base-branch.sh missing
#       or exited 1) — the caller must ask the user rather than guess
set -uo pipefail

DRY_RUN=0
MESSAGE_FILE=""
TITLE=""
BODY_FILE=""
BASE=""
NEW_BRANCH=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run)
            DRY_RUN=1
            shift
            ;;
        --message-file)
            MESSAGE_FILE="${2:-}"
            shift 2
            ;;
        --title)
            TITLE="${2:-}"
            shift 2
            ;;
        --body-file)
            BODY_FILE="${2:-}"
            shift 2
            ;;
        --base)
            BASE="${2:-}"
            shift 2
            ;;
        --branch)
            NEW_BRANCH="${2:-}"
            shift 2
            ;;
        *)
            echo "Unknown argument: $1" >&2
            exit 1
            ;;
    esac
done

if [ -z "$MESSAGE_FILE" ] || [ -z "$TITLE" ] || [ -z "$BODY_FILE" ]; then
    echo "Usage: $0 [--dry-run] --message-file <f> --title <t> --body-file <f> [--base <ref>] [--branch <name>]" >&2
    exit 1
fi

if [ ! -f "$MESSAGE_FILE" ]; then
    echo "Refused: message file not found: $MESSAGE_FILE" >&2
    exit 1
fi
if [ ! -f "$BODY_FILE" ]; then
    echo "Refused: body file not found: $BODY_FILE" >&2
    exit 1
fi

run() {
    if [ "$DRY_RUN" -eq 1 ]; then
        printf '[dry-run]'
        printf ' %q' "$@"
        printf '\n'
        return 0
    fi
    "$@"
}

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || {
    echo "Refused: not inside a git repository" >&2
    exit 1
}
cd "$REPO_ROOT" || exit 2

# --- Branch safety -----------------------------------------------------

CURRENT_BRANCH="$(git branch --show-current)"

is_refused_branch() {
    case "$1" in
        main|master|milestone/*) return 0 ;;
        *) return 1 ;;
    esac
}

if is_refused_branch "$CURRENT_BRANCH"; then
    if [ -z "$NEW_BRANCH" ]; then
        echo "Refused: current branch '$CURRENT_BRANCH' is main, master, or milestone/* — pass --branch <name> or branch first." >&2
        exit 1
    fi
    if is_refused_branch "$NEW_BRANCH"; then
        echo "Refused: --branch '$NEW_BRANCH' is itself main, master, or milestone/*." >&2
        exit 1
    fi
    if ! run git checkout -b "$NEW_BRANCH"; then
        echo "git checkout -b '$NEW_BRANCH' failed (branch may already exist)." >&2
        exit 2
    fi
    CURRENT_BRANCH="$NEW_BRANCH"
elif [ -n "$NEW_BRANCH" ] && [ "$NEW_BRANCH" != "$CURRENT_BRANCH" ]; then
    if ! run git checkout -b "$NEW_BRANCH"; then
        echo "git checkout -b '$NEW_BRANCH' failed (branch may already exist)." >&2
        exit 2
    fi
    CURRENT_BRANCH="$NEW_BRANCH"
fi

# --- Staging -------------------------------------------------------------
# Stage only the changed files that git status lists, never the whole tree.
# `git add -A` is used with that explicit pathspec list so a rename's old
# path is staged as a removal. Refuse if any listed path is forbidden.

# is_forbidden_path <path> — true if the path must never be staged. A pure
# function (no I/O) so tests/hooks/test-commit-push-pr.sh can source it and
# exercise the rule directly, without needing a real forbidden path to exist
# in the working tree (docs/plans/ and _noupload/ are gitignored, and a
# repo-level pre-tool hook already blocks `git add -f` on them, so this
# script's own check is a defense-in-depth backstop that is otherwise hard
# to trigger from a live git status).
is_forbidden_path() {
    case "$1" in
        docs/plans/*|_noupload/*) return 0 ;;
        *) return 1 ;;
    esac
}

# parse_porcelain_z — read `git status --porcelain=v1 -z` on stdin and print
# one changed path per line. The line format ("XY old -> new") is ambiguous
# for renames and for paths with spaces. In -z mode a rename or copy entry is
# "XY new" followed by a separate "old" field. Print both paths, so a move
# into a forbidden directory is caught and the old path's removal is staged.
# Pure (stdin only) so tests/hooks/test-commit-push-pr.sh can feed it input.
parse_porcelain_z() {
    local entry status orig
    while IFS= read -r -d '' entry; do
        status="${entry:0:2}"
        printf '%s\n' "${entry:3}"
        if [[ "$status" == R* || "$status" == C* ]]; then
            IFS= read -r -d '' orig || break
            printf '%s\n' "$orig"
        fi
    done
}

if ! STATUS_OUTPUT="$(git status --porcelain=v1 -z --untracked-files=all)"; then
    echo "git status failed" >&2
    exit 2
fi

CHANGED_FILES=()
while IFS= read -r f; do
    CHANGED_FILES+=("$f")
done < <(printf '%s' "$STATUS_OUTPUT" | parse_porcelain_z)

FORBIDDEN=()
for f in "${CHANGED_FILES[@]:-}"; do
    [ -z "$f" ] && continue
    is_forbidden_path "$f" && FORBIDDEN+=("$f")
done

if [ "${#FORBIDDEN[@]}" -gt 0 ]; then
    echo "Refused: the following changed paths are under docs/plans/ or _noupload/ and must not be staged:" >&2
    printf '  %s\n' "${FORBIDDEN[@]}" >&2
    exit 1
fi

TO_STAGE=()
for f in "${CHANGED_FILES[@]:-}"; do
    [ -z "$f" ] && continue
    TO_STAGE+=("$f")
done

if [ "${#TO_STAGE[@]}" -eq 0 ]; then
    echo "Nothing to stage (working tree clean)." >&2
else
    if ! run git add -A -- "${TO_STAGE[@]}"; then
        echo "git add failed" >&2
        exit 2
    fi
fi

# --- Commit ----------------------------------------------------------------

# A clean tree with commits already made is normal (the user ran /commit
# first). Skip the commit and push what exists.
if [ "${#TO_STAGE[@]}" -eq 0 ]; then
    echo "Nothing to commit; pushing existing commits." >&2
elif ! run git commit -F "$MESSAGE_FILE"; then
    echo "git commit failed" >&2
    exit 2
fi

# --- Push --------------------------------------------------------------

if ! run git push -u origin "$CURRENT_BRANCH"; then
    echo "git push failed" >&2
    exit 2
fi

# --- Resolve base branch -------------------------------------------------

if [ -z "$BASE" ]; then
    if [ ! -x "scripts/resolve-base-branch.sh" ]; then
        echo "Cannot determine base branch: scripts/resolve-base-branch.sh is missing or not executable, and no --base was given." >&2
        exit 3
    fi
    RESOLVED="$(scripts/resolve-base-branch.sh)"
    RESOLVE_STATUS=$?
    if [ "$RESOLVE_STATUS" -ne 0 ] || [ -z "$RESOLVED" ]; then
        echo "Cannot determine base branch: scripts/resolve-base-branch.sh exited $RESOLVE_STATUS." >&2
        exit 3
    fi
    BASE="${RESOLVED#origin/}"
fi

# --- Create or reuse PR -------------------------------------------------

EXISTING_PR_URL="$(gh pr view "$CURRENT_BRANCH" --json url --jq .url 2>/dev/null)"

if [ -n "$EXISTING_PR_URL" ]; then
    echo "$EXISTING_PR_URL"
    exit 0
fi

if [ "$DRY_RUN" -eq 1 ]; then
    printf '[dry-run] gh pr create --draft --base %q --head %q --title %q --body-file %q\n' \
        "$BASE" "$CURRENT_BRANCH" "$TITLE" "$BODY_FILE"
    exit 0
fi

PR_URL="$(gh pr create --draft --base "$BASE" --head "$CURRENT_BRANCH" --title "$TITLE" --body-file "$BODY_FILE")" || {
    echo "gh pr create failed" >&2
    exit 2
}

echo "$PR_URL"
exit 0
