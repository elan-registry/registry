#!/bin/bash
#
# Lists the open cleanup-ledger items for a set of files. /review-pr,
# /commit-push-pr and /finish-issue pipe a diff's file list into it, so an
# item for a touched file is seen before the PR is pushed (#2271).
#
# Usage: git diff --name-only <base>..HEAD | scripts/ledger-items-for-files.sh
#
# stdin: one repo-relative path per line. Blank lines are ignored. Empty
# stdin gives empty output and exit 0, with no gh call, and one note on
# stderr.
#
# On stdout, one line per open item under a heading that matches an input
# path:
#   <path>: <item text>
# Sources are read in order: the issue body, then the comments in API order.
# An item prints once, with the first input path (in input order) that
# matches its heading. Control characters (other than TAB) are removed from
# the printed text. scripts/lib/ledger.sh describes the heading and item
# rules, and the comment-author filter.
#
# Exit codes:
#   0  The query ran. Empty output means no open items.
#   1  Usage error: an argument was given, or stdin is a TTY.
#   2  gh failed, gh output was truncated, or there is not exactly one open
#      cleanup-ledger issue.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib/ledger.sh
source "${SCRIPT_DIR}/lib/ledger.sh"

if [ "$#" -gt 0 ] || [ -t 0 ]; then
    echo "Usage: git diff --name-only <base>..HEAD | $(basename "$0")" >&2
    exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

# A trailing CR on a path would stop an exact match, so remove it here.
LC_ALL=C awk '{ sub(/\r$/, "") } $0 != "" { print }' > "${tmp_dir}/paths"
if [ ! -s "${tmp_dir}/paths" ]; then
    echo "$(basename "$0"): no input paths, no query made" >&2
    exit 0
fi

if ! issue="$(ledger_find_issue)"; then
    exit 2
fi
if ! ledger_fetch_sources "$issue" "$tmp_dir"; then
    exit 2
fi

files=()
while IFS=$'\t' read -r _kind _id file; do
    files+=("$file")
done < "${tmp_dir}/sources"

# Each source starts outside a section, so a heading in one source never
# applies to items in the next.
LEDGER_PATHS="${tmp_dir}/paths" LC_ALL=C awk "${LEDGER_AWK_LIB}"'
BEGIN {
    np = 0
    while ((getline p < ENVIRON["LEDGER_PATHS"]) > 0) paths[++np] = p
    close(ENVIRON["LEDGER_PATHS"])
}
FNR == 1 { delete ledger_tok; ledger_ntok = 0; sec_path = "" }
{
    ledger_classify($0)
    if (ledger_kind == "section") {
        sec_path = ""
        for (i = 1; i <= np; i++)
            if (ledger_section_matches(paths[i])) { sec_path = paths[i]; break }
    } else if (ledger_kind == "end") {
        sec_path = ""
    } else if (ledger_kind == "open" && sec_path != "") {
        print ledger_printable(sec_path ": " ledger_text)
    }
}
' "${files[@]}"
