#!/bin/bash
#
# Ticks cleanup-ledger items that a merged PR completed. /finish-issue pipes
# the lines of the PR body's "## Ledger items" section into it after the
# merge (#2271). It is the only script that writes to the ledger.
#
# Usage: <Ledger items section lines> | scripts/ledger-tick-items.sh
#
# stdin: one `<path>: <item text>` line per item. A leading `- ` is removed,
# so the PR body bullets can go in as they are. The split is on the first
# `: ` only, because item text can contain `: `. Blank lines and `none`
# lines (also `none (...)`) are ignored. Empty stdin ticks nothing and exits
# 0, with no gh call, and one note on stderr.
#
# For each line, the script ticks the first open `- [ ] <item text>` line
# (exact text) under a heading that matches the path. It reads the issue
# body first, then the comments in API order. scripts/lib/ledger.sh
# describes the heading and item rules, and the comment-author filter.
#   - A second open line with the same text under the same heading stays
#     open, and a warning goes to stderr.
#   - A line with no open match ticks nothing, and a warning goes to stderr.
# Each changed source gets one PATCH. All bytes other than the ticked `[ ]`
# stay the same, CRLF line endings included.
#
# A tick is a read-then-write of whole bodies: the script reads the body and
# comments, then PATCHes each changed one in full. An edit that someone makes
# to the same body or comment between the read and the PATCH is lost. This is
# an accepted risk: one maintainer edits the ledger, and the window is
# seconds long.
#
# On stdout, one line per ticked item, after its PATCH succeeds:
#   ticked: <path>: <item text>
# When a PATCH fails, each item of that source goes to stderr instead:
#   not ticked: <path>: <item text>
# Control characters (other than TAB) are removed from printed text only.
#
# Exit codes:
#   0  The script ran. Warnings on stderr name the items it did not tick.
#   1  Usage error: an argument was given, or stdin is a TTY.
#   2  gh failed (read or PATCH), gh output was truncated, or there is not
#      exactly one open cleanup-ledger issue.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib/ledger.sh
source "${SCRIPT_DIR}/lib/ledger.sh"

prog="$(basename "$0")"

if [ "$#" -gt 0 ] || [ -t 0 ]; then
    echo "Usage: <Ledger items section lines> | ${prog}" >&2
    exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

# The request files hold one request per line, in input order, and the two
# files stay line-aligned. A repeated request is dropped. Otherwise it would
# tick a second line with the same text.
LEDGER_REQ_PATHS="${tmp_dir}/req-paths" LEDGER_REQ_ITEMS="${tmp_dir}/req-items" \
LEDGER_PROG="$prog" LC_ALL=C awk "${LEDGER_AWK_LIB}"'
BEGIN { printf "" > ENVIRON["LEDGER_REQ_PATHS"]; printf "" > ENVIRON["LEDGER_REQ_ITEMS"] }
{
    line = $0
    sub(/\r$/, "", line)
    if (substr(line, 1, 2) == "- ") line = substr(line, 3)
    if (line == "" || line == "none" || substr(line, 1, 6) == "none (") next
    sep = index(line, ": ")
    if (sep <= 1 || sep + 2 > length(line)) {
        print ENVIRON["LEDGER_PROG"] ": warning: not a \"path: item text\" line, skipped: " ledger_printable(line) | "cat 1>&2"
        next
    }
    path = substr(line, 1, sep - 1)
    item = substr(line, sep + 2)
    if ((path SUBSEP item) in seen) next
    seen[path SUBSEP item] = 1
    print path > ENVIRON["LEDGER_REQ_PATHS"]
    print item > ENVIRON["LEDGER_REQ_ITEMS"]
}
'
if [ ! -s "${tmp_dir}/req-paths" ]; then
    echo "${prog}: no requests, no query made" >&2
    exit 0
fi

if ! issue="$(ledger_find_issue)"; then
    exit 2
fi
if ! ledger_fetch_sources "$issue" "$tmp_dir"; then
    exit 2
fi

: > "${tmp_dir}/done"

# Each request ticks at most one line in all sources. The done file carries
# the ticked request numbers from one source to the next.
# shellcheck disable=SC2016
tick_prog='
BEGIN {
    nr = 0
    while ((getline p < ENVIRON["LEDGER_REQ_PATHS"]) > 0) rpath[++nr] = p
    close(ENVIRON["LEDGER_REQ_PATHS"])
    n = 0
    while ((getline it < ENVIRON["LEDGER_REQ_ITEMS"]) > 0) ritem[++n] = it
    close(ENVIRON["LEDGER_REQ_ITEMS"])
    while ((getline d < ENVIRON["LEDGER_DONE"]) > 0) done[d] = 1
    close(ENVIRON["LEDGER_DONE"])
    printf "" > ENVIRON["LEDGER_TICKED"]
    ledger_ntok = 0
}
{
    rec[NR] = $0
    ledger_classify($0)
    if (ledger_kind == "section" || ledger_kind == "end") {
        delete ticked_here
        next
    }
    if (ledger_kind != "open" || ledger_ntok == 0) next
    hit = 0
    for (r = 1; r <= nr; r++) {
        if ((r in done) || ritem[r] != ledger_text) continue
        if (!ledger_section_matches(rpath[r])) continue
        rec[NR] = "- [x] " substr($0, 7)
        done[r] = 1
        ticked_here[r] = 1
        print r >> ENVIRON["LEDGER_DONE"]
        print "ticked: " ledger_printable(rpath[r] ": " ritem[r]) >> ENVIRON["LEDGER_TICKED"]
        hit = 1
        break
    }
    if (hit) next
    for (r in ticked_here) {
        if (ritem[r] == ledger_text && ledger_section_matches(rpath[r]))
            print ENVIRON["LEDGER_PROG"] ": warning: a second open line has the same text under the same heading. Only the first was ticked: " ledger_printable(rpath[r] ": " ritem[r]) | "cat 1>&2"
    }
}
END {
    out = ENVIRON["LEDGER_OUT"]
    printf "" > out
    for (i = 1; i <= NR; i++) {
        printf "%s", rec[i] > out
        if (i < NR || ENVIRON["LEDGER_TRAILING_NL"] == "1") printf "\n" > out
    }
}
'

changed=()
while IFS=$'\t' read -r kind id file; do
    # awk cannot see if the last line had a newline, so the script tells it.
    trailing_nl=0
    if [ -s "$file" ] && [ -z "$(tail -c 1 "$file")" ]; then
        trailing_nl=1
    fi
    LEDGER_REQ_PATHS="${tmp_dir}/req-paths" LEDGER_REQ_ITEMS="${tmp_dir}/req-items" \
    LEDGER_DONE="${tmp_dir}/done" LEDGER_OUT="${file}.new" LEDGER_TICKED="${file}.ticked" \
    LEDGER_TRAILING_NL="$trailing_nl" LEDGER_PROG="$prog" \
        LC_ALL=C awk "${LEDGER_AWK_LIB}${tick_prog}" "$file"
    if ! cmp -s "$file" "${file}.new"; then
        changed+=("${kind}"$'\t'"${id}"$'\t'"${file}")
    fi
done < "${tmp_dir}/sources"

status=0
# not_ticked <file>: writes each item that <file> would tick to stderr.
not_ticked() {
    sed 's/^ticked: /not ticked: /' "${1}.ticked" >&2
}

for entry in ${changed[@]+"${changed[@]}"}; do
    IFS=$'\t' read -r kind id file <<< "$entry"
    # ledger_fetch_sources checks the id too. This check keeps a bad id out
    # of the PATCH URL if that check changes.
    case "$id" in
        ''|*[!0-9]*)
            echo "${prog}: the ${kind} id is not numeric, no PATCH sent: ${id}" >&2
            not_ticked "$file"
            status=2
            continue
            ;;
    esac
    endpoint="$(ledger_endpoint "$kind" "$id")"
    # jq --rawfile keeps every byte of the new body, CR and trailing newline
    # included. The JSON then goes to gh unchanged through --input.
    if ! jq -n --rawfile body "${file}.new" '{body: $body}' > "${file}.json"; then
        echo "${prog}: could not build the PATCH payload for ${endpoint}." >&2
        not_ticked "$file"
        status=2
        continue
    fi
    if ! gh api -X PATCH "$endpoint" --input "${file}.json" > /dev/null; then
        echo "${prog}: the PATCH of ${endpoint} failed. Its items were not ticked." >&2
        not_ticked "$file"
        status=2
        continue
    fi
    cat "${file}.ticked"
done

LEDGER_REQ_PATHS="${tmp_dir}/req-paths" LEDGER_REQ_ITEMS="${tmp_dir}/req-items" \
LEDGER_DONE="${tmp_dir}/done" LEDGER_PROG="$prog" LC_ALL=C awk "${LEDGER_AWK_LIB}"'
BEGIN {
    while ((getline d < ENVIRON["LEDGER_DONE"]) > 0) done[d] = 1
    n = 0
    while ((getline it < ENVIRON["LEDGER_REQ_ITEMS"]) > 0) ritem[++n] = it
    r = 0
    while ((getline p < ENVIRON["LEDGER_REQ_PATHS"]) > 0) {
        r++
        if (!(r in done))
            print ENVIRON["LEDGER_PROG"] ": warning: no open item under a heading that matches the path, nothing ticked: " ledger_printable(p ": " ritem[r]) | "cat 1>&2"
    }
}
'

exit "$status"
