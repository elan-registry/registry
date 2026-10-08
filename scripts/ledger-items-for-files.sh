#!/bin/bash
#
# Lists the open cleanup-ledger items for a set of files. The ledger is
# docs/development/CLEANUP_LEDGER.md in the repository that holds the
# current directory. The workflow commands pipe a file list into this
# script, so a change sees the open items for the files that it touches.
#
# Usage: git diff --name-only <base>..HEAD | scripts/ledger-items-for-files.sh
#
# stdin: one repo-relative path per line. Blank lines are ignored. Empty
# stdin gives empty output and exit 0, and one note on stderr. The ledger
# is not read.
#
# On stdout, one line per open item under a heading that matches an input
# path:
#   <path>: <item text>
# An item prints once, with the first input path (in input order) that
# matches its heading. Control characters (other than TAB) are removed from
# the printed text.
#
# Ledger format that this script reads:
#   - A section starts at a `### ` heading and ends at the next `## ` or
#     `### ` heading.
#   - Each backticked token in a `### ` heading names a file. A token that
#     ends in `/` matches each path under that directory. Any other token
#     matches only the exact path. Other heading text is ignored.
#   - An item is a column-0 line that starts with `- [ ] `. Its text is the
#     rest of the line, without trailing spaces and TABs. Indented lines and
#     other lines are ignored.
#   - A trailing CR is removed from ledger lines and from input paths.
#
# Exit codes:
#   0  The ledger was read. Empty output means no open items.
#   1  Usage error: an argument was given, or stdin is a TTY.
#   2  The current directory is not in a git work tree, or the ledger file
#      is missing or cannot be read.

set -euo pipefail

prog="$(basename "$0")"
LEDGER_REL="docs/development/CLEANUP_LEDGER.md"

if [ "$#" -gt 0 ] || [ -t 0 ]; then
    echo "Usage: git diff --name-only <base>..HEAD | ${prog}" >&2
    exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

LC_ALL=C awk '{ sub(/\r$/, "") } $0 != "" { print }' > "${tmp_dir}/paths"
if [ ! -s "${tmp_dir}/paths" ]; then
    echo "${prog}: no input paths, the ledger was not read" >&2
    exit 0
fi

if ! root="$(git rev-parse --show-toplevel 2>/dev/null)"; then
    echo "${prog}: the current directory is not in a git work tree" >&2
    exit 2
fi
ledger="${root}/${LEDGER_REL}"
if [ ! -f "$ledger" ] || [ ! -r "$ledger" ]; then
    echo "${prog}: cannot read ${LEDGER_REL}" >&2
    exit 2
fi

# Paths go to awk through ENVIRON, because `awk -v` expands backslash
# escapes. LC_ALL=C makes substr() and length() count bytes.
# shellcheck disable=SC2016
if ! LEDGER_PATHS="${tmp_dir}/paths" LC_ALL=C awk '
function token_matches(tok, path,    n) {
    n = length(tok)
    if (substr(tok, n, 1) == "/")
        return length(path) > n && substr(path, 1, n) == tok
    return path == tok
}
BEGIN {
    np = 0
    while ((getline p < ENVIRON["LEDGER_PATHS"]) > 0) paths[++np] = p
    close(ENVIRON["LEDGER_PATHS"])
    sec_path = ""
}
{
    line = $0
    sub(/\r$/, "", line)
    if (substr(line, 1, 4) == "### ") {
        ntok = 0
        rest = substr(line, 5)
        while ((a = index(rest, "`")) > 0) {
            rest = substr(rest, a + 1)
            b = index(rest, "`")
            if (b == 0) break
            tok = substr(rest, 1, b - 1)
            if (tok != "") toks[++ntok] = tok
            rest = substr(rest, b + 1)
        }
        sec_path = ""
        for (i = 1; i <= np && sec_path == ""; i++)
            for (j = 1; j <= ntok; j++)
                if (token_matches(toks[j], paths[i])) { sec_path = paths[i]; break }
    } else if (substr(line, 1, 3) == "## ") {
        sec_path = ""
    } else if (substr(line, 1, 6) == "- [ ] " && sec_path != "") {
        text = substr(line, 7)
        sub(/[ \t]+$/, "", text)
        out = sec_path ": " text
        gsub(/[\001-\010\013-\037\177]/, "", out)
        print out
    }
}
' "$ledger"; then
    echo "${prog}: cannot read ${LEDGER_REL}" >&2
    exit 2
fi
