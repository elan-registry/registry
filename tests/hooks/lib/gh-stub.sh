# shellcheck shell=bash
# Shared fake `gh` for tests/hooks/test-*.sh. Source it; do not run it.
# It lives in lib/ so that the CI loop over tests/hooks/test-*.sh skips it.
#
# Why one shared stub: a stub that ignores --jq hides a wrong filter. This
# stub runs the real jq on fixture JSON, the same way real gh does. So a test
# fails when a script's filter is wrong.
#
# Usage:
#   gh_stub_install "$TMPROOT/bin"
#   PATH="$TMPROOT/bin:$PATH" STUB_COMMENTS=... ./scripts/some-script.sh
#
# The stub reads these variables when it runs:
#   STUB_COMMENTS       JSON file. Page 1 of an issue-comments call.
#   STUB_PAGE1/PAGE2    JSON files. Used instead of STUB_COMMENTS when
#                       STUB_PAGE1 is set. Page 2 is read only with
#                       --paginate, as real gh does.
#   STUB_PR_JSON        JSON file for `gh pr view`.
#   STUB_MAIN_BLOB      The .sha for `gh api repos/*/contents/*?ref=main`.
#   STUB_HEAD_BLOB      The .sha for the same call with any other ref.
#   STUB_GH_FAIL=1      Every call exits 1.
#   STUB_GH_FAIL_PR_VIEW=1  Only `gh pr view` exits 1.
#   STUB_LOG            File. Each call appends one line of arguments.
#   STUB_POSTED_DIR     Directory. `gh pr comment` saves each body here as
#                       1.md, 2.md, and so on.
#   STUB_EXTRA          Executable. The stub runs it first with the call
#                       arguments. Exit 0 means it handled the call. Exit 3
#                       means it did not, and the stub goes on. Other codes
#                       are the final exit code of the stub.

# gh_stub_install <bindir>
gh_stub_install() {
    local bindir="${1:?gh_stub_install needs a directory}"
    mkdir -p "$bindir" || return 1
    cat > "$bindir/gh" <<'STUB'
#!/bin/bash
# Fake gh. See tests/hooks/lib/gh-stub.sh for the variables it reads.

[ -n "${STUB_LOG:-}" ] && printf '%s\n' "$*" >> "$STUB_LOG"

if [ "${STUB_GH_FAIL:-0}" = "1" ]; then
    echo "gh: simulated failure (stub)" >&2
    exit 1
fi

if [ -n "${STUB_EXTRA:-}" ] && [ -x "$STUB_EXTRA" ]; then
    "$STUB_EXTRA" "$@"
    rc=$?
    [ "$rc" -ne 3 ] && exit "$rc"
fi

all_args="$*"
paginate=0
filter=""
has_filter=0
body_file=""
body_text=""
has_body=0
positional=()
while [ $# -gt 0 ]; do
    case "$1" in
        --paginate) paginate=1 ;;
        --jq|-q) filter="${2-}"; has_filter=1; shift ;;
        --jq=*) filter="${1#--jq=}"; has_filter=1 ;;
        --body-file|-F)
            # -F also means "typed field" for gh api. Only gh pr comment
            # reads body_file, so the overlap does no harm.
            body_file="${2-}"; shift ;;
        --body-file=*) body_file="${1#--body-file=}" ;;
        --body|-b) body_text="${2-}"; has_body=1; shift ;;
        --body=*) body_text="${1#--body=}"; has_body=1 ;;
        # Flags with a value that real gh consumes. Skip the value so it is
        # not mistaken for an endpoint or a PR number.
        --repo|-R|--json|-H|--header|-f|--raw-field|--field|-X|--method|--hostname|--template|-t|--cache|--input)
            shift ;;
        -*) ;;
        *) positional+=("$1") ;;
    esac
    shift
done

# Print a fixture file the way real gh does: through `jq -r F` when a filter
# is given, so string output is raw.
emit_file() {
    local f="$1"
    if [ "$has_filter" = "1" ]; then
        jq -r "$filter" "$f"
    else
        cat "$f"
    fi
}

case "${positional[0]:-}" in
    api)
        endpoint="${positional[1]:-}"
        case "$endpoint" in
            repos/*/contents/*\?ref=*)
                ref="${endpoint##*\?ref=}"
                if [ "$ref" = "main" ]; then
                    blob="${STUB_MAIN_BLOB:-}"
                else
                    blob="${STUB_HEAD_BLOB:-}"
                fi
                if [ -z "$blob" ]; then
                    echo "gh: no blob for ref $ref (stub)" >&2
                    exit 1
                fi
                json="$(jq -cn --arg sha "$blob" '{sha: $sha}')"
                if [ "$has_filter" = "1" ]; then
                    printf '%s' "$json" | jq -r "$filter"
                else
                    printf '%s\n' "$json"
                fi
                exit $?
                ;;
            repos/*/comments|repos/*/comments\?*)
                pages=()
                if [ -n "${STUB_PAGE1:-}" ]; then
                    pages+=("$STUB_PAGE1")
                    if [ "$paginate" = "1" ] && [ -n "${STUB_PAGE2:-}" ]; then
                        pages+=("$STUB_PAGE2")
                    fi
                elif [ -n "${STUB_COMMENTS:-}" ]; then
                    pages+=("$STUB_COMMENTS")
                else
                    echo "gh: no comment fixture (stub)" >&2
                    exit 1
                fi
                # The filter runs on each page alone. A script that needs
                # a whole-list filter must fail here, as with real gh.
                for page in "${pages[@]}"; do
                    emit_file "$page" || exit 1
                done
                exit 0
                ;;
        esac
        ;;
    pr)
        case "${positional[1]:-}" in
            view)
                if [ "${STUB_GH_FAIL_PR_VIEW:-0}" = "1" ]; then
                    echo "gh: simulated pr view failure (stub)" >&2
                    exit 1
                fi
                if [ -z "${STUB_PR_JSON:-}" ]; then
                    echo "gh: no pr fixture (stub)" >&2
                    exit 1
                fi
                emit_file "$STUB_PR_JSON"
                exit $?
                ;;
            comment)
                if [ -n "${STUB_POSTED_DIR:-}" ]; then
                    mkdir -p "$STUB_POSTED_DIR" || exit 1
                    n=1
                    while [ -e "$STUB_POSTED_DIR/$n.md" ]; do n=$((n + 1)); done
                    if [ -n "$body_file" ]; then
                        cp "$body_file" "$STUB_POSTED_DIR/$n.md" || exit 1
                    elif [ "$has_body" = "1" ]; then
                        printf '%s' "$body_text" > "$STUB_POSTED_DIR/$n.md"
                    fi
                fi
                exit 0
                ;;
        esac
        ;;
esac

echo "gh: unknown call (stub): $all_args" >&2
exit 1
STUB
    chmod +x "$bindir/gh"
}
