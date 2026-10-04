#!/bin/bash
#
# Regression test for scripts/ledger-pr-body-items.sh.
#
# HERMETIC: the script under test makes no gh call. A stub `gh` is first on
# PATH all the same, so the end-to-end case can pipe the output into
# scripts/ledger-tick-items.sh and no call reaches GitHub. The stub accepts
# only the exact argv of the calls that the tick script makes: the issue
# list, the issue view of #2208, the comments read, and
# `api -X PATCH repos/elan-registry/registry/issues/2208 --input <file>`. Any
# other call writes `stub gh: unexpected call: ...` to stderr and exits 99.
# It serves fixture files named by env vars:
#   STUB_LIST      numbers that `gh issue list` prints, one per line
#   STUB_BODY      the issue body file
#   STUB_COMMENTS  lines of `<id> <path to comment body file>`
# It logs each call to $STUB_LOG. It saves the "body" of each PATCH payload,
# byte for byte, to $STUB_PATCH_DIR/<seq>-issue-2208.txt.
#
# Output goes in files and is compared with cmp. Nothing compares it through
# $(...), because that drops trailing newlines.
#
# Usage: bash tests/hooks/test-ledger-pr-body-items.sh
# Exit code: 0 if all cases pass, 1 otherwise.

# Fixture lines hold literal backticks, so single quotes are on purpose.
# shellcheck disable=SC2016
set -u

REPO_ROOT="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REPO_ROOT/scripts/ledger-pr-body-items.sh"
TICK="$REPO_ROOT/scripts/ledger-tick-items.sh"
for f in "$SCRIPT" "$TICK"; do
    if [ ! -x "$f" ]; then
        echo "FAIL: $f not found or not executable" >&2
        exit 1
    fi
done

TMPROOT="$(mktemp -d)" || exit 1
cleanup() {
    cd / || true
    [ -n "${TMPROOT:-}" ] && rm -rf "$TMPROOT"
}
trap cleanup EXIT

TESTS_RUN=0
TESTS_FAILED=0

pass() { TESTS_RUN=$((TESTS_RUN + 1)); echo "PASS: $1"; }
fail() {
    TESTS_RUN=$((TESTS_RUN + 1))
    TESTS_FAILED=$((TESTS_FAILED + 1))
    echo "FAIL: $1"
    shift
    local line
    for line in "$@"; do echo "      $line"; done
}

# --- Stub gh ----------------------------------------------------------------
STUB_BIN="$TMPROOT/bin"
mkdir -p "$STUB_BIN"
cat > "$STUB_BIN/gh" <<'EOF'
#!/bin/bash
# Stub gh for test-ledger-pr-body-items.sh.
echo "$*" >> "$STUB_LOG"
ARGV=("$@")
# The exact --jq expressions that scripts/lib/ledger.sh must send.
BODY_JQ='"\(.body // "" | @base64) END"'
COMMENTS_JQ='.[] | select(.author_association == "OWNER" or .author_association == "MEMBER" or .author_association == "COLLABORATOR") | "\(.id) \(.body // "" | @base64) END"'

# same <word>...: true when the argv is exactly these words.
same() { [ "$(printf '%s\037' "${ARGV[@]}")" = "$(printf '%s\037' "$@")" ]; }

unexpected() {
    printf 'stub gh: unexpected call:' >&2
    printf ' [%s]' "${ARGV[@]}" >&2
    echo >&2
    exit 99
}

if same issue list --repo elan-registry/registry --label cleanup-ledger \
        --state open --json number --jq '.[].number'; then
    cat "$STUB_LIST"
    exit 0
fi
if same issue view 2208 --repo elan-registry/registry --json body --jq "$BODY_JQ"; then
    jq -Rs '{body: .}' < "$STUB_BODY" | jq -r "$9"
    exit 0
fi
if same api repos/elan-registry/registry/issues/2208/comments --paginate --jq "$COMMENTS_JQ"; then
    while read -r id file; do
        [ -z "$id" ] && continue
        jq -Rs --arg id "$id" '{id: ($id | tonumber), author_association: "MEMBER", body: .}' < "$file"
    done < "$STUB_COMMENTS" | jq -s . | jq -r "$5"
    exit 0
fi
if [ "$#" -eq 6 ] && [ "$1" = api ] && [ "$2" = -X ] && [ "$3" = PATCH ] \
        && [ "$4" = repos/elan-registry/registry/issues/2208 ] \
        && [ "$5" = --input ] && [ -f "$6" ]; then
    seq=$(( $(find "$STUB_PATCH_DIR" -type f | wc -l | tr -d ' ') + 1 ))
    jq -j .body < "$6" > "$(printf '%s/%02d-issue-2208.txt' "$STUB_PATCH_DIR" "$seq")" || exit 98
    echo '{}'
    exit 0
fi
unexpected
EOF
chmod +x "$STUB_BIN/gh"
export PATH="$STUB_BIN:$PATH"

FX="$TMPROOT/fx"
mkdir -p "$FX"
export STUB_LOG="$TMPROOT/gh.log"
export STUB_LIST="$FX/list"
export STUB_BODY="$FX/body.md"
export STUB_COMMENTS="$FX/comments"
export STUB_PATCH_DIR="$TMPROOT/patches"
OUT="$TMPROOT/out"
ERR="$TMPROOT/err"
EXP="$TMPROOT/exp"
IN="$TMPROOT/in"

# run_body: sends the file $IN as the PR body.
run_body() {
    : > "$STUB_LOG"
    "$SCRIPT" < "$IN" > "$OUT" 2> "$ERR"
    RC=$?
}

# body_lines <line>...: writes one PR body line per argument to $IN.
body_lines() { printf '%s\n' "$@" > "$IN"; }

expect_lines() { printf '%s\n' "$@" > "$EXP"; }

check_rc() {
    if [ "$RC" -eq "$2" ]; then
        pass "$1"
    else
        fail "$1" "expected exit $2, got $RC" "stderr: $(cat "$ERR")"
    fi
}

check_out() {
    if cmp -s "$EXP" "$OUT"; then
        pass "$1"
    else
        # od -c shows CR, TAB and trailing spaces. macOS cat has no -A.
        fail "$1" "stdout differs (expected < > actual):" "$(diff "$EXP" "$OUT" | od -c)"
    fi
}

check_out_empty() {
    if [ ! -s "$OUT" ]; then
        pass "$1"
    else
        fail "$1" "stdout is not empty:" "$(cat "$OUT")"
    fi
}

check_err_empty() {
    if [ ! -s "$ERR" ]; then
        pass "$1"
    else
        fail "$1" "stderr is not empty:" "$(cat "$ERR")"
    fi
}

check_err_has() {
    if grep -qF -- "$2" "$ERR"; then
        pass "$1"
    else
        fail "$1" "stderr lacks: $2" "stderr: $(cat "$ERR")"
    fi
}

check_no_gh() {
    if [ ! -s "$STUB_LOG" ]; then
        pass "$1"
    else
        fail "$1" "gh was called:" "$(cat "$STUB_LOG")"
    fi
}

# --- Case 1: a section between other ## sections ------------------------------
body_lines '## Summary' '- summary bullet' '' '## Ledger items' '' \
    '- app/a.php: fix X' '- lib/b.php: key: value' '' '## Test plan' '- test bullet'
run_body
check_rc "1 section between others: exit 0" 0
expect_lines '- app/a.php: fix X' '- lib/b.php: key: value'
check_out "1 section between others: only its bullets, text after the first ': ' kept"
check_err_empty "1 section between others: no stderr"
check_no_gh "1 section between others: no gh call"

# --- Case 2: CRLF line endings ------------------------------------------------
printf '## Summary\r\n- s\r\n## Ledger items\r\n- app/a.php: fix X\r\n- app/b.php: y\r\n## Next\r\n- n\r\n' > "$IN"
run_body
check_rc "2 CRLF body: exit 0" 0
expect_lines '- app/a.php: fix X' '- app/b.php: y'
check_out "2 CRLF body: CR removed, section found"

# --- Case 3: '* ', '+ ' and indented bullets become '- ' -----------------------
printf '## Ledger items\n* app/star.php: s\n+ app/plus.php: p\n  - app/indent.php: i\n\t* app/tab.php: t\n- app/dash.php: d\n' > "$IN"
run_body
check_rc "3 bullet forms: exit 0" 0
expect_lines '- app/star.php: s' '- app/plus.php: p' '- app/indent.php: i' '- app/tab.php: t' '- app/dash.php: d'
check_out "3 bullet forms: '* ', '+ ', space and TAB indents all print as '- '"

# --- Case 4: the heading twice ------------------------------------------------
body_lines '## Ledger items' '- app/one.php: first' '## Other' '- other' \
    '## Ledger items' '- app/two.php: second'
run_body
check_rc "4 heading twice: exit 0" 0
expect_lines '- app/one.php: first' '- app/two.php: second'
check_out "4 heading twice: both sections are read, in order"

# --- Case 5: '- none' passes with no warning ----------------------------------
body_lines '## Ledger items' '' '- none'
run_body
check_rc "5 none: exit 0" 0
expect_lines '- none'
check_out "5 none: the line passes through"
check_err_empty "5 none: no warning"
body_lines '## Ledger items' '- none (no ledger items)'
run_body
expect_lines '- none (no ledger items)'
check_out "5 other none (...) text: the line passes through"
check_err_empty "5 other none (...) text: no warning"

# --- Case 6: '- none (ledger query failed)' gives a warning -------------------
printf '## Ledger items\r\n\r\n- none (ledger query failed)\r\n' > "$IN"
run_body
check_rc "6 query failed: exit 0" 0
expect_lines '- none (ledger query failed)'
check_out "6 query failed: the line passes through"
check_err_has "6 query failed: warning on stderr" \
    "ledger-pr-body-items.sh: warning: the ledger query failed when the PR was opened. No items were selected."

# --- Case 7: no section gives exit 3 ------------------------------------------
body_lines '## Summary' '- s' '### Ledger items' '- not a section' '## Ledger items extra' '- no'
run_body
check_rc "7 no section: exit 3" 3
check_out_empty "7 no section: empty stdout"
check_err_has "7 no section: a note on stderr" 'no "## Ledger items" section'
: > "$IN"
run_body
check_rc "7 empty body: exit 3" 3

# --- Case 8: heading with trailing spaces and TAB -----------------------------
printf '## Ledger items  \t\n- app/a.php: fix X\n' > "$IN"
run_body
check_rc "8 heading with trailing whitespace: exit 0" 0
expect_lines '- app/a.php: fix X'
check_out "8 heading with trailing whitespace: section found"
body_lines '## Ledger items' '' 'Text that is not a bullet.'
run_body
check_rc "8 section with no bullets: exit 0" 0
check_out_empty "8 section with no bullets: empty stdout"

# --- Case 9: non-bullet lines are ignored, and '# ' ends the section ----------
body_lines '## Ledger items' 'Some text.' '-no-space' '*also-no-space' \
    '1. numbered' '> - quoted' '- app/a.php: kept' '# Top heading' '- after top heading'
run_body
check_rc "9 non-bullet lines: exit 0" 0
expect_lines '- app/a.php: kept'
check_out "9 non-bullet lines are ignored, '# ' ends the section"

# --- Case 10: an argument gives exit 1 ----------------------------------------
"$SCRIPT" extra < /dev/null > "$OUT" 2> "$ERR"
RC=$?
check_rc "10 argument: exit 1" 1
check_out_empty "10 argument: empty stdout"
check_err_has "10 argument: usage text on stderr" "Usage"

# --- Case 11: end to end into ledger-tick-items.sh ----------------------------
echo 2208 > "$STUB_LIST"
: > "$STUB_COMMENTS"
cat > "$STUB_BODY" <<'EOF'
### `app/a.php`
- [ ] fix X
- [ ] keep open
### `lib/b.php`
- [ ] other item
EOF
cat > "$EXP.body" <<'EOF'
### `app/a.php`
- [x] fix X
- [ ] keep open
### `lib/b.php`
- [x] other item
EOF
printf '## Summary\r\n- s\r\n## Ledger items\r\n  * app/a.php: fix X  \r\n+ `lib/b.php`: other item\r\n## Test plan\r\n- t\r\n' > "$IN"
: > "$STUB_LOG"
rm -rf "$STUB_PATCH_DIR"
mkdir -p "$STUB_PATCH_DIR"
"$SCRIPT" < "$IN" > "$TMPROOT/items" 2> "$ERR"
RC=$?
check_rc "11 end to end: extraction exit 0" 0
"$TICK" < "$TMPROOT/items" > "$OUT" 2> "$ERR"
RC=$?
check_rc "11 end to end: tick exit 0" 0
if cmp -s "$EXP.body" "$STUB_PATCH_DIR/01-issue-2208.txt"; then
    pass "11 end to end: the right items are ticked, the other stays open"
else
    fail "11 end to end: the right items are ticked, the other stays open" \
        "present: $(ls "$STUB_PATCH_DIR")" "stderr: $(cat "$ERR")"
fi
expect_lines 'ticked: app/a.php: fix X' 'ticked: lib/b.php: other item'
check_out "11 end to end: one ticked line per item"

echo
echo "Ran $TESTS_RUN checks, $TESTS_FAILED failed."
[ "$TESTS_FAILED" -eq 0 ]
