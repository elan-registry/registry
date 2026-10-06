#!/bin/bash
#
# Regression test for scripts/ledger-items-for-files.sh (#2271).
#
# HERMETIC: a stub `gh` is first on PATH, so no call reaches GitHub. The stub
# accepts only the exact argv of the three calls that the script makes: the
# issue list, the issue view of #2208, and the comments read. Any other call
# writes `stub gh: unexpected call: ...` to stderr and exits 99. It serves
# fixture files named by env vars:
#   STUB_LIST      numbers that `gh issue list` prints, one per line
#   STUB_BODY      the issue body file
#   STUB_COMMENTS  lines of `<id> <path to comment body file> [association]`
#                  (association defaults to MEMBER)
#   STUB_FAIL      list | view | comments: that call exits 1
#   STUB_TRUNCATE  body | comments: that output loses its final newline, its
#                  last ` END` marker and 8 base64 characters
# The stub builds the JSON that the GitHub API returns from the fixtures, and
# runs the script's own --jq expression on it with jq. So the END marker and
# the author filter are tested, not copied. It logs each call to $STUB_LOG.
#
# Expected output goes in files and is compared with cmp. Nothing compares
# script output through $(...), because that drops trailing newlines.
#
# Usage: bash tests/hooks/test-ledger-items-for-files.sh
# Exit code: 0 if all cases pass, 1 otherwise.

# Fixture lines hold literal backticks and $, so single quotes are on purpose.
# shellcheck disable=SC2016
set -u

REPO_ROOT="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REPO_ROOT/scripts/ledger-items-for-files.sh"
if [ ! -x "$SCRIPT" ]; then
    echo "FAIL: $SCRIPT not found or not executable" >&2
    exit 1
fi

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
# Stub gh for test-ledger-items-for-files.sh.
echo "$*" >> "$STUB_LOG"
fail="${STUB_FAIL:-}"
ARGV=("$@")
# The exact --jq expressions that scripts/lib/ledger.sh must send.
BODY_JQ='"\(.body // "" | @base64) END"'
COMMENTS_JQ='.[] | select(.author_association == "OWNER" or .author_association == "MEMBER" or .author_association == "COLLABORATOR") | "\(.id) \(.body // "" | @base64) END"'

# same <word>...: true when the argv is exactly these words.
same() { [ "$(printf '%s\037' "${ARGV[@]}")" = "$(printf '%s\037' "$@")" ]; }

# emit <kind>: prints stdin, truncated when STUB_TRUNCATE names <kind>.
emit() {
    local out
    out="$(cat)"
    if [ "${STUB_TRUNCATE:-}" = "$1" ]; then
        printf '%s' "${out:0:${#out}-12}"
    else
        printf '%s\n' "$out"
    fi
}

if same issue list --repo elan-registry/registry --label cleanup-ledger \
        --state open --json number --jq '.[].number'; then
    [ "$fail" = list ] && exit 1
    cat "$STUB_LIST"
    exit 0
fi
if same issue view 2208 --repo elan-registry/registry --json body --jq "$BODY_JQ"; then
    [ "$fail" = view ] && exit 1
    jq -Rs '{body: .}' < "$STUB_BODY" | jq -r "$9" | emit body
    exit 0
fi
if same api repos/elan-registry/registry/issues/2208/comments --paginate --jq "$COMMENTS_JQ"; then
    [ "$fail" = comments ] && exit 1
    while read -r id file assoc; do
        [ -z "$id" ] && continue
        jq -Rs --arg id "$id" --arg a "${assoc:-MEMBER}" \
            '{id: ($id | if test("^[0-9]+$") then tonumber else . end), author_association: $a, body: .}' < "$file"
    done < "$STUB_COMMENTS" | jq -s . | jq -r "$5" | emit comments
    exit 0
fi
printf 'stub gh: unexpected call:' >&2
printf ' [%s]' "$@" >&2
echo >&2
exit 99
EOF
chmod +x "$STUB_BIN/gh"
export PATH="$STUB_BIN:$PATH"

FX="$TMPROOT/fx"
mkdir -p "$FX"
export STUB_LOG="$TMPROOT/gh.log"
export STUB_LIST="$FX/list"
export STUB_BODY="$FX/body.md"
export STUB_COMMENTS="$FX/comments"
OUT="$TMPROOT/out"
ERR="$TMPROOT/err"
EXP="$TMPROOT/exp"
IN="$TMPROOT/in"

# --- Fixture ledger: a body and two comments, all four heading forms ---------
cat > "$FX/body.md" <<'EOF'
# Cleanup ledger

Intro mentions `app/intro.php` outside any heading.
- [ ] preamble item before any heading

## Open items

### `app/one.php`
- [ ] one-open: first item
- [x] one-ticked item
  - [ ] indented item
* [ ] star item
-[ ] no-space item
- [ ]no-gap item
plain text line

### `lib/a.php` + `lib/b.php` (est. −125)
- [ ] plus-item

### `lib/c.php`, `lib/d.php`
- [ ] comma-item

### `scripts/spike-1871/`
- [ ] dir-item

## Closed items
- [ ] after-end item
EOF
cat > "$FX/c111.md" <<'EOF'
### `app/two.php`
- [ ] comment-item one
- [ ] comment-item two
EOF
cat > "$FX/c222.md" <<'EOF'
- [ ] orphan item
### `lib/c.php`
- [ ] item in 222
EOF
printf '111 %s\n222 %s\n' "$FX/c111.md" "$FX/c222.md" > "$FX/comments.default"

reset_env() {
    unset STUB_FAIL STUB_TRUNCATE
    echo 2208 > "$STUB_LIST"
    cp "$FX/comments.default" "$STUB_COMMENTS"
    : > "$STUB_LOG"
}

# run_paths <path>...: sends one path per line on stdin.
run_paths() {
    : > "$STUB_LOG"
    printf '%s\n' "$@" > "$IN"
    "$SCRIPT" < "$IN" > "$OUT" 2> "$ERR"
    RC=$?
}

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
        fail "$1" "stdout differs (expected < > actual):" "$(diff "$EXP" "$OUT")"
    fi
}

check_out_empty() {
    if [ ! -s "$OUT" ]; then
        pass "$1"
    else
        fail "$1" "stdout is not empty:" "$(cat "$OUT")"
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

reset_env

# --- Case 1: match in the body ------------------------------------------------
run_paths app/one.php
expect_lines "app/one.php: one-open: first item"
check_rc "1 body match: exit 0" 0
check_out "1 body match: only the open item, text keeps its ': '"

# --- Case 2: match in a comment -----------------------------------------------
run_paths app/two.php
expect_lines "app/two.php: comment-item one" "app/two.php: comment-item two"
check_rc "2 comment match: exit 0" 0
check_out "2 comment match: both comment items"

# --- Case 3: a ticked item is skipped -----------------------------------------
cat > "$FX/body.tmp" <<'EOF'
### `x/y.php`
- [x] done item
- [ ] open item
- [x] another done item
EOF
STUB_BODY="$FX/body.tmp" run_paths x/y.php
expect_lines "x/y.php: open item"
check_out "3 ticked item skipped"

# --- Case 4: a path with no heading ------------------------------------------
run_paths nothing/here.php
check_rc "4 no heading for the path: exit 0" 0
check_out_empty "4 no heading for the path: empty output"

# --- Case 5: no open items ----------------------------------------------------
cat > "$FX/body.tmp" <<'EOF'
### `x/y.php`
- [x] done item
EOF
: > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run_paths x/y.php
check_rc "5 no open items: exit 0" 0
check_out_empty "5 no open items: empty output"
reset_env

# --- Case 6: gh failures give exit 2 ------------------------------------------
for step in list view comments; do
    STUB_FAIL="$step" run_paths app/one.php
    check_rc "6 gh $step failure: exit 2" 2
    check_out_empty "6 gh $step failure: empty stdout"
done

# --- Case 7: no ledger issue, and two ledger issues ---------------------------
: > "$STUB_LIST"
run_paths app/one.php
check_rc "7 no open ledger issue: exit 2" 2
check_out_empty "7 no open ledger issue: empty stdout"
if grep -q 'issue view' "$STUB_LOG"; then
    fail "7 no open ledger issue: no issue was read" "$(cat "$STUB_LOG")"
else
    pass "7 no open ledger issue: no issue was read"
fi
printf '2208\n2300\n' > "$STUB_LIST"
run_paths app/one.php
check_rc "7 two open ledger issues: exit 2" 2
check_out_empty "7 two open ledger issues: empty stdout"
if grep -q 'issue view' "$STUB_LOG"; then
    fail "7 two open ledger issues: no issue was read" "$(cat "$STUB_LOG")"
else
    pass "7 two open ledger issues: no issue was read"
fi
echo '12x' > "$STUB_LIST"
run_paths app/one.php
check_rc "7 non-numeric issue number: exit 2" 2
reset_env

# --- Case 8: an argument gives exit 1 -----------------------------------------
: > "$STUB_LOG"
"$SCRIPT" app/one.php < /dev/null > "$OUT" 2> "$ERR"
RC=$?
check_rc "8 argument: exit 1" 1
check_out_empty "8 argument: empty stdout"
check_no_gh "8 argument: no gh call"
if grep -q 'Usage' "$ERR"; then
    pass "8 argument: usage text on stderr"
else
    fail "8 argument: usage text on stderr" "stderr: $(cat "$ERR")"
fi

# --- Case 9: `a + b (est. -125)` heading --------------------------------------
run_paths lib/a.php
expect_lines "lib/a.php: plus-item"
check_out "9 plus heading: first path matches"
run_paths lib/b.php
expect_lines "lib/b.php: plus-item"
check_out "9 plus heading: second path matches"
run_paths 'est.' '125' '(est.'
check_out_empty "9 plus heading: trailing text is not a path"

# --- Case 10: comma-separated heading -----------------------------------------
run_paths lib/d.php
expect_lines "lib/d.php: comma-item"
check_out "10 comma heading: second token matches"
run_paths lib/c.php
expect_lines "lib/c.php: comma-item" "lib/c.php: item in 222"
check_out "10 comma heading: first token matches, body then comment order"

# --- Case 11: directory token -------------------------------------------------
run_paths scripts/spike-1871/x.php
expect_lines "scripts/spike-1871/x.php: dir-item"
check_out "11 directory token: matches a file under it"
run_paths scripts/spike-1871/sub/deep.php
expect_lines "scripts/spike-1871/sub/deep.php: dir-item"
check_out "11 directory token: matches a nested file"
run_paths scripts/spike-18710/x
check_out_empty "11 directory token: does not match a longer sibling name"
run_paths scripts/spike-1871/ scripts/spike-1871
check_out_empty "11 directory token: does not match the directory itself"

# --- Case 12: two input paths for one heading print the item once -------------
run_paths lib/a.php lib/b.php
expect_lines "lib/a.php: plus-item"
check_out "12 two paths, one heading: printed once with the first input path"
run_paths lib/b.php lib/a.php
expect_lines "lib/b.php: plus-item"
check_out "12 two paths, reversed: printed once with the first input path"

# --- Case 13: output form -----------------------------------------------------
run_paths app/one.php
expect_lines "app/one.php: one-open: first item"
check_out "13 output form is exactly 'path: item text'"

# --- Case 14: CRLF fixture ----------------------------------------------------
printf '### `crlf/a.php`\r\n- [ ] crlf item\r\n- [x] crlf done\r\n\r\n### `crlf/b.php`\r\n- [ ] other item\r\n' > "$FX/body.tmp"
: > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run_paths crlf/a.php
expect_lines "crlf/a.php: crlf item"
check_out "14 CRLF ledger: no trailing CR in the item"
printf 'crlf/b.php\r\n' > "$IN"
STUB_BODY="$FX/body.tmp" "$SCRIPT" < "$IN" > "$OUT" 2> "$ERR"
RC=$?
expect_lines "crlf/b.php: other item"
check_out "14 CRLF input path: CR removed, item has no trailing CR"
reset_env

# --- Case 15: items do not leak past the next heading -------------------------
cat > "$FX/body.tmp" <<'EOF'
### `leak/a.php`
- [ ] a item
### `leak/b.php`
- [ ] b item
### Heading with no backticked path
- [ ] untokened item
### `leak/c.php`
- [ ] c item
## `leak/c.php` end heading
- [ ] after end item
EOF
: > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run_paths leak/a.php
expect_lines "leak/a.php: a item"
check_out "15 no leak into the next ### or ## heading (path a)"
STUB_BODY="$FX/body.tmp" run_paths leak/b.php
expect_lines "leak/b.php: b item"
check_out "15 no leak into a heading without paths (path b)"
STUB_BODY="$FX/body.tmp" run_paths leak/c.php
expect_lines "leak/c.php: c item"
check_out "15 no leak past a ## heading, even one that names the path (path c)"
# A heading in one source must not apply to the next source.
cat > "$FX/body.tmp" <<'EOF'
### `lib/z.php`
- [ ] z item
EOF
printf '%s\n' '- [ ] orphan item' > "$FX/c-orphan.md"
echo "333 $FX/c-orphan.md" > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run_paths lib/z.php
expect_lines "lib/z.php: z item"
check_out "15 heading does not carry from the body into a comment"
reset_env

# --- Case 16: indented and non-checkbox lines ---------------------------------
cat > "$FX/body.tmp" <<'EOF'
### `fmt/a.php`
  - [ ] indented item
	- [ ] tab item
* [ ] star item
-[ ] no-space item
- [ ]no-gap item
- [] empty box
- [X] capital X
> - [ ] quoted item
plain text line
1. [ ] numbered item
- [ ] valid item
EOF
: > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run_paths fmt/a.php
expect_lines "fmt/a.php: valid item"
check_out "16 only a column-0 '- [ ] ' line is an item"
reset_env

# --- Extras: order, empty stdin, call shape, pagination, odd bodies ------------
# Body, then comments in API order.
cat > "$FX/body.tmp" <<'EOF'
### `ord/x.php`
- [ ] body item
EOF
printf '### `ord/x.php`\n- [ ] first comment item\n' > "$FX/o1.md"
printf '### `ord/x.php`\n- [ ] second comment item\n' > "$FX/o2.md"
printf '444 %s\n333 %s\n' "$FX/o1.md" "$FX/o2.md" > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run_paths ord/x.php
expect_lines "ord/x.php: body item" "ord/x.php: first comment item" "ord/x.php: second comment item"
check_out "extra: body first, then comments in API order (not id order)"

# Many comments, an empty comment body, no trailing newline, no comments.
: > "$FX/empty.md"
printf '### `pg/x.php`\n- [ ] no trailing newline' > "$FX/nonl.md"
printf '555 %s\n556 %s\n557 %s\n558 %s\n' "$FX/o1.md" "$FX/empty.md" "$FX/nonl.md" "$FX/o2.md" > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run_paths pg/x.php ord/x.php
expect_lines "ord/x.php: body item" "ord/x.php: first comment item" "pg/x.php: no trailing newline" "ord/x.php: second comment item"
check_out "extra: many comment lines, an empty comment, a comment with no final newline"
: > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run_paths ord/x.php
expect_lines "ord/x.php: body item"
check_out "extra: an issue with no comments"
: > "$FX/body.tmp"
STUB_BODY="$FX/body.tmp" run_paths ord/x.php
check_rc "extra: an empty body: exit 0" 0
check_out_empty "extra: an empty body: empty output"
reset_env

# Empty stdin, and blank lines only: exit 0 with no gh call.
: > "$STUB_LOG"
"$SCRIPT" < /dev/null > "$OUT" 2> "$ERR"
RC=$?
check_rc "extra: empty stdin: exit 0" 0
check_out_empty "extra: empty stdin: empty stdout"
check_no_gh "extra: empty stdin: no gh call"
check_err_has "extra: empty stdin: a note on stderr" "ledger-items-for-files.sh: no input paths, no query made"
printf '\n\n\r\n' > "$IN"
: > "$STUB_LOG"
"$SCRIPT" < "$IN" > "$OUT" 2> "$ERR"
RC=$?
check_rc "extra: blank lines only: exit 0" 0
check_no_gh "extra: blank lines only: no gh call"
check_err_has "extra: blank lines only: a note on stderr" "no input paths, no query made"

# The list call filters on the label and the open state.
run_paths app/one.php
if grep -q -- '--label cleanup-ledger' "$STUB_LOG" && grep -q -- '--state open' "$STUB_LOG" \
    && grep -q -- '--repo elan-registry/registry' "$STUB_LOG"; then
    pass "extra: the list call uses --label cleanup-ledger --state open"
else
    fail "extra: the list call uses --label cleanup-ledger --state open" "$(cat "$STUB_LOG")"
fi
check_rc "extra: the list filters keep exactly one issue: exit 0" 0

# The script never writes: no PATCH call.
if grep -q 'PATCH' "$STUB_LOG"; then
    fail "extra: the query script never calls PATCH" "$(cat "$STUB_LOG")"
else
    pass "extra: the query script never calls PATCH"
fi

# --- Truncated gh output: exit 2 and no output ---------------------------------
STUB_TRUNCATE=body run_paths app/one.php
check_rc "truncation: body record without END: exit 2" 2
check_out_empty "truncation: body record without END: empty stdout"
check_err_has "truncation: body record without END: stderr names it" "truncated"
STUB_TRUNCATE=comments run_paths app/one.php
check_rc "truncation: last comment record without END: exit 2" 2
check_out_empty "truncation: last comment record without END: empty stdout"
check_err_has "truncation: last comment record without END: stderr names it" "truncated"
reset_env

# --- A comment id that is not numeric: exit 2 ---------------------------------
printf '111 %s\n12x %s\n' "$FX/c111.md" "$FX/c222.md" > "$STUB_COMMENTS"
run_paths app/two.php
check_rc "comment id not numeric: exit 2" 2
check_out_empty "comment id not numeric: empty stdout"
check_err_has "comment id not numeric: stderr names it" "comment id that is not numeric: 12x"
reset_env

# --- Comment author filter ----------------------------------------------------
printf '%s\n' '### `auth/a.php`' '- [ ] outsider item' > "$FX/c-none.md"
printf '%s\n' '### `auth/a.php`' '- [ ] contributor item' > "$FX/c-contrib.md"
printf '%s\n' '### `auth/a.php`' '- [ ] owner item' > "$FX/c-owner.md"
printf '%s\n' '### `auth/a.php`' '- [ ] collaborator item' > "$FX/c-collab.md"
printf '%s\n' '### `auth/a.php`' '- [ ] member item' > "$FX/c-member.md"
printf '801 %s NONE\n802 %s CONTRIBUTOR\n803 %s OWNER\n804 %s COLLABORATOR\n805 %s MEMBER\n' \
    "$FX/c-none.md" "$FX/c-contrib.md" "$FX/c-owner.md" "$FX/c-collab.md" "$FX/c-member.md" > "$STUB_COMMENTS"
run_paths auth/a.php
expect_lines "auth/a.php: owner item" "auth/a.php: collaborator item" "auth/a.php: member item"
check_rc "author filter: exit 0" 0
check_out "author filter: NONE and CONTRIBUTOR comments are ignored, OWNER, COLLABORATOR and MEMBER are read"
reset_env

# --- Trailing whitespace is not printed ---------------------------------------
printf '### `ws/a.php`\n- [ ] spaces item   \n- [ ] tab item\t\n- [ ] crlf item \t\r\n' > "$FX/body.tmp"
: > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run_paths ws/a.php
expect_lines "ws/a.php: spaces item" "ws/a.php: tab item" "ws/a.php: crlf item"
check_out "trailing whitespace: spaces, TAB and CR are not printed"
reset_env

# --- Control characters are removed from the printed item ---------------------
printf '### `ctl/a.php`\n- [ ] red \033[31mtext\001 here\tok\177\n' > "$FX/body.tmp"
: > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run_paths ctl/a.php
printf 'ctl/a.php: red [31mtext here\tok\n' > "$EXP"
check_out "control characters: removed from stdout, TAB kept"
reset_env

echo
echo "Ran $TESTS_RUN checks, $TESTS_FAILED failed."
[ "$TESTS_FAILED" -eq 0 ]
