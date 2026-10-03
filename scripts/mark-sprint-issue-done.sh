#!/bin/bash
#
# Marks one issue done in a sprint plan's arrow-chain sequence line.
# finish-issue.md Step 8.5 used to describe this edit in prose only — this
# script does the edit so the step is a call plus an exit-code branch.
#
# Usage: scripts/mark-sprint-issue-done.sh <version> <issue-number>
#   version        E.g. v2.30.4 (matches docs/plans/sprints/<version>.md).
#   issue-number   The issue number to mark, e.g. 1591.
#
# The sprint file holds a sequence line such as:
#   **#1591 → #1547 → #1438 → #1439**
# This script finds that line, and if "#<issue-number>" appears in it not
# already prefixed with a checkmark, rewrites it to "✅#<issue-number>" —
# preserving every arrow, other issue number, and surrounding formatting
# exactly. It is idempotent: an already-marked issue is left untouched and
# still reported as success.
#
# docs/plans/ is gitignored (private scratch — see CLAUDE.md's Planning Work
# section), so this is a plain local file edit. Nothing is staged or
# committed.
#
# Exit codes:
#   0  Marked done, or was already marked done. Not an error either way —
#      finish-issue.md treats a missing sprint file as a normal case (not
#      every milestone has one), so this script keeps that same posture for
#      the issue-not-tracked case (exit 1) but not for the file-missing case,
#      which callers should also treat as normal, not blocking.
#   1  Sprint file docs/plans/sprints/<version>.md does not exist. This is
#      NORMAL, not an error a caller should stop on — see finish-issue.md's
#      "no matching file exists: skip this step silently."
#   2  Sprint file exists, but issue #<issue-number> does not appear
#      anywhere in its sequence line (or no sequence line was found at all).

set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "Usage: $(basename "$0") <version> <issue-number>" >&2
    exit 2
fi

version="$1"
issue_number="$2"

case "$issue_number" in
    ''|*[!0-9]*)
        echo "mark-sprint-issue-done.sh: issue number '$issue_number' is not numeric." >&2
        exit 2
        ;;
esac

sprint_file="docs/plans/sprints/${version}.md"

if [ ! -f "$sprint_file" ]; then
    echo "mark-sprint-issue-done.sh: '$sprint_file' does not exist (normal — not every milestone has a sprint plan)." >&2
    exit 1
fi

target="#${issue_number}"
marked_target="✅#${issue_number}"

# The sequence line is the one bold line containing this issue's "#NNN" as
# a whole token (not a substring of a longer number, e.g. #1591 vs #15911).
seq_line_num="$(grep -nF "$target" "$sprint_file" \
    | grep -E "(^|[^0-9])\\#${issue_number}([^0-9]|\$)" \
    | head -n1 | cut -d: -f1 || true)"

if [ -z "$seq_line_num" ]; then
    echo "mark-sprint-issue-done.sh: issue #${issue_number} not found in '$sprint_file'." >&2
    exit 2
fi

line_content="$(sed -n "${seq_line_num}p" "$sprint_file")"

case "$line_content" in
    *"$marked_target"*)
        echo "mark-sprint-issue-done.sh: #${issue_number} already marked done in '$sprint_file'."
        exit 0
        ;;
esac

# Replace the first bare "#<issue_number>" (not already preceded by ✅) with
# the checkmarked form. sed's regex has no negative lookbehind, so match the
# character before the token and re-emit it — this also correctly leaves a
# DIFFERENT already-marked issue elsewhere on the line untouched.
new_content="$(printf '%s\n' "$line_content" | sed -E "s/([^#[:alnum:]]|^)#${issue_number}([^0-9]|\$)/\\1✅#${issue_number}\\2/")"

if [ "$new_content" = "$line_content" ]; then
    echo "mark-sprint-issue-done.sh: found #${issue_number} on line ${seq_line_num} of '$sprint_file' but the substitution made no change — not marking." >&2
    exit 2
fi

tmp_file="${sprint_file}.tmp.$$"
awk -v n="$seq_line_num" -v repl="$new_content" \
    'NR == n { print repl; next } { print }' "$sprint_file" > "$tmp_file"
mv -f "$tmp_file" "$sprint_file"

echo "mark-sprint-issue-done.sh: marked #${issue_number} done in '$sprint_file'."
exit 0
