#!/bin/bash
#
# Regression test for scripts/ledger-orphans.sh (#2316).
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
# runs the script's own --jq expression on it with jq. It logs each call to
# $STUB_LOG.
#
# The script runs with its working directory in a temporary git repository.
# Its HEAD commit holds the paths that "exist". The working tree differs from
# HEAD on purpose, to show that only HEAD counts.
#
# Expected output goes in files and is compared with cmp.
#
# Usage: bash tests/hooks/test-ledger-orphans.sh
# Exit code: 0 if all cases pass, 1 otherwise.

# Fixture lines hold literal backticks and $, so single quotes are on purpose.
# shellcheck disable=SC2016
set -u

REPO_ROOT="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REPO_ROOT/scripts/ledger-orphans.sh"
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
# Stub gh for test-ledger-orphans.sh.
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

# --- Fixture repository -------------------------------------------------------
# HEAD has these files. After the commit, keep/deleted.php is removed from
# the working tree and untracked.php is created, but neither is committed.
WORK="$TMPROOT/repo"
mkdir -p "$WORK"
git -C "$WORK" init -q
git -C "$WORK" config user.email test@example.invalid
git -C "$WORK" config user.name test
git -C "$WORK" config commit.gpgsign false
mkdir -p "$WORK/keep/dir" "$WORK/deep/x/y" "$WORK/sp ace"
for f in keep/a.php keep/dir/b.php keep/deleted.php deep/x/y/z.php 'sp ace/f.php' 'keep/ü.php'; do
    echo x > "$WORK/$f"
done
git -C "$WORK" add -A
git -C "$WORK" commit -q -m fixture
rm "$WORK/keep/deleted.php"
echo x > "$WORK/untracked.php"

# --- Fixture ledger -----------------------------------------------------------
cat > "$FX/body.md" <<'EOF'
# Cleanup ledger

- [ ] preamble item before any heading

## Open items

### `keep/a.php`
- [ ] exists item

### `gone/a.php` (est. −10)
- [ ] gone item: with a colon
- [x] gone ticked item
  - [ ] indented item
* [ ] star item

### `gone/b.php` + `keep/a.php`
- [ ] mixed item

### `gone/c.php`, `gone/d.php`
- [ ] all gone item

### `keep/dir/`
- [ ] dir exists item

### `gone/dir/`
- [ ] dir gone item

### `deep/x`
- [ ] directory named without a slash item

### `deep/x/y/`
- [ ] nested dir item

### `keep/a.php/`
- [ ] slash on a file item

### `kee`
- [ ] prefix of a directory item

### `keep/dir/b`
- [ ] prefix of a file item

### `keep/deleted.php`
- [ ] deleted only in the working tree item

### `untracked.php`
- [ ] untracked item

### `sp ace/f.php` + `keep/ü.php`
- [ ] special characters item

### `keep/ü.php`
- [ ] non-ASCII item

### Heading with no backticked path
- [ ] untokened item

## `gone/z.php` end heading
- [ ] after end item
EOF
cat > "$FX/c111.md" <<'EOF'
- [ ] item before any heading in a comment
### `gone/e.php`
- [ ] comment orphan item
EOF
printf '111 %s\n' "$FX/c111.md" > "$FX/comments.default"

reset_env() {
    unset STUB_FAIL STUB_TRUNCATE
    echo 2208 > "$STUB_LIST"
    cp "$FX/comments.default" "$STUB_COMMENTS"
    : > "$STUB_LOG"
}

# run [arg...]: runs the script in the fixture repository.
run() {
    : > "$STUB_LOG"
    (cd "$WORK" && "$SCRIPT" "$@" < /dev/null > "$OUT" 2> "$ERR")
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

# --- Case 1: the full fixture -------------------------------------------------
# Not orphans: an existing file, a heading with one existing token, an
# existing directory token (direct and nested), a directory named without
# a slash, a file deleted only in the working tree, paths with a space and
# non-ASCII characters, a heading with no token, items outside a heading.
run
expect_lines \
    "gone/a.php: gone item: with a colon" \
    "gone/c.php: all gone item" \
    "gone/dir/: dir gone item" \
    "keep/a.php/: slash on a file item" \
    "kee: prefix of a directory item" \
    "keep/dir/b: prefix of a file item" \
    "untracked.php: untracked item" \
    "gone/e.php: comment orphan item"
check_rc "1 full fixture: exit 0" 0
check_out "1 full fixture: only open items whose heading paths are all gone at HEAD"

# --- Case 2: no orphans -------------------------------------------------------
cat > "$FX/body.tmp" <<'EOF'
### `keep/a.php`
- [ ] exists item
### `gone/x.php`
- [x] ticked item
EOF
: > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run
check_rc "2 no orphans: exit 0" 0
check_out_empty "2 no orphans: empty output"
reset_env

# --- Case 3: a heading does not carry from the body into a comment ------------
cat > "$FX/body.tmp" <<'EOF'
### `gone/z.php`
- [ ] z item
EOF
printf '%s\n' '- [ ] carried item' > "$FX/c-carry.md"
echo "333 $FX/c-carry.md" > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run
expect_lines "gone/z.php: z item"
check_out "3 heading does not carry from the body into a comment"
cat > "$FX/body.tmp" <<'EOF'
### `gone/z.php`
- [ ] z item
## Closed items
- [ ] after end item
EOF
: > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run
expect_lines "gone/z.php: z item"
check_out "3 an orphan section ends at the next ## heading"
reset_env

# --- Case 4: gh failures give exit 2 ------------------------------------------
for step in list view comments; do
    STUB_FAIL="$step" run
    check_rc "4 gh $step failure: exit 2" 2
    check_out_empty "4 gh $step failure: empty stdout"
done
reset_env

# --- Case 5: no ledger issue, two ledger issues, a bad issue number -----------
: > "$STUB_LIST"
run
check_rc "5 no open ledger issue: exit 2" 2
check_out_empty "5 no open ledger issue: empty stdout"
printf '2208\n2300\n' > "$STUB_LIST"
run
check_rc "5 two open ledger issues: exit 2" 2
if grep -q 'issue view' "$STUB_LOG"; then
    fail "5 two open ledger issues: no issue was read" "$(cat "$STUB_LOG")"
else
    pass "5 two open ledger issues: no issue was read"
fi
echo '12x' > "$STUB_LIST"
run
check_rc "5 non-numeric issue number: exit 2" 2
reset_env

# --- Case 6: truncated gh output gives exit 2 ---------------------------------
for kind in body comments; do
    STUB_TRUNCATE="$kind" run
    check_rc "6 truncated $kind: exit 2" 2
    check_out_empty "6 truncated $kind: empty stdout"
    check_err_has "6 truncated $kind: stderr names it" "truncated"
done
reset_env

# --- Case 7: an argument gives exit 1 with no gh call -------------------------
run gone/a.php
check_rc "7 argument: exit 1" 1
check_out_empty "7 argument: empty stdout"
check_no_gh "7 argument: no gh call"
check_err_has "7 argument: usage text on stderr" "Usage"

# --- Case 8: outside a repository, or with no commit: exit 1, no gh call ------
mkdir -p "$TMPROOT/norepo"
: > "$STUB_LOG"
(cd "$TMPROOT/norepo" && GIT_CEILING_DIRECTORIES="$TMPROOT" "$SCRIPT" < /dev/null > "$OUT" 2> "$ERR")
RC=$?
check_rc "8 not a git repository: exit 1" 1
check_no_gh "8 not a git repository: no gh call"
check_err_has "8 not a git repository: message on stderr" "inside a git repository"
git -C "$TMPROOT/norepo" init -q
: > "$STUB_LOG"
(cd "$TMPROOT/norepo" && "$SCRIPT" < /dev/null > "$OUT" 2> "$ERR")
RC=$?
check_rc "8 repository with no commit: exit 1" 1
check_no_gh "8 repository with no commit: no gh call"

# --- Case 9: comment author filter --------------------------------------------
printf '%s\n' '### `gone/auth.php`' '- [ ] outsider item' > "$FX/c-none.md"
printf '%s\n' '### `gone/auth.php`' '- [ ] owner item' > "$FX/c-owner.md"
printf '901 %s NONE\n902 %s OWNER\n' "$FX/c-none.md" "$FX/c-owner.md" > "$STUB_COMMENTS"
: > "$FX/body.tmp"
STUB_BODY="$FX/body.tmp" run
expect_lines "gone/auth.php: owner item"
check_out "9 author filter: a NONE comment is ignored, an OWNER comment is read"
reset_env

# --- Case 10: control characters and CRLF -------------------------------------
printf '### `gone/ctl.php`\r\n- [ ] red \033[31mtext\001 here\tok\177  \r\n' > "$FX/body.tmp"
: > "$STUB_COMMENTS"
STUB_BODY="$FX/body.tmp" run
printf 'gone/ctl.php: red [31mtext here\tok\n' > "$EXP"
check_out "10 control characters and CR removed from stdout, TAB kept"
reset_env

# --- Case 11: read-only -------------------------------------------------------
run
if grep -qE 'PATCH|POST|--method|issue (comment|edit|close)' "$STUB_LOG"; then
    fail "11 the script makes no write call" "$(cat "$STUB_LOG")"
else
    pass "11 the script makes no write call"
fi
if [ -z "$(git -C "$WORK" status --porcelain -- . ':!keep/deleted.php' ':!untracked.php')" ]; then
    pass "11 the script changes nothing in the repository"
else
    fail "11 the script changes nothing in the repository" "$(git -C "$WORK" status --porcelain)"
fi

echo
echo "Ran $TESTS_RUN checks, $TESTS_FAILED failed."
[ "$TESTS_FAILED" -eq 0 ]
