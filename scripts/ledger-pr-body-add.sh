#!/bin/bash
#
# Adds ledger items to the "## Ledger items" section of a PR body.
# /commit-push-pr (on an existing PR) and /address-pr-comments use it to
# record an item that was fixed after the PR opened. /finish-issue ticks only
# the items in that section, through scripts/ledger-pr-body-items.sh.
#
# Usage: scripts/ledger-pr-body-add.sh <items file> < <PR body file> > <new body file>
#
# <items file>: one `path: item text` line per item, as
# scripts/ledger-items-for-files.sh prints it. A leading `- ` is removed.
# Blank lines are ignored. A trailing CR and trailing spaces and TABs are
# removed.
#
# stdin: the current PR body.
#
# stdout: the new PR body.
#   - An item that is already a bullet in a "## Ledger items" section is not
#     added again.
#   - The new items go as `- path: item text` bullets at the end of the
#     first "## Ledger items" section, in input order.
#   - When an item is added, each `- none` and `- none (...)` bullet in a
#     "## Ledger items" section is removed.
#   - When the body has no "## Ledger items" section, the script adds one at
#     the end of the body.
#   - When no item is new, stdout is the same as stdin.
#
# The script makes no gh call.
#
# Exit codes:
#   0  Done. A note on stderr gives the number of added items.
#   1  Usage error: wrong number of arguments, the items file is not
#      readable, or stdin is a TTY.
#   Any other code: a command failed. Do not use the output.

set -euo pipefail

prog="$(basename "$0")"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "$#" -ne 1 ] || [ ! -r "$1" ] || [ -t 0 ]; then
    echo "Usage: ${prog} <items file> < <PR body file> > <new body file>" >&2
    exit 1
fi

items_file="$1"
tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

cat > "$tmp_dir/body"

# Bullets already in a Ledger items section. Exit 3 means no section.
rc=0
"$SCRIPT_DIR/ledger-pr-body-items.sh" < "$tmp_dir/body" > "$tmp_dir/existing" 2>/dev/null || rc=$?
if [ "$rc" -ne 0 ] && [ "$rc" -ne 3 ]; then
    echo "${prog}: scripts/ledger-pr-body-items.sh exited ${rc}." >&2
    exit 2
fi
has_section=1
[ "$rc" -eq 3 ] && has_section=0

# Normalize the items and drop the ones already present or repeated.
# FILENAME, not FNR == NR, picks the first file, because that file can be
# empty.
EXISTING="$tmp_dir/existing" LC_ALL=C awk '
FILENAME == ENVIRON["EXISTING"] {
    line = $0
    sub(/\r$/, "", line)
    sub(/[ \t]+$/, "", line)
    seen[line] = 1
    next
}
{
    line = $0
    sub(/\r$/, "", line)
    sub(/[ \t]+$/, "", line)
    sub(/^- /, "", line)
    if (line == "") next
    bullet = "- " line
    if (bullet in seen) next
    seen[bullet] = 1
    print bullet
}
' "$tmp_dir/existing" "$items_file" > "$tmp_dir/new"

added="$(wc -l < "$tmp_dir/new" | tr -d ' ')"

if [ "$added" -eq 0 ]; then
    cat "$tmp_dir/body"
    echo "${prog}: added 0 items." >&2
    exit 0
fi

if [ "$has_section" -eq 0 ]; then
    cat "$tmp_dir/body"
    # Start the new section on its own paragraph. $(...) drops a final
    # newline, so a non-empty result means the body has no final newline.
    if [ -s "$tmp_dir/body" ]; then
        [ -n "$(tail -c 1 "$tmp_dir/body")" ] && printf '\n'
        printf '\n'
    fi
    printf '## Ledger items\n\n'
    cat "$tmp_dir/new"
    echo "${prog}: added ${added} items." >&2
    exit 0
fi

# Insert the new bullets at the end of the first section, before its
# trailing blank lines. Remove each `none` bullet from every section.
NEW_FILE="$tmp_dir/new" LC_ALL=C awk '
function flush_new(   l) {
    while ((getline l < ENVIRON["NEW_FILE"]) > 0) print l
    close(ENVIRON["NEW_FILE"])
    done_insert = 1
}
BEGIN { in_sec = 0; first = 0; done_insert = 0; blanks = 0; wrote = 0 }
{
    line = $0
    bare = line
    sub(/\r$/, "", bare)
    head = bare
    sub(/[ \t]+$/, "", head)
    is_heading = (substr(bare, 1, 3) == "## " || substr(bare, 1, 2) == "# ")
    if (in_sec && is_heading) {
        if (first && !done_insert) {
            if (!wrote) print ""
            flush_new()
        }
        print ""
        blanks = 0
        in_sec = 0
    }
    if (head == "## Ledger items") {
        print line
        in_sec = 1
        first = !done_insert
        wrote = 0
        blanks = 0
        next
    }
    if (in_sec) {
        if (head == "") { blanks++; next }
        check = head
        if (match(check, /^[ \t]*[-*+] /)) {
            rest = substr(check, RLENGTH + 1)
            if (rest == "none" || rest ~ /^none \(/) next
        }
        if (!wrote) print ""
        else for (i = 0; i < blanks; i++) print ""
        blanks = 0
        print line
        wrote = 1
        next
    }
    print line
}
END {
    if (in_sec) {
        if (first && !done_insert) {
            if (!wrote) print ""
            flush_new()
        }
    }
}
' "$tmp_dir/body"

echo "${prog}: added ${added} items." >&2
exit 0
