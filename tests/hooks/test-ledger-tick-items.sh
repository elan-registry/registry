#!/bin/bash
#
# Regression test for scripts/ledger-tick-items.sh (#2271).
#
# HERMETIC: a stub `gh` is first on PATH, so no call reaches GitHub. The stub
# accepts only the exact argv of the calls that the script makes: the issue
# list, the issue view of #2208, the comments read, and
# `api -X PATCH <endpoint> --input <file>`. The endpoint must be exactly
# repos/elan-registry/registry/issues/2208 or
# repos/elan-registry/registry/issues/comments/<digits>. Any other call
# writes `stub gh: unexpected call: ...` to stderr and exits 99. It serves
# fixture files named by env vars:
#   STUB_LIST          numbers that `gh issue list` prints, one per line
#   STUB_BODY          the issue body file
#   STUB_COMMENTS      lines of `<id> <path to comment body file> [association]`
#                      (association defaults to MEMBER)
#   STUB_FAIL          list | view | comments | patch: that call exits 1
#   STUB_FAIL_PATCH_ID endpoint tail (for example issues/2208) whose PATCH
#                      exits 1, while other PATCH calls work
#   STUB_TRUNCATE      body | comments: that output loses its final newline,
#                      its last ` END` marker and 8 base64 characters
# The stub builds the JSON that the GitHub API returns from the fixtures, and
# runs the script's own --jq expression on it with jq. So the END marker and
# the author filter are tested, not copied. It logs each call to $STUB_LOG.
# It saves the "body" of each PATCH payload, byte for byte, to
# $STUB_PATCH_DIR/<seq>-<issue|comment>-<id>.txt.
#
# Payloads and output go in files and are compared with cmp or diff. Nothing
# compares them through $(...), because that drops trailing newlines.
#
# Usage: bash tests/hooks/test-ledger-tick-items.sh
# Exit code: 0 if all cases pass, 1 otherwise.

# Fixture lines hold literal backticks and $, so single quotes are on purpose.
# shellcheck disable=SC2016
set -u

REPO_ROOT="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REPO_ROOT/scripts/ledger-tick-items.sh"
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
# Stub gh for test-ledger-tick-items.sh.
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
    [ "$endpoint" = "repos/elan-registry/registry/${STUB_FAIL_PATCH_ID:-@@none@@}" ] && exit 1
    seq=$(( $(find "$STUB_PATCH_DIR" -type f | wc -l | tr -d ' ') + 1 ))
    jq -j .body < "$payload" > "$(printf '%s/%02d-%s-%s.txt' "$STUB_PATCH_DIR" "$seq" "$kind" "$id")" || exit 98
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

# --- Fixture ledger: a body and two comments, all four heading forms ---------
cat > "$FX/body.default" <<'EOF'
# Cleanup ledger

## Open items

### `app/one.php`
- [ ] one-open: first item
- [x] one-ticked item
- [ ] shared text

### `app/other.php`
- [ ] shared text

### `lib/a.php` + `lib/b.php` (est. −125)
- [ ] plus-item
EOF
cat > "$FX/c111.md" <<'EOF'
### `lib/c.php`, `lib/d.php`
- [ ] comma-item
- [ ] comma-item two
EOF
cat > "$FX/c222.md" <<'EOF'
### `scripts/spike-1871/`
- [ ] dir-item
EOF
printf '111 %s\n222 %s\n' "$FX/c111.md" "$FX/c222.md" > "$FX/comments.default"

reset_env() {
    unset STUB_FAIL STUB_FAIL_PATCH_ID STUB_TRUNCATE
    echo 2208 > "$STUB_LIST"
    cp "$FX/body.default" "$STUB_BODY"
    cp "$FX/comments.default" "$STUB_COMMENTS"
    : > "$STUB_LOG"
}

# run_lines <line>...: sends one request line per argument on stdin.
run_lines() {
    : > "$STUB_LOG"
    rm -rf "$STUB_PATCH_DIR"
    mkdir -p "$STUB_PATCH_DIR"
    printf '%s\n' "$@" > "$IN"
    "$SCRIPT" < "$IN" > "$OUT" 2> "$ERR"
    RC=$?
}

# run_input: sends the file $IN as it is.
run_input() {
    : > "$STUB_LOG"
    rm -rf "$STUB_PATCH_DIR"
    mkdir -p "$STUB_PATCH_DIR"
    "$SCRIPT" < "$IN" > "$OUT" 2> "$ERR"
    RC=$?
}

expect_lines() { printf '%s\n' "$@" > "$EXP"; }

# patch_file <seq> <issue|comment> <id>
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
        fail "$1" "expected $2 PATCH payloads, got $n:" "$(ls "$STUB_PATCH_DIR")"
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
    if grep -qE 'issue view|api ' "$STUB_LOG"; then
        fail "$1" "gh read an issue:" "$(cat "$STUB_LOG")"
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

reset_env

# --- Case 1: tick in the body; the payload differs by one [ ] -> [x] ----------
run_lines "app/one.php: one-open: first item"
sed 's/^- \[ \] one-open: first item$/- [x] one-open: first item/' "$FX/body.default" > "$EXP.body"
check_rc "1 tick in the body: exit 0" 0
check_patches "1 tick in the body: one PATCH" 1
check_patch "1 tick in the body: payload is the body with one line ticked" "$EXP.body" "$(patch_file 1 issue 2208)"
changed="$(diff "$FX/body.default" "$(patch_file 1 issue 2208)" | grep -c '^<')"
if [ "$changed" -eq 1 ]; then
    pass "1 tick in the body: exactly one line differs"
else
    fail "1 tick in the body: exactly one line differs" "changed lines: $changed"
fi
expect_lines "ticked: app/one.php: one-open: first item"
check_out "1 tick in the body: stdout reports the tick, split at the first ': ' only"
if grep -q 'PATCH repos/elan-registry/registry/issues/2208 --input' "$STUB_LOG"; then
    pass "1 tick in the body: PATCH goes to the issue endpoint"
else
    fail "1 tick in the body: PATCH goes to the issue endpoint" "$(cat "$STUB_LOG")"
fi

# --- Case 2: tick in a comment; only that comment is PATCHed ------------------
run_lines "lib/d.php: comma-item two"
sed 's/^- \[ \] comma-item two$/- [x] comma-item two/' "$FX/c111.md" > "$EXP.c"
check_rc "2 tick in a comment: exit 0" 0
check_patches "2 tick in a comment: one PATCH" 1
check_patch "2 tick in a comment: payload is the comment with one line ticked" "$EXP.c" "$(patch_file 1 comment 111)"
expect_lines "ticked: lib/d.php: comma-item two"
check_out "2 tick in a comment: stdout reports the tick"
if grep -q 'PATCH repos/elan-registry/registry/issues/comments/111 --input' "$STUB_LOG"; then
    pass "2 tick in a comment: PATCH goes to the comment endpoint"
else
    fail "2 tick in a comment: PATCH goes to the comment endpoint" "$(cat "$STUB_LOG")"
fi

# --- Case 3: no PATCH for a source with no change -----------------------------
run_lines "scripts/spike-1871/x.php: dir-item"
check_patches "3 change in the last comment only: one PATCH, body and comment 111 are not sent" 1
if [ -f "$(patch_file 1 comment 222)" ]; then
    pass "3 change in the last comment only: the PATCH is for comment 222"
else
    fail "3 change in the last comment only: the PATCH is for comment 222" "$(ls "$STUB_PATCH_DIR")"
fi
run_lines "nothing/here.php: no such item"
check_patches "3 no match anywhere: no PATCH" 0
check_rc "3 no match anywhere: exit 0" 0

# --- All four heading forms, three sources, one run ---------------------------
run_lines "lib/a.php: plus-item" "lib/d.php: comma-item" "scripts/spike-1871/x.php: dir-item"
sed 's/^- \[ \] plus-item$/- [x] plus-item/' "$FX/body.default" > "$EXP.body"
sed 's/^- \[ \] comma-item$/- [x] comma-item/' "$FX/c111.md" > "$EXP.c1"
sed 's/^- \[ \] dir-item$/- [x] dir-item/' "$FX/c222.md" > "$EXP.c2"
check_patches "heading forms: three sources, three PATCH calls" 3
check_patch "heading forms: body (a + b heading)" "$EXP.body" "$(patch_file 1 issue 2208)"
check_patch "heading forms: comment 111 (comma heading)" "$EXP.c1" "$(patch_file 2 comment 111)"
check_patch "heading forms: comment 222 (directory heading)" "$EXP.c2" "$(patch_file 3 comment 222)"
expect_lines "ticked: lib/a.php: plus-item" "ticked: lib/d.php: comma-item" "ticked: scripts/spike-1871/x.php: dir-item"
check_out "heading forms: one ticked line per item, in source order"
check_rc "heading forms: exit 0" 0

# --- Case 4: CRLF ledger comes back byte-identical except for the tick --------
printf '### `crlf/a.php`\r\n- [ ] crlf item\r\n- [ ] keep open\r\n\r\n### `crlf/b.php`\r\n- [ ] other\r\n' > "$STUB_BODY"
printf '### `crlf/a.php`\r\n- [x] crlf item\r\n- [ ] keep open\r\n\r\n### `crlf/b.php`\r\n- [ ] other\r\n' > "$EXP.body"
: > "$STUB_COMMENTS"
printf 'crlf/a.php: crlf item\r\n' > "$IN"
run_input
check_rc "4 CRLF ledger and CRLF request: exit 0" 0
check_patch "4 CRLF ledger: same bytes except the ticked line" "$EXP.body" "$(patch_file 1 issue 2208)"
expect_lines "ticked: crlf/a.php: crlf item"
check_out "4 CRLF request: no CR in the ticked line"
# Mixed line endings and no final newline.
printf '### `m/a.php`\n- [ ] one\r\n- [ ] two' > "$STUB_BODY"
printf '### `m/a.php`\n- [ ] one\r\n- [x] two' > "$EXP.body"
run_lines "m/a.php: two"
check_patch "4 mixed endings, no final newline: same bytes except the tick" "$EXP.body" "$(patch_file 1 issue 2208)"
printf '### `m/a.php`\n- [ ] one\r\n- [ ] two' > "$STUB_BODY"
printf '### `m/a.php`\n- [x] one\r\n- [ ] two' > "$EXP.body"
run_lines "m/a.php: one"
check_patch "4 mixed endings, no final newline: a tick on an earlier line keeps the missing final newline" "$EXP.body" "$(patch_file 1 issue 2208)"
reset_env

# --- Case 5: the same text under the wrong heading is not ticked --------------
run_lines "app/other.php: one-open: first item"
check_patches "5 wrong heading: no PATCH" 0
check_rc "5 wrong heading: exit 0" 0
check_out_empty "5 wrong heading: no ticked line"
check_err_has "5 wrong heading: warning" "nothing ticked"
# Text under two headings: only the requested heading is ticked.
run_lines "app/other.php: shared text"
n="$(grep -n 'shared text' "$FX/body.default" | tail -1 | cut -d: -f1)"
sed "${n}s/^- \[ \]/- [x]/" "$FX/body.default" > "$EXP.body"
check_patch "5 same text under two headings: only the requested heading is ticked" "$EXP.body" "$(patch_file 1 issue 2208)"
run_lines "app/one.php: shared text"
n="$(grep -n 'shared text' "$FX/body.default" | head -1 | cut -d: -f1)"
sed "${n}s/^- \[ \]/- [x]/" "$FX/body.default" > "$EXP.body"
check_patch "5 same text under two headings: the other heading is ticked on request" "$EXP.body" "$(patch_file 1 issue 2208)"
# Paths match as exact strings, and a directory token needs the slash.
run_lines "lib/aXphp: plus-item" "scripts/spike-18710/x.php: dir-item" "scripts/spike-1871: dir-item"
check_patches "5 path 'lib/aXphp' and a similar directory name do not match" 0

# --- Case 6: a partial text is not ticked, with a warning ---------------------
run_lines "app/one.php: one-open"
check_patches "6 partial text (shorter than the item): no PATCH" 0
check_err_has "6 partial text: warning on stderr" "nothing ticked: app/one.php: one-open"
check_rc "6 partial text: exit 0" 0
run_lines "app/one.php: one-open: first item and more"
check_patches "6 longer text than the item: no PATCH" 0
check_err_has "6 longer text: warning on stderr" "nothing ticked"

# --- Case 7: no match -> warning, nothing ticked, exit 0 ----------------------
run_lines "nothing/here.php: absent"
check_rc "7 no match: exit 0" 0
check_out_empty "7 no match: empty stdout"
check_patches "7 no match: no PATCH" 0
check_err_has "7 no match: warning names the path and item" "nothing ticked: nothing/here.php: absent"

# --- Case 8: duplicate text under one heading ---------------------------------
printf '%s\n' '### `d/a.php`' '- [ ] same text' '- [ ] same text' > "$STUB_BODY"
printf '%s\n' '### `d/a.php`' '- [x] same text' '- [ ] same text' > "$EXP.body"
: > "$STUB_COMMENTS"
run_lines "d/a.php: same text"
check_patch "8 duplicate text: the first open line is ticked, the second stays open" "$EXP.body" "$(patch_file 1 issue 2208)"
check_err_has "8 duplicate text: warning on stderr" "second open line"
check_rc "8 duplicate text: exit 0" 0
# The same request twice in the input ticks one line only.
run_lines "d/a.php: same text" "d/a.php: same text"
check_patch "8 repeated request: the repeat is dropped, one line ticked" "$EXP.body" "$(patch_file 1 issue 2208)"
check_patches "8 repeated request: one PATCH" 1
expect_lines "ticked: d/a.php: same text"
check_out "8 repeated request: one ticked line"
reset_env

# --- A request ticks one line across all sources ------------------------------
printf '%s\n' '### `s/a.php`' '- [ ] twice' > "$STUB_BODY"
printf '%s\n' '### `s/a.php`' '- [ ] twice' > "$FX/cs.md"
echo "601 $FX/cs.md" > "$STUB_COMMENTS"
printf '%s\n' '### `s/a.php`' '- [x] twice' > "$EXP.body"
run_lines "s/a.php: twice"
check_patches "multi-source: a request ticks only the first match" 1
check_patch "multi-source: the body line is ticked" "$EXP.body" "$(patch_file 1 issue 2208)"
reset_env

# --- Case 9: an already-ticked line is not matched ----------------------------
printf '%s\n' '### `t/a.php`' '- [x] done item' > "$STUB_BODY"
: > "$STUB_COMMENTS"
run_lines "t/a.php: done item"
check_patches "9 ticked line only: no PATCH" 0
check_err_has "9 ticked line only: warning" "nothing ticked"
printf '%s\n' '### `t/a.php`' '- [x] done item' '- [ ] done item' > "$STUB_BODY"
printf '%s\n' '### `t/a.php`' '- [x] done item' '- [x] done item' > "$EXP.body"
run_lines "t/a.php: done item"
check_patch "9 ticked line before an open line: the open line is ticked" "$EXP.body" "$(patch_file 1 issue 2208)"
reset_env

# --- Case 10: exit 2 on gh failures and on a bad ledger issue count -----------
for step in list view comments patch; do
    STUB_FAIL="$step" run_lines "app/one.php: one-open: first item"
    check_rc "10 gh $step failure: exit 2" 2
    check_out_empty "10 gh $step failure: no 'ticked:' line"
done
: > "$STUB_LIST"
run_lines "app/one.php: one-open: first item"
check_rc "10 no open ledger issue: exit 2" 2
check_patches "10 no open ledger issue: no PATCH" 0
check_no_read "10 no open ledger issue: no issue was read"
printf '2208\n2300\n' > "$STUB_LIST"
run_lines "app/one.php: one-open: first item"
check_rc "10 two open ledger issues: exit 2" 2
check_patches "10 two open ledger issues: no PATCH" 0
check_no_read "10 two open ledger issues: no issue was read"
reset_env
# A failed PATCH does not stop the other sources.
STUB_FAIL_PATCH_ID="issues/2208" run_lines "lib/a.php: plus-item" "lib/d.php: comma-item"
sed 's/^- \[ \] comma-item$/- [x] comma-item/' "$FX/c111.md" > "$EXP.c1"
check_rc "10 PATCH of the body fails, comment PATCH works: exit 2" 2
check_patches "10 PATCH of the body fails: the comment is still PATCHed" 1
check_patch "10 PATCH of the body fails: comment payload is right" "$EXP.c1" "$(patch_file 1 comment 111)"
expect_lines "ticked: lib/d.php: comma-item"
check_out "10 PATCH of the body fails: 'ticked:' only for the PATCH that worked"
check_err_has "10 PATCH of the body fails: stderr names the endpoint" "PATCH of repos/elan-registry/registry/issues/2208 failed"
check_err_has "10 PATCH of the body fails: its item is reported as not ticked" "not ticked: lib/a.php: plus-item"
if grep -qF "not ticked: lib/d.php: comma-item" "$ERR"; then
    fail "10 PATCH of the body fails: the comment item is not reported as not ticked" "stderr: $(cat "$ERR")"
else
    pass "10 PATCH of the body fails: the comment item is not reported as not ticked"
fi
reset_env

# --- Case 11: usage errors -> exit 1 -------------------------------------------
: > "$STUB_LOG"
"$SCRIPT" "app/one.php: x" < /dev/null > "$OUT" 2> "$ERR"
RC=$?
check_rc "11 argument: exit 1" 1
check_out_empty "11 argument: empty stdout"
check_no_gh "11 argument: no gh call"
check_err_has "11 argument: usage text on stderr" "Usage"

# --- Case 12: two items in one source give one PATCH --------------------------
run_lines "app/one.php: one-open: first item" "app/one.php: shared text"
n="$(grep -n 'shared text' "$FX/body.default" | head -1 | cut -d: -f1)"
sed -e 's/^- \[ \] one-open: first item$/- [x] one-open: first item/' \
    -e "${n}s/^- \[ \]/- [x]/" "$FX/body.default" > "$EXP.body"
check_patches "12 two items, one source: one PATCH" 1
check_patch "12 two items, one source: both ticked in that payload" "$EXP.body" "$(patch_file 1 issue 2208)"
expect_lines "ticked: app/one.php: one-open: first item" "ticked: app/one.php: shared text"
check_out "12 two items, one source: two ticked lines"

# --- Case 13: metacharacters and ': ' in item text match literally ------------
cat > "$FX/meta.md" <<'EOF'
### `lib/m.php`
- [ ] aXXb
- [ ] a.*b
- [ ] [x] (y)+ ? {2} ^$ | \1 & \\ \n end
- [ ] cost $HOME and `backticks` and $(touch @PWN@)
- [ ] key: value: more
- [ ] 100%s %d "quoted" 'single'
EOF
cat > "$FX/meta-exp.md" <<'EOF'
### `lib/m.php`
- [ ] aXXb
- [x] a.*b
- [x] [x] (y)+ ? {2} ^$ | \1 & \\ \n end
- [x] cost $HOME and `backticks` and $(touch @PWN@)
- [x] key: value: more
- [x] 100%s %d "quoted" 'single'
EOF
# The marker file sits in the test temp dir, so the check can fail if the
# script ever runs item text as a command.
PWN="$TMPROOT/pwn"
sed "s|@PWN@|$PWN|g" "$FX/meta.md" > "$STUB_BODY"
sed "s|@PWN@|$PWN|g" "$FX/meta-exp.md" > "$EXP.body"
: > "$STUB_COMMENTS"
run_lines 'lib/m.php: a.*b' \
    'lib/m.php: [x] (y)+ ? {2} ^$ | \1 & \\ \n end' \
    "lib/m.php: cost \$HOME and \`backticks\` and \$(touch $PWN)" \
    'lib/m.php: key: value: more' \
    "lib/m.php: 100%s %d \"quoted\" 'single'"
check_rc "13 metacharacters: exit 0" 0
check_patch "13 metacharacters: only the literal lines are ticked, decoy 'aXXb' stays open" "$EXP.body" "$(patch_file 1 issue 2208)"
cat > "$FX/meta-out.txt" <<'EOF'
ticked: lib/m.php: a.*b
ticked: lib/m.php: [x] (y)+ ? {2} ^$ | \1 & \\ \n end
ticked: lib/m.php: cost $HOME and `backticks` and $(touch @PWN@)
ticked: lib/m.php: key: value: more
ticked: lib/m.php: 100%s %d "quoted" 'single'
EOF
sed "s|@PWN@|$PWN|g" "$FX/meta-out.txt" > "$EXP"
check_out "13 metacharacters: ticked lines keep the text byte for byte"
if [ -e "$PWN" ]; then
    fail "13 metacharacters: item text is never run as a command"
else
    pass "13 metacharacters: item text is never run as a command"
fi
reset_env

# --- Case 14: a leading '- ' and 'none' lines ---------------------------------
run_lines "- app/one.php: one-open: first item" "none" "none (no ledger items)" "" "- none"
sed 's/^- \[ \] one-open: first item$/- [x] one-open: first item/' "$FX/body.default" > "$EXP.body"
check_rc "14 bullet, none and blank lines: exit 0" 0
check_patch "14 bullet prefix removed: the item is ticked" "$EXP.body" "$(patch_file 1 issue 2208)"
expect_lines "ticked: app/one.php: one-open: first item"
check_out "14 none lines and blank lines give no ticked line and no stdout noise"
if grep -qi 'warning' "$ERR"; then
    fail "14 none lines and blank lines give no warning" "stderr: $(cat "$ERR")"
else
    pass "14 none lines and blank lines give no warning"
fi
run_lines "none" "" "none (the PR touches no ledger file)" "- none"
check_rc "14 only none lines: exit 0" 0
check_no_gh "14 only none lines: no gh call"
check_err_has "14 only none lines: a note on stderr" "no requests, no query made"

# --- Trailing whitespace and a backticked path --------------------------------
# A model types the request into the PR body, so the PR body can lose or add
# trailing whitespace, or put the path in backticks.
printf '### `ws/a.php`\n- [ ] trailing item  \t\n- [ ] crlf trailing \r\n- [ ] plain item\n' > "$STUB_BODY"
printf '### `ws/a.php`\n- [x] trailing item  \t\n- [x] crlf trailing \r\n- [ ] plain item\n' > "$EXP.body"
: > "$STUB_COMMENTS"
run_lines "ws/a.php: trailing item" "ws/a.php: crlf trailing"
check_rc "whitespace: ledger item with trailing whitespace: exit 0" 0
check_patch "whitespace: a request without the trailing whitespace ticks it, and the payload keeps it" "$EXP.body" "$(patch_file 1 issue 2208)"
expect_lines "ticked: ws/a.php: trailing item" "ticked: ws/a.php: crlf trailing"
check_out "whitespace: ticked lines have no trailing whitespace"
printf '### `ws/a.php`\n- [ ] trailing item  \t\n- [ ] crlf trailing \r\n- [x] plain item\n' > "$EXP.body"
printf 'ws/a.php: plain item  \t\r\n' > "$IN"
run_input
check_rc "whitespace: request with trailing spaces and TAB: exit 0" 0
check_patch "whitespace: request with trailing spaces and TAB ticks the item" "$EXP.body" "$(patch_file 1 issue 2208)"
expect_lines "ticked: ws/a.php: plain item"
check_out "whitespace: request with trailing spaces: ticked line has none"
run_lines '- `ws/a.php`: plain item'
check_rc "backticks: backticked path: exit 0" 0
check_patch "backticks: backticked path ticks the item" "$EXP.body" "$(patch_file 1 issue 2208)"
expect_lines "ticked: ws/a.php: plain item"
check_out "backticks: ticked line has no backticks"
run_lines '`ws/a.php: plain item' 'ws/a.php`: plain item' '``: plain item'
check_patches "backticks: one backtick only, or an empty pair, ticks nothing" 0
check_err_has "backticks: one backtick only gives a warning" 'nothing ticked: `ws/a.php: plain item'
reset_env

# --- Extras ---------------------------------------------------------------------
# Empty stdin: exit 0, no gh call.
: > "$STUB_LOG"
"$SCRIPT" < /dev/null > "$OUT" 2> "$ERR"
RC=$?
check_rc "extra: empty stdin: exit 0" 0
check_no_gh "extra: empty stdin: no gh call"
check_err_has "extra: empty stdin: a note on stderr" "ledger-tick-items.sh: no requests, no query made"

# A line without ': ' is skipped with a warning. Other lines still work.
run_lines "app/one.php:no-space" "justapath:" "app/one.php: shared text"
n="$(grep -n 'shared text' "$FX/body.default" | head -1 | cut -d: -f1)"
sed "${n}s/^- \[ \]/- [x]/" "$FX/body.default" > "$EXP.body"
check_rc "extra: lines without ': ': exit 0" 0
check_err_has "extra: a line without ': ' gives a warning" "not a \"path: item text\" line, skipped: app/one.php:no-space"
check_err_has "extra: a bare 'path:' line gives a warning" "skipped: justapath:"
check_patch "extra: a valid line next to bad lines is still ticked" "$EXP.body" "$(patch_file 1 issue 2208)"

# The list call uses the label and open state, and PATCH uses --input.
if grep -q -- '--label cleanup-ledger' "$STUB_LOG" && grep -q -- '--state open' "$STUB_LOG" \
    && grep -q -- '--repo elan-registry/registry' "$STUB_LOG"; then
    pass "extra: the list call uses --label cleanup-ledger --state open"
else
    fail "extra: the list call uses --label cleanup-ledger --state open" "$(cat "$STUB_LOG")"
fi

# Many comment lines (as --paginate prints them), an empty body, and a tick
# in the third comment: only that comment id is PATCHed.
: > "$FX/empty.md"
printf '%s\n' '### `pg/x.php`' '- [ ] p1' > "$FX/p1.md"
printf '%s\n' '### `pg/x.php`' '- [ ] p3' > "$FX/p3.md"
printf '%s\n' '### `pg/x.php`' '- [x] p3' > "$EXP.c"
printf '701 %s\n702 %s\n703 %s\n704 %s\n' "$FX/p1.md" "$FX/empty.md" "$FX/p3.md" "$FX/p1.md" > "$STUB_COMMENTS"
run_lines "pg/x.php: p3"
check_patches "extra: many comments with an empty one: one PATCH" 1
check_patch "extra: the PATCH goes to the third comment id" "$EXP.c" "$(patch_file 1 comment 703)"
reset_env

# --- Section ends: a ## heading, and a ### heading with no path -------------
cat > "$STUB_BODY" <<'EOF'
### `h/a.php`
- [ ] a open
## Other heading
- [ ] after-h2 item
### `h/b.php`
- [ ] b open
### Heading with no backticked path
- [ ] untokened item
EOF
: > "$STUB_COMMENTS"
run_lines "h/a.php: after-h2 item" "h/b.php: untokened item"
check_rc "section end: exit 0" 0
check_patches "section end: no PATCH" 0
check_out_empty "section end: no ticked line"
check_err_has "section end: the item under a ## heading gets a warning" "nothing ticked: h/a.php: after-h2 item"
check_err_has "section end: the item under a ### heading with no path gets a warning" "nothing ticked: h/b.php: untokened item"
reset_env

# --- Truncated gh output: exit 2 and no PATCH ----------------------------------
# The item sits at the top, so a short body would still match and be PATCHed.
{
    printf '%s\n' '### `tr/a.php`' '- [ ] top item'
    for i in 1 2 3 4 5 6 7 8; do printf 'filler line %s keeps the ledger text\n' "$i"; done
} > "$STUB_BODY"
: > "$STUB_COMMENTS"
STUB_TRUNCATE=body run_lines "tr/a.php: top item"
check_rc "truncation: body record without END: exit 2" 2
check_patches "truncation: body record without END: no PATCH" 0
check_out_empty "truncation: body record without END: no ticked line"
check_err_has "truncation: body record without END: stderr names it" "truncated"
printf '901 %s\n' "$STUB_BODY" > "$STUB_COMMENTS"
printf '%s\n' '### `x/none.php`' > "$FX/tr-body.md"
STUB_BODY="$FX/tr-body.md" STUB_TRUNCATE=comments run_lines "tr/a.php: top item"
check_rc "truncation: comment record without END: exit 2" 2
check_patches "truncation: comment record without END: no PATCH" 0
check_err_has "truncation: comment record without END: stderr names it" "truncated"
reset_env

# --- A comment id that is not numeric: exit 2 and no PATCH ---------------------
printf '12x %s\n' "$FX/c111.md" > "$STUB_COMMENTS"
run_lines "lib/d.php: comma-item"
check_rc "comment id not numeric: exit 2" 2
check_patches "comment id not numeric: no PATCH" 0
check_err_has "comment id not numeric: stderr names it" "comment id that is not numeric: 12x"
reset_env

# --- Comment author filter: outside comments are not ticked --------------------
printf '%s\n' '### `app/one.php`' '- [ ] outsider item' > "$FX/c-none.md"
printf '%s\n' '### `app/one.php`' '- [ ] contributor item' > "$FX/c-contrib.md"
printf '911 %s NONE\n912 %s CONTRIBUTOR\n' "$FX/c-none.md" "$FX/c-contrib.md" > "$STUB_COMMENTS"
run_lines "app/one.php: outsider item" "app/one.php: contributor item"
check_rc "author filter: exit 0" 0
check_patches "author filter: no PATCH of a NONE or CONTRIBUTOR comment" 0
check_out_empty "author filter: no ticked line"
check_err_has "author filter: NONE comment item gets a warning" "nothing ticked: app/one.php: outsider item"
check_err_has "author filter: CONTRIBUTOR comment item gets a warning" "nothing ticked: app/one.php: contributor item"
reset_env

# --- Control characters: removed from stdout, kept in the PATCH ----------------
printf '### `ctl/a.php`\n- [ ] red \033[31mtext\001 here\tok\n' > "$STUB_BODY"
printf '### `ctl/a.php`\n- [x] red \033[31mtext\001 here\tok\n' > "$EXP.body"
: > "$STUB_COMMENTS"
printf 'ctl/a.php: red \033[31mtext\001 here\tok\n' > "$IN"
run_input
check_rc "control characters: exit 0" 0
check_patch "control characters: the PATCH keeps the original bytes" "$EXP.body" "$(patch_file 1 issue 2208)"
printf 'ticked: ctl/a.php: red [31mtext here\tok\n' > "$EXP"
check_out "control characters: removed from stdout, TAB kept"
printf 'ctl/a.php: absent \033]0;title\007 item\n' > "$IN"
run_input
check_err_has "control characters: removed from a warning" "nothing ticked: ctl/a.php: absent ]0;title item"
reset_env

echo
echo "Ran $TESTS_RUN checks, $TESTS_FAILED failed."
[ "$TESTS_FAILED" -eq 0 ]
