#!/bin/bash
#
# Prints the bullets of the "## Ledger items" section of a PR body.
# /finish-issue pipes its output into scripts/ledger-tick-items.sh after the
# merge. The extraction is in a script, not in the command Markdown, so a
# test can cover it.
#
# Usage: scripts/ledger-pr-body-items.sh < <PR body file>
#
# stdin: a PR body. A section starts at each line that is exactly
# `## Ledger items`. A trailing CR and trailing spaces and TABs on that line
# are permitted. A section ends at the next line that starts with `## ` or
# `# `. When the heading occurs more than once, each section is read.
#
# Inside a section, a bullet is a line with optional leading spaces and TABs,
# then `-`, `*` or `+`, then one space. A model writes the PR body, so the
# script accepts each Markdown bullet form. Other lines are ignored.
#
# On stdout, one line per bullet, in input order, with a trailing CR removed:
#   - <rest of the bullet line>
# scripts/ledger-tick-items.sh reads that form. The bytes after the bullet
# marker stay the same, because the tick matches the item text exactly.
#
# A `- none (ledger query failed)` line means that /commit-push-pr could not
# query the ledger. The tick script ignores it, so this script writes one
# warning to stderr. Then the report does not look like a user choice of
# `- none`. The line still goes to stdout.
#
# The script makes no gh call.
#
# Exit codes:
#   0  At least one section was found. Empty output means no bullets.
#   1  Usage error: an argument was given, or stdin is a TTY.
#   3  The PR body has no `## Ledger items` section. A note goes to stderr.
#   Any other code: awk failed. Do not trust the output.

set -euo pipefail

prog="$(basename "$0")"

if [ "$#" -gt 0 ] || [ -t 0 ]; then
    echo "Usage: ${prog} < <PR body file>" >&2
    exit 1
fi

# awk exits 3 when it finds no section. Other non-zero codes are awk errors.
rc=0
LEDGER_PROG="$prog" LC_ALL=C awk '
BEGIN { found = 0; in_sec = 0; warned = 0 }
{
    line = $0
    sub(/\r$/, "", line)
    head = line
    sub(/[ \t]+$/, "", head)
    if (head == "## Ledger items") {
        found = 1
        in_sec = 1
        next
    }
    if (substr(line, 1, 3) == "## " || substr(line, 1, 2) == "# ") {
        in_sec = 0
        next
    }
    if (!in_sec) next
    if (!match(line, /^[ \t]*[-*+] /)) next
    out = "- " substr(line, RLENGTH + 1)
    print out
    check = out
    sub(/[ \t]+$/, "", check)
    if (check == "- none (ledger query failed)" && !warned) {
        print ENVIRON["LEDGER_PROG"] ": warning: the ledger query failed when the PR was opened. No items were selected." | "cat 1>&2"
        warned = 1
    }
}
END { exit found ? 0 : 3 }
' || rc=$?

if [ "$rc" -eq 3 ]; then
    echo "${prog}: the PR body has no \"## Ledger items\" section." >&2
fi
exit "$rc"
