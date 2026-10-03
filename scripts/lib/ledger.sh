#!/bin/bash
#
# Sourceable library for the cleanup ledger (the open GitHub issue with the
# `cleanup-ledger` label). scripts/ledger-items-for-files.sh and
# scripts/ledger-tick-items.sh source it, so the two scripts find the issue,
# fetch it, and parse it in the same way.
#
# This file defines functions and one awk library string. It does not run
# anything by itself. Source it, then call the functions.
#
# Ledger format that the parser reads:
#   - A section starts at a `### ` heading and ends at the next `## ` or
#     `### ` heading.
#   - Each backticked token in a `### ` heading names a file. A token that
#     ends in `/` matches each path under that directory. Any other token
#     matches only the exact path. Other heading text is ignored.
#   - An item is a column-0 line that starts with `- [ ] ` (open) or
#     `- [x] ` (ticked). Its text is the rest of the line. Indented lines and
#     other lines are ignored.
#   - A trailing CR is removed for matching only. The fetched bytes stay the
#     same, so a tick does not change the line endings of the ledger.
#
# Comment authors: only comments whose author_association is OWNER, MEMBER
# or COLLABORATOR are read. Other comments are ignored. The ledger issue is
# public, so any GitHub user can comment on it. Without this filter, an
# outside comment could add items that the scripts list and tick.
#
# Truncation: each fetched record ends with ` END`. A record without that
# marker, or with text after it, stops the fetch with exit 2. macOS
# `base64 -d` exits 0 on short input, so the marker check, not the decoder,
# stops a truncated gh output from becoming a short body that a tick writes
# back.
#
# Item text is untrusted. The awk code compares it as a literal string. All
# values go to awk through ENVIRON, because `awk -v` expands backslash
# escapes. Output goes through ledger_printable(), which removes control
# characters from the printed copy only.

LEDGER_REPO="elan-registry/registry"

# The --jq expressions of the two fetch calls. The test stubs accept only
# these exact strings, so a change here must also change the tests.
# shellcheck disable=SC2016
LEDGER_BODY_JQ='"\(.body // "" | @base64) END"'
# shellcheck disable=SC2016
LEDGER_COMMENTS_JQ='.[] | select(.author_association == "OWNER" or .author_association == "MEMBER" or .author_association == "COLLABORATOR") | "\(.id) \(.body // "" | @base64) END"'

# Prints the number of the one open cleanup-ledger issue. Returns 2 when gh
# fails, when no open ledger issue exists, or when more than one exists. A
# tick to the wrong issue is worse than no tick, so the caller must not
# guess.
ledger_find_issue() {
    local prog numbers count
    prog="$(basename "$0")"
    if ! numbers="$(gh issue list --repo "$LEDGER_REPO" --label cleanup-ledger \
            --state open --json number --jq '.[].number')"; then
        echo "${prog}: gh could not list the cleanup-ledger issues." >&2
        return 2
    fi
    count="$(printf '%s\n' "$numbers" | grep -c '[0-9]' || true)"
    if [ "$count" -eq 0 ]; then
        echo "${prog}: no open issue has the cleanup-ledger label." >&2
        return 2
    fi
    if [ "$count" -gt 1 ]; then
        echo "${prog}: more than one open cleanup-ledger issue: ${numbers//$'\n'/, }" >&2
        return 2
    fi
    case "$numbers" in
        *[!0-9]*)
            echo "${prog}: gh returned a ledger issue number that is not numeric: ${numbers}" >&2
            return 2
            ;;
    esac
    printf '%s\n' "$numbers"
}

# Prints the REST endpoint that a PATCH of one source must use.
# $1 is `body` or `comment`. $2 is the issue number or the comment id.
ledger_endpoint() {
    case "$1" in
        body) printf 'repos/%s/issues/%s\n' "$LEDGER_REPO" "$2" ;;
        comment) printf 'repos/%s/issues/comments/%s\n' "$LEDGER_REPO" "$2" ;;
        *) return 1 ;;
    esac
}

# Prints record $1 without its trailing ` END` marker. Returns 1 when the
# marker is missing, which is how a truncated gh output shows.
ledger_strip_end() {
    case "$1" in
        *" END") printf '%s\n' "${1% END}" ;;
        *) return 1 ;;
    esac
}

# Fetches the issue body and each member comment into separate files in
# directory $2, and writes the manifest $2/sources. Each manifest line is
# `kind<TAB>id<TAB>file`, in body-then-comments order. Returns 2 on a gh
# failure or on a truncated or malformed record.
#
# gh prints each body base64-encoded, and this function decodes it into its
# file. Text from `--jq .body` or from `$(...)` would lose a trailing
# newline, and a tick must write back the same bytes. The base64 form also
# keeps one comment on one line when `--paginate` prints many pages.
ledger_fetch_sources() {
    local issue="$1" dir="$2" prog n line rec id b64
    prog="$(basename "$0")"
    : > "${dir}/sources" || return 2

    if ! gh issue view "$issue" --repo "$LEDGER_REPO" --json body \
            --jq "$LEDGER_BODY_JQ" > "${dir}/body.b64"; then
        echo "${prog}: gh could not read the body of issue #${issue}." >&2
        return 2
    fi
    line=""
    IFS= read -r line < "${dir}/body.b64" || true
    if [ "$(grep -c '' "${dir}/body.b64")" -ne 1 ] \
            || ! b64="$(ledger_strip_end "$line")"; then
        echo "${prog}: the body of issue #${issue} from gh is truncated (no END marker). Nothing was changed." >&2
        return 2
    fi
    case "$b64" in
        *[!A-Za-z0-9+/=]*)
            echo "${prog}: the body of issue #${issue} from gh is not base64. Nothing was changed." >&2
            return 2
            ;;
    esac
    if ! printf '%s\n' "$b64" | base64 -d > "${dir}/src-body"; then
        echo "${prog}: could not decode the body of issue #${issue}." >&2
        return 2
    fi
    printf 'body\t%s\t%s\n' "$issue" "${dir}/src-body" >> "${dir}/sources" || return 2

    if ! gh api "repos/${LEDGER_REPO}/issues/${issue}/comments" --paginate \
            --jq "$LEDGER_COMMENTS_JQ" > "${dir}/comments.b64"; then
        echo "${prog}: gh could not read the comments of issue #${issue}." >&2
        return 2
    fi
    n=0
    # `|| [ -n "$line" ]` reads a last line that has no newline, so a
    # truncated last record fails the END check instead of being dropped.
    while IFS= read -r line || [ -n "$line" ]; do
        [ -z "$line" ] && continue
        if ! rec="$(ledger_strip_end "$line")" || [[ ! "$rec" =~ ^([^\ ]*)\ ([^\ ]*)$ ]]; then
            echo "${prog}: a comment record of issue #${issue} from gh is truncated (no END marker). Nothing was changed." >&2
            return 2
        fi
        id="${BASH_REMATCH[1]}"
        b64="${BASH_REMATCH[2]}"
        case "$id" in
            ''|*[!0-9]*)
                echo "${prog}: gh returned a comment id that is not numeric: ${id}" >&2
                return 2
                ;;
        esac
        case "$b64" in
            *[!A-Za-z0-9+/=]*)
                echo "${prog}: comment ${id} of issue #${issue} from gh is not base64. Nothing was changed." >&2
                return 2
                ;;
        esac
        n=$((n + 1))
        if ! printf '%s\n' "$b64" | base64 -d > "${dir}/src-comment-${n}"; then
            echo "${prog}: could not decode comment ${id} of issue #${issue}." >&2
            return 2
        fi
        printf 'comment\t%s\t%s\n' "$id" "${dir}/src-comment-${n}" >> "${dir}/sources" || return 2
    done < "${dir}/comments.b64"
}

# Awk functions that both scripts prepend to their own awk program. Run awk
# with LC_ALL=C so that substr() and length() count bytes. The callers then
# keep each byte that they do not tick.
#
# ledger_classify(line) sets `ledger_kind` to "section", "end", "open",
# "ticked", or "". For "section" it fills ledger_tok[1..ledger_ntok]. For
# "open" and "ticked" it sets ledger_text to the item text.
# ledger_section_matches(path) returns 1 when a token of the current section
# heading matches path.
# ledger_printable(s) returns s without control characters other than TAB.
# Use it for printed text only. Matching and PATCH bytes use the original.
# The sourcing scripts use this variable, and shellcheck cannot see that.
# shellcheck disable=SC2016,SC2034
LEDGER_AWK_LIB='
function ledger_classify(line,    rest, a, b, tok) {
    sub(/\r$/, "", line)
    ledger_kind = ""
    ledger_text = ""
    if (substr(line, 1, 4) == "### ") {
        ledger_kind = "section"
        delete ledger_tok
        ledger_ntok = 0
        rest = substr(line, 5)
        while ((a = index(rest, "`")) > 0) {
            rest = substr(rest, a + 1)
            b = index(rest, "`")
            if (b == 0) break
            tok = substr(rest, 1, b - 1)
            if (tok != "") ledger_tok[++ledger_ntok] = tok
            rest = substr(rest, b + 1)
        }
        return
    }
    if (substr(line, 1, 3) == "## ") {
        ledger_kind = "end"
        delete ledger_tok
        ledger_ntok = 0
        return
    }
    if (substr(line, 1, 6) == "- [ ] ") {
        ledger_kind = "open"
        ledger_text = substr(line, 7)
        return
    }
    if (substr(line, 1, 6) == "- [x] ") {
        ledger_kind = "ticked"
        ledger_text = substr(line, 7)
    }
}
function ledger_token_matches(tok, path,    n) {
    n = length(tok)
    if (substr(tok, n, 1) == "/")
        return length(path) > n && substr(path, 1, n) == tok
    return path == tok
}
function ledger_section_matches(path,    i) {
    for (i = 1; i <= ledger_ntok; i++)
        if (ledger_token_matches(ledger_tok[i], path)) return 1
    return 0
}
function ledger_printable(s) {
    gsub(/[\001-\010\013-\037\177]/, "", s)
    return s
}
'
