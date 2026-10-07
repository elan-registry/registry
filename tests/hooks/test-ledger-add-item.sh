#!/bin/bash
#
# Regression test for scripts/ledger-add-item.sh (#2314).
#
# HERMETIC: a stub `gh` is first on PATH, so no call reaches GitHub. The stub
# accepts only the exact argv of the calls that the script makes: the issue
# list, the issue view of #2208, the comments read,
# `api -X PATCH <endpoint> --input <file>`, and
# `issue comment 2208 --repo elan-registry/registry --body <body>`. The PATCH
# endpoint must be exactly repos/elan-registry/registry/issues/2208 or
# repos/elan-registry/registry/issues/comments/<digits>. Any other call
# writes `stub gh: unexpected call: ...` to stderr and exits 99. It serves
# fixture files named by env vars:
#   STUB_LIST          numbers that `gh issue list` prints, one per line
#   STUB_BODY          the issue body file
#   STUB_COMMENTS      lines of `<id> <path to comment body file> [association]`
#                      (association defaults to MEMBER)
#   STUB_FAIL          list | view | comments | patch | comment: that call
#                      exits 1
#   STUB_TRUNCATE      body | comments: that output loses its final newline,
#                      its last ` END` marker and 8 base64 characters
# The stub builds the JSON that the GitHub API returns from the fixtures, and
# runs the script's own --jq expression on it with jq. So the END marker and
# the author filter are tested, not copied. It logs each call to $STUB_LOG.
# It saves the "body" of each PATCH payload, byte for byte, to
# $STUB_PATCH_DIR/<seq>-<issue|comment>-<id>.txt, and the --body of each new
# comment to $STUB_PATCH_DIR/<seq>-newcomment-2208.txt.
#
# Payloads and output go in files and are compared with cmp or diff. Nothing
# compares them through $(...), because that drops trailing newlines.
#
# Usage: bash tests/hooks/test-ledger-add-item.sh
# Exit code: 0 if all cases pass, 1 otherwise.

# Fixture lines hold literal backticks and $, so single quotes are on purpose.
# shellcheck disable=SC2016
set -u

REPO_ROOT="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REPO_ROOT/scripts/ledger-add-item.sh"
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
# Stub gh for test-ledger-add-item.sh.
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

next_seq() { echo $(( $(find "$STUB_PATCH_DIR" -type f | wc -l | tr -d ' ') + 1 )); }

unexpected() {
    printf 'stub gh: unexpected call:' >&2
    printf ' [%s]' "${ARGV[@]}" >&2
    echo >&2
    exit 99
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
if [ "$#" -eq 7 ] && [ "$1" = issue ] && [ "$2" = comment ] && [ "$3" = 2208 ] \
        && [ "$4" = --repo ] && [ "$5" = elan-registry/registry ] && [ "$6" = --body ]; then
    [ "$fail" = comment ] && exit 1
    printf '%s' "$7" > "$(printf '%s/%02d-newcomment-2208.txt' "$STUB_PATCH_DIR" "$(next_seq)")"
    echo "https://github.com/elan-registry/registry/issues/2208#issuecomment-1"
    exit 0
fi
if [ "$#" -eq 6 ] && [ "$1" = api ] && [ "$2" = -X ] && [ "$3" = PATCH ] \
        && [ "$5" = --input ] && [ -f "$6" ]; then
    endpoint="$4"
    payload="$6"
    if [ "$endpoint" = repos/elan-registry/registry/issues/2208 ]; then
        kind=issue; id=2208
    elif [[ "$endpoint" =~ ^repos/elan-registry/registry/issues/comments/[0-9]+$ ]]; then
        kind=comment; id="${endpoint##*/}"
    else
        unexpected
    fi
    [ "$fail" = patch ] && exit 1
    jq -j .body < "$payload" > "$(printf '%s/%02d-%s-%s.txt' "$STUB_PATCH_DIR" "$(next_seq)" "$kind" "$id")" || exit 98
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

# --- Fixture ledger: a body and two comments ---------------------------------
cat > "$FX/body.default" <<'EOF'
# Cleanup ledger

## Open items

### `app/one.php`
- [ ] one-open: first item
- [x] one-ticked item

### `app/other.php`
- [ ] other item
  indented detail of the other item

## Closed

Some closing text.
EOF
cat > "$FX/c111.md" <<'EOF'
### `lib/c.php`, `lib/d.php`
- [ ] comma-item
### `lib/e.php`
- [ ] e-item
EOF
cat > "$FX/c222.md" <<'EOF'
### `scripts/spike-1871/`
- [ ] dir-item
EOF
printf '111 %s\n222 %s\n' "$FX/c111.md" "$FX/c222.md" > "$FX/comments.default"

reset_env() {
    unset STUB_FAIL STUB_TRUNCATE
    echo 2208 > "$STUB_LIST"
    cp "$FX/body.default" "$STUB_BODY"
    cp "$FX/comments.default" "$STUB_COMMENTS"
    : > "$STUB_LOG"
}

# run_add <arg>...: runs the script with these arguments.
run_add() {
    : > "$STUB_LOG"
    rm -rf "$STUB_PATCH_DIR"
    mkdir -p "$STUB_PATCH_DIR"
    "$SCRIPT" "$@" < /dev/null > "$OUT" 2> "$ERR"
    RC=$?
}

expect_lines() { printf '%s\n' "$@" > "$EXP"; }

# patch_file <seq> <issue|comment|newcomment> <id>
patch_file() { printf '%s/%02d-%s-%s.txt' "$STUB_PATCH_DIR" "$1" "$2" "$3"; }

patch_count() { find "$STUB_PATCH_DIR" -type f | wc -l | tr -d ' '; }

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

check_patches() {
    local n
    n="$(patch_count)"
    if [ "$n" -eq "$2" ]; then
        pass "$1"
    else
        fail "$1" "expected $2 writes, got $n:" "$(ls "$STUB_PATCH_DIR")"
    fi
}

# check_patch <desc> <expected file> <patch file>
check_patch() {
    if [ ! -f "$3" ]; then
        fail "$1" "no payload file: $3" "present: $(ls "$STUB_PATCH_DIR")"
    elif cmp -s "$2" "$3"; then
        pass "$1"
    else
        # od -c shows CR, TAB and trailing spaces. macOS cat has no -A.
        fail "$1" "payload differs (expected < > actual):" "$(diff "$2" "$3" | od -c)"
    fi
}

check_err_has() {
    if grep -qF -- "$2" "$ERR"; then
        pass "$1"
    else
        fail "$1" "stderr lacks: $2" "stderr: $(cat "$ERR")"
    fi
}

check_no_read() {
    if grep -qE 'issue view|api |issue comment' "$STUB_LOG"; then
        fail "$1" "gh read or wrote an issue:" "$(cat "$STUB_LOG")"
    else
        pass "$1"
    fi
}

check_no_gh() {
    if [ ! -s "$STUB_LOG" ]; then
        pass "$1"
    else
        fail "$1" "gh was called:" "$(cat "$STUB_LOG")"
    fi
}

check_log_lacks() {
    if grep -qF -- "$2" "$STUB_LOG"; then
        fail "$1" "gh log has: $2" "$(cat "$STUB_LOG")"
    else
        pass "$1"
    fi
}

reset_env

# --- Case 1: existing heading in the body; the item is the last checkbox ------
run_add "app/one.php" "new body item"
cat > "$EXP.body" <<'EOF'
# Cleanup ledger

## Open items

### `app/one.php`
- [ ] one-open: first item
- [x] one-ticked item
- [ ] new body item

### `app/other.php`
- [ ] other item
  indented detail of the other item

## Closed

Some closing text.
EOF
check_rc "1 body heading: exit 0" 0
check_patches "1 body heading: one PATCH" 1
check_patch "1 body heading: payload is the body with one line added" "$EXP.body" "$(patch_file 1 issue 2208)"
changed="$(diff "$FX/body.default" "$(patch_file 1 issue 2208)" | grep -c '^[<>]')"
if [ "$changed" -eq 1 ]; then
    pass "1 body heading: exactly one line differs"
else
    fail "1 body heading: exactly one line differs" "changed lines: $changed"
fi
expect_lines "added to #2208: app/one.php: new body item (existing heading)"
check_out "1 body heading: stdout says existing heading"
if grep -q 'PATCH repos/elan-registry/registry/issues/2208 --input' "$STUB_LOG"; then
    pass "1 body heading: PATCH goes to the issue endpoint"
else
    fail "1 body heading: PATCH goes to the issue endpoint" "$(cat "$STUB_LOG")"
fi
check_log_lacks "1 body heading: no new comment" "issue comment"

# The last group line is an indented detail: the item goes after it, and the
# group ends at the ## heading.
run_add "app/other.php" "after detail"
n="$(grep -n 'indented detail' "$FX/body.default" | cut -d: -f1)"
sed "${n}a\\
- [ ] after detail
" "$FX/body.default" > "$EXP.body"
check_patch "1 body heading: item goes after the last non-blank group line, before the ## heading" "$EXP.body" "$(patch_file 1 issue 2208)"

# --- Case 2: existing heading in a comment; only that comment is PATCHed ------
run_add "lib/d.php" "comment item"
cat > "$EXP.c" <<'EOF'
### `lib/c.php`, `lib/d.php`
- [ ] comma-item
- [ ] comment item
### `lib/e.php`
- [ ] e-item
EOF
check_rc "2 comment heading: exit 0" 0
check_patches "2 comment heading: one PATCH" 1
check_patch "2 comment heading: the item is added before the next ### heading" "$EXP.c" "$(patch_file 1 comment 111)"
if grep -q 'PATCH repos/elan-registry/registry/issues/comments/111 --input' "$STUB_LOG"; then
    pass "2 comment heading: PATCH goes to the comment endpoint"
else
    fail "2 comment heading: PATCH goes to the comment endpoint" "$(cat "$STUB_LOG")"
fi
check_log_lacks "2 comment heading: the issue body is not PATCHed" "PATCH repos/elan-registry/registry/issues/2208 "
expect_lines "added to #2208: lib/d.php: comment item (existing heading)"
check_out "2 comment heading: stdout says existing heading"
# The last group of a comment: the item goes at the end.
run_add "lib/e.php" "end item"
printf '%s\n' '### `lib/c.php`, `lib/d.php`' '- [ ] comma-item' '### `lib/e.php`' '- [ ] e-item' '- [ ] end item' > "$EXP.c"
check_patch "2 comment heading: last group of a comment, item at the end" "$EXP.c" "$(patch_file 1 comment 111)"

# --- Case 3: directory heading with a trailing / ------------------------------
run_add "scripts/spike-1871/x.php" "dir add"
printf '%s\n' '### `scripts/spike-1871/`' '- [ ] dir-item' '- [ ] dir add' > "$EXP.c"
check_rc "3 directory heading: exit 0" 0
check_patches "3 directory heading: one PATCH" 1
check_patch "3 directory heading: the comment 222 group gets the item" "$EXP.c" "$(patch_file 1 comment 222)"
expect_lines "added to #2208: scripts/spike-1871/x.php: dir add (existing heading)"
check_out "3 directory heading: stdout says existing heading"
# A similar directory name, or the directory without the slash, does not
# match, so a new heading is made.
run_add "scripts/spike-18710/x.php" "near miss"
check_patch "3 similar directory name: a new comment, not a PATCH" \
    <(printf '### `scripts/spike-18710/x.php`\n- [ ] near miss') "$(patch_file 1 newcomment 2208)"

# --- Case 4: no heading anywhere -> new comment with a new heading ------------
run_add "app/new.php" "brand new (found while working on #2314)"
printf '### `app/new.php`\n- [ ] brand new (found while working on #2314)' > "$EXP.n"
check_rc "4 no heading: exit 0" 0
check_patches "4 no heading: one write" 1
check_patch "4 no heading: the new comment has the heading and the item" "$EXP.n" "$(patch_file 1 newcomment 2208)"
check_log_lacks "4 no heading: no PATCH" "PATCH"
expect_lines "added to #2208: app/new.php: brand new (found while working on #2314) (new heading)"
check_out "4 no heading: stdout says new heading"
# The same path as an outside comment's heading: the comment is not read, so
# a new heading is made.
printf '%s\n' '### `app/outside.php`' '- [ ] x' > "$FX/c-none.md"
printf '911 %s NONE\n' "$FX/c-none.md" > "$STUB_COMMENTS"
run_add "app/outside.php" "y"
check_log_lacks "4 author filter: a NONE comment is not PATCHed" "PATCH"
check_patch "4 author filter: a new comment is made" <(printf '### `app/outside.php`\n- [ ] y') "$(patch_file 1 newcomment 2208)"
reset_env

# --- Case 5: CRLF and a missing final newline are kept ------------------------
printf '### `crlf/a.php`\r\n- [ ] one\r\n\r\n### `crlf/b.php`\r\n- [ ] other\r\n' > "$STUB_BODY"
printf '### `crlf/a.php`\r\n- [ ] one\r\n- [ ] two\r\n\r\n### `crlf/b.php`\r\n- [ ] other\r\n' > "$EXP.body"
: > "$STUB_COMMENTS"
run_add "crlf/a.php" "two"
check_rc "5 CRLF: exit 0" 0
check_patch "5 CRLF: the new line has a CRLF ending, other bytes are the same" "$EXP.body" "$(patch_file 1 issue 2208)"
printf '### `m/a.php`\n- [ ] one  \t\n- [ ] two' > "$STUB_BODY"
printf '### `m/a.php`\n- [ ] one  \t\n- [ ] two\n- [ ] three' > "$EXP.body"
run_add "m/a.php" "three"
check_patch "5 no final newline: the new last line has none either, trailing whitespace kept" "$EXP.body" "$(patch_file 1 issue 2208)"
printf '### `m/a.php`\n- [ ] one\n- [ ] two\n### `m/b.php`' > "$STUB_BODY"
printf '### `m/a.php`\n- [ ] one\n- [ ] two\n- [ ] three\n### `m/b.php`' > "$EXP.body"
run_add "m/a.php" "three"
check_patch "5 no final newline after a later heading: still none" "$EXP.body" "$(patch_file 1 issue 2208)"
# A heading with no items: the item goes right after the heading.
printf '### `e/a.php`\n\n### `e/b.php`\n' > "$STUB_BODY"
printf '### `e/a.php`\n- [ ] first\n\n### `e/b.php`\n' > "$EXP.body"
run_add "e/a.php" "first"
check_patch "5 empty group: the item goes after the heading" "$EXP.body" "$(patch_file 1 issue 2208)"
reset_env

# --- Case 6: the first matching source wins -----------------------------------
printf '%s\n' '### `s/a.php`' '- [ ] body' > "$STUB_BODY"
printf '%s\n' '### `s/a.php`' '- [ ] comment' > "$FX/cs.md"
echo "601 $FX/cs.md" > "$STUB_COMMENTS"
printf '%s\n' '### `s/a.php`' '- [ ] body' '- [ ] added' > "$EXP.body"
run_add "s/a.php" "added"
check_patches "6 two matching sources: one PATCH" 1
check_patch "6 two matching sources: the body gets the item" "$EXP.body" "$(patch_file 1 issue 2208)"
reset_env

# --- Case 7: metacharacters in item text are kept literally -------------------
PWN="$TMPROOT/pwn"
item="a.*b [x] (y)+ \\1 & \\\\ \\n %s \"q\" 'q' \$HOME \`bt\` \$(touch $PWN)"
run_add "app/one.php" "$item"
check_rc "7 metacharacters: exit 0" 0
n="$(grep -n 'one-ticked item' "$FX/body.default" | cut -d: -f1)"
{ head -n "$n" "$FX/body.default"; printf -- '- [ ] %s\n' "$item"; tail -n "+$((n + 1))" "$FX/body.default"; } > "$EXP.body"
check_patch "7 metacharacters: the item is added byte for byte" "$EXP.body" "$(patch_file 1 issue 2208)"
printf 'added to #2208: app/one.php: %s (existing heading)\n' "$item" > "$EXP"
check_out "7 metacharacters: stdout keeps the text"
run_add "app/brandnew.php" "$item"
check_patch "7 metacharacters: a new comment keeps the text" <(printf '### `app/brandnew.php`\n- [ ] %s' "$item") "$(patch_file 1 newcomment 2208)"
if [ -e "$PWN" ]; then
    fail "7 metacharacters: item text is never run as a command"
else
    pass "7 metacharacters: item text is never run as a command"
fi

# --- Case 8: exit 2 on gh failures and on a bad ledger issue count ------------
for step in list view comments patch; do
    STUB_FAIL="$step" run_add "app/one.php" "x"
    check_rc "8 gh $step failure: exit 2" 2
    check_out_empty "8 gh $step failure: no 'added:' line"
done
STUB_FAIL=comment run_add "app/none.php" "x"
check_rc "8 gh comment failure: exit 2" 2
check_out_empty "8 gh comment failure: no 'added:' line"
check_err_has "8 gh comment failure: stderr says so" "could not add a comment"
: > "$STUB_LIST"
run_add "app/one.php" "x"
check_rc "8 no open ledger issue: exit 2" 2
check_patches "8 no open ledger issue: no write" 0
check_no_read "8 no open ledger issue: no issue was read or written"
check_err_has "8 no open ledger issue: stderr says so" "no open issue has the cleanup-ledger label"
printf '2208\n2300\n' > "$STUB_LIST"
run_add "app/one.php" "x"
check_rc "8 two open ledger issues: exit 2" 2
check_patches "8 two open ledger issues: no write" 0
check_no_read "8 two open ledger issues: no issue was read or written"
reset_env
STUB_TRUNCATE=body run_add "app/one.php" "x"
check_rc "8 truncated body: exit 2" 2
check_patches "8 truncated body: no write" 0
check_err_has "8 truncated body: stderr names it" "truncated"
STUB_TRUNCATE=comments run_add "app/none.php" "x"
check_rc "8 truncated comments: exit 2" 2
check_log_lacks "8 truncated comments: no new comment" "issue comment"
reset_env

# --- Case 9: usage errors -> exit 1, no gh call --------------------------------
for args in "none" "one" "three"; do
    case "$args" in
        none) run_add ;;
        one) run_add "app/one.php" ;;
        three) run_add "app/one.php" "x" "y" ;;
    esac
    check_rc "9 $args argument(s): exit 1" 1
    check_out_empty "9 $args argument(s): empty stdout"
    check_no_gh "9 $args argument(s): no gh call"
    check_err_has "9 $args argument(s): usage text on stderr" "Usage"
done
run_add "" "x"
check_rc "9 empty path: exit 1" 1
run_add "app/one.php" ""
check_rc "9 empty item: exit 1" 1
run_add "app/one.php" $'line one\n### `app/evil.php`'
check_rc "9 newline in item: exit 1" 1
check_no_gh "9 newline in item: no gh call"
run_add "app/one.php" $'cr\r'
check_rc "9 CR in item: exit 1" 1
run_add 'app/`x`.php' "x"
check_rc "9 backtick in path: exit 1" 1
check_no_gh "9 backtick in path: no gh call"

echo
echo "Ran $TESTS_RUN checks, $TESTS_FAILED failed."
[ "$TESTS_FAILED" -eq 0 ]
