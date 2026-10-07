#!/bin/bash
#
# Lists the open cleanup-ledger items whose files are gone. A rename or a
# delete leaves the item under a heading that no diff matches again, so
# scripts/ledger-items-for-files.sh never shows it. /groom-backlog runs this
# script to find those items (#2316).
#
# Usage: scripts/ledger-orphans.sh
#
# Run it from inside the repository. No arguments. stdin is not read. The
# script only reads: it makes no write to GitHub and no change to the repo.
#
# An item is an orphan when it is open and each backticked token in its
# `### ` heading names a path that is not in the tree at HEAD
# (`git ls-tree -r HEAD`):
#   - A token that ends in `/` exists when HEAD has a file under it.
#   - Any other token exists when HEAD has a file with that exact path, or a
#     file under a directory with that name.
# A heading with no backticked token names no path, so its items are never
# orphans. scripts/lib/ledger.sh describes the heading and item rules, and
# the comment-author filter.
#
# On stdout, one line per orphan item, in ledger order (the issue body, then
# the comments in API order):
#   <path>: <item text>
# <path> is the first token of the heading. A heading can name more paths,
# and all of them are gone. A line whose <path> ends in `/` is for a
# directory token. scripts/ledger-tick-items.sh matches that token only with
# a path under it, so add a file name after the `/` before you pipe the line
# into it. Control characters (other than TAB) are removed from the printed
# text.
#
# Exit codes:
#   0  The query ran. Empty output means no orphan items.
#   1  Usage error: an argument was given, or the current directory is not
#      in a git repository with a HEAD commit.
#   2  gh failed, gh output was truncated, or there is not exactly one open
#      cleanup-ledger issue.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib/ledger.sh
source "${SCRIPT_DIR}/lib/ledger.sh"

if [ "$#" -gt 0 ]; then
    echo "Usage: $(basename "$0")" >&2
    exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

# Read the tree before any gh call, so a wrong directory costs no API call.
# -z keeps a path with special characters unquoted.
if ! git ls-tree -r --name-only -z HEAD > "${tmp_dir}/tree.z" 2> /dev/null; then
    echo "$(basename "$0"): run this script inside a git repository with a HEAD commit." >&2
    exit 1
fi
tr '\0' '\n' < "${tmp_dir}/tree.z" > "${tmp_dir}/tree"

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
LEDGER_TREE="${tmp_dir}/tree" LC_ALL=C awk "${LEDGER_AWK_LIB}"'
BEGIN {
    while ((getline p < ENVIRON["LEDGER_TREE"]) > 0) {
        is_file[p] = 1
        # Record each parent directory with its trailing slash.
        d = p
        while ((i = last_slash(d)) > 0) {
            d = substr(d, 1, i - 1)
            is_dir[d "/"] = 1
        }
    }
    close(ENVIRON["LEDGER_TREE"])
}
function last_slash(s,    i, n) {
    n = 0
    while ((i = index(s, "/")) > 0) {
        n += i
        s = substr(s, i + 1)
    }
    return n
}
function token_exists(tok) {
    if (substr(tok, length(tok), 1) == "/") return (tok in is_dir)
    return (tok in is_file) || ((tok "/") in is_dir)
}
FNR == 1 { delete ledger_tok; ledger_ntok = 0; orphan = 0 }
{
    ledger_classify($0)
    if (ledger_kind == "section") {
        orphan = (ledger_ntok > 0)
        for (t = 1; t <= ledger_ntok; t++)
            if (token_exists(ledger_tok[t])) { orphan = 0; break }
        first_tok = ledger_tok[1]
    } else if (ledger_kind == "end") {
        orphan = 0
    } else if (ledger_kind == "open" && orphan) {
        print ledger_printable(first_tok ": " ledger_text)
    }
}
' "${files[@]}"
