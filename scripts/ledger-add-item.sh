#!/usr/bin/env bash
#
# Adds one item to the cleanup ledger. /found (its "Ledger" step),
# /review-pr (Step 6, "Defer" -> "Cleanup ledger") and /address-pr-comments
# (Step 7, through /found) call it, so every caller finds the heading and
# inserts the item in the same way.
#
# Usage: scripts/ledger-add-item.sh <file-path> <item-text>
#
# argv: <file-path> is the repo-relative path that the item is filed under
# (for example app/foo.php). <item-text> is the one-line item text, without
# the leading `- [ ] `. The script adds that prefix. stdin is not read.
# Both arguments must be non-empty and must not contain a newline or a CR.
# The path must not contain a backtick, because the heading puts the path in
# backticks. The path must not end in `:<digits>` and must not start with
# `./` or `/`: heading tokens match paths exactly, so `app/a.php:42` or
# `./app/a.php` would file an item that scripts/ledger-items-for-files.sh
# never finds for `app/a.php`. Callers work from `File:Line` findings, so
# this mistake is likely.
#
# The script reads the issue body first, then the comments in API order, and
# stops at the first `### ` heading whose backticked tokens match the path.
# scripts/lib/ledger.sh describes the heading rules (a token that ends in `/`
# matches each path under that directory) and the comment-author filter.
#   - A heading is found and its group already has an open `- [ ] <item-text>`
#     line with the same text: nothing is written. This stops a second add
#     of an item that /review-pr listed from the ledger itself.
#   - A heading is found otherwise: the new `- [ ] <item-text>` line goes
#     after the last non-blank line of that heading's group (the group ends
#     at the next `## ` or `### ` heading, or at the end of the source). An
#     indented line under the last item stays with that item. A prose line at
#     the end of the group also comes before the new item. That one source
#     gets one PATCH. All other bytes stay the same: CRLF line endings,
#     trailing whitespace, and a missing final newline. The new line takes
#     the line ending of the line before it.
#   - No heading is found: the script posts a new comment on the ledger
#     issue, with "### `<file-path>`" as the heading and the item under it.
#
# An add to an existing heading is a read-then-write of a whole body: the
# script reads the body and comments, then PATCHes the changed one in full.
# An edit that someone makes to the same body or comment between the read
# and the PATCH is lost. This is an accepted risk: one maintainer edits the
# ledger, and the window is seconds long.
#
# On stdout, one line after the write succeeds, or when nothing was needed:
#   added to #<issue>: <file-path>: <item-text> (existing heading)
#   added to #<issue>: <file-path>: <item-text> (new heading)
#   already present in #<issue>: <file-path>: <item-text>
# Control characters (other than TAB) are removed from printed text only.
#
# Exit codes:
#   0  The item was added, or was already present. stdout tells which.
#   1  Usage error: not exactly two arguments, an empty argument, a newline
#      or CR in an argument, or a path with a backtick, a `:<digits>` suffix,
#      or a leading `./` or `/`.
#   2  gh or jq failed (read, PATCH or comment), gh output was truncated,
#      there is not exactly one open cleanup-ledger issue, or another tool
#      failed. When a write call fails, GitHub may still have saved it, so
#      the caller must check the ledger before it runs the script again.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib/ledger.sh
source "${SCRIPT_DIR}/lib/ledger.sh"

prog="$(basename "$0")"

usage() {
    echo "Usage: ${prog} <file-path> <item-text>" >&2
    exit 1
}

[ "$#" -eq 2 ] || usage
path="$1"
item="$2"
[ -n "$path" ] && [ -n "$item" ] || usage
# A newline or CR would put a second line, or a heading, into the ledger.
case "${path}${item}" in
    *$'\n'*|*$'\r'*) usage ;;
esac
case "$path" in
    *'`'*|./*|/*) usage ;;
esac
if [[ "$path" =~ :[0-9]+$ ]]; then
    usage
fi

# After argument checks, so a usage error keeps exit 1. Any later failure
# that the script does not check itself (mktemp, awk, tail) is exit 2: exit 1
# would tell the caller to fix its arguments.
trap 'echo "${prog}: unexpected failure at line ${LINENO}. The item may not have been added." >&2; exit 2' ERR

# printable <text>: <text> without control characters other than TAB.
printable() {
    printf '%s\n' "$1" | LC_ALL=C tr -d '\001-\010\013-\037\177'
}

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

if ! issue="$(ledger_find_issue)"; then
    exit 2
fi
if ! ledger_fetch_sources "$issue" "$tmp_dir"; then
    exit 2
fi

# Finds the first heading in this source that matches the path, and writes
# the source with the new item line into LEDGER_OUT. When no heading
# matches, LEDGER_OUT is not written. When the group already has an open
# item with the same text, only LEDGER_DUP is written.
# shellcheck disable=SC2016
add_prog='
{ rec[NR] = $0 }
END {
    path = ENVIRON["LEDGER_PATH"]
    # ledger_text has no trailing spaces or TABs, so compare the same form.
    item = ENVIRON["LEDGER_ITEM"]
    sub(/[ \t]+$/, "", item)
    head = 0
    ledger_ntok = 0
    for (i = 1; i <= NR; i++) {
        ledger_classify(rec[i])
        if (ledger_kind == "section" && ledger_section_matches(path)) { head = i; break }
    }
    if (head == 0) exit
    anchor = head
    for (i = head + 1; i <= NR; i++) {
        ledger_classify(rec[i])
        if (ledger_kind == "section" || ledger_kind == "end") break
        if (ledger_kind == "open" && ledger_text == item) {
            printf "" > ENVIRON["LEDGER_DUP"]
            exit
        }
        line = rec[i]
        sub(/\r$/, "", line)
        if (line !~ /^[ \t]*$/) anchor = i
    }
    eol = (substr(rec[anchor], length(rec[anchor]), 1) == "\r") ? "\r" : ""
    out = ENVIRON["LEDGER_OUT"]
    printf "" > out
    for (i = 1; i <= NR; i++) {
        printf "%s", rec[i] > out
        if (i < NR || i == anchor || ENVIRON["LEDGER_TRAILING_NL"] == "1") printf "\n" > out
        if (i == anchor) {
            printf "- [ ] %s%s", ENVIRON["LEDGER_ITEM"], eol > out
            if (i < NR || ENVIRON["LEDGER_TRAILING_NL"] == "1") printf "\n" > out
        }
    }
}
'

while IFS=$'\t' read -r kind id file; do
    # awk cannot see if the last line had a newline, so the script tells it.
    trailing_nl=0
    if [ -s "$file" ] && [ -z "$(tail -c 1 "$file")" ]; then
        trailing_nl=1
    fi
    LEDGER_PATH="$path" LEDGER_ITEM="$item" LEDGER_OUT="${file}.new" \
    LEDGER_DUP="${file}.dup" LEDGER_TRAILING_NL="$trailing_nl" \
        LC_ALL=C awk "${LEDGER_AWK_LIB}${add_prog}" "$file"
    if [ -f "${file}.dup" ]; then
        printable "already present in #${issue}: ${path}: ${item}"
        exit 0
    fi
    [ -f "${file}.new" ] || continue

    # ledger_fetch_sources checks the id too. This check keeps a bad id out
    # of the PATCH URL if that check changes.
    case "$id" in
        ''|*[!0-9]*)
            echo "${prog}: the ${kind} id is not numeric, no PATCH sent: ${id}" >&2
            exit 2
            ;;
    esac
    endpoint="$(ledger_endpoint "$kind" "$id")"
    # jq --rawfile keeps every byte of the new body, CR and trailing newline
    # included. The JSON then goes to gh unchanged through --input.
    if ! jq -n --rawfile body "${file}.new" '{body: $body}' > "${file}.json"; then
        echo "${prog}: could not build the PATCH payload for ${endpoint}. The item was not added." >&2
        exit 2
    fi
    if ! gh api -X PATCH "$endpoint" --input "${file}.json" > /dev/null; then
        echo "${prog}: the PATCH of ${endpoint} failed. GitHub may still have saved it: check ledger #${issue} before you run this again." >&2
        exit 2
    fi
    printable "added to #${issue}: ${path}: ${item} (existing heading)"
    exit 0
done < "${tmp_dir}/sources"

if ! gh issue comment "$issue" --repo "$LEDGER_REPO" \
        --body "### \`${path}\`"$'\n'"- [ ] ${item}" > /dev/null; then
    echo "${prog}: gh could not add a comment to issue #${issue}. GitHub may still have saved it: check ledger #${issue} before you run this again." >&2
    exit 2
fi
printable "added to #${issue}: ${path}: ${item} (new heading)"
