#!/bin/bash
#
# Regression test for scripts/ledger-items-for-files.sh.
#
# HERMETIC: each case runs the script inside a temporary git repository
# that holds a fixture docs/development/CLEANUP_LEDGER.md. The real ledger
# is never read.
#
# Expected output goes in files and is compared with cmp. Nothing compares
# script output through $(...), because that drops trailing newlines.
#
# Set LEDGER_SCRIPT to test another copy of the script (for a mutation
# check).
#
# Usage: bash tests/hooks/test-ledger-items-for-files.sh
# Exit code: 0 if all cases pass, 1 otherwise.

# Fixture lines hold literal backticks, so single quotes are on purpose.
# shellcheck disable=SC2016
set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

REPO_ROOT="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="${LEDGER_SCRIPT:-$REPO_ROOT/scripts/ledger-items-for-files.sh}"
if [ ! -x "$SCRIPT" ]; then
    echo "FAIL: $SCRIPT not found or not executable" >&2
    exit 1
fi

TMPROOT="$(mktemp -d)" || exit 1

# Some cases remove permissions in $TMPROOT; restore them so rm -rf works.
# shellcheck disable=SC2329 # called only through the EXIT trap in lib/harness.sh
cleanup() {
    cd / || true
    [ -n "${TMPROOT:-}" ] && chmod -R u+rwx "$TMPROOT" 2>/dev/null
    [ -n "${TMPROOT:-}" ] && rm -rf "$TMPROOT"
}

# --- Fixture repository -------------------------------------------------------
FXREPO="$TMPROOT/repo"
mkdir -p "$FXREPO/docs/development" "$FXREPO/sub/dir"
git -C "$FXREPO" init -q
LEDGER="$FXREPO/docs/development/CLEANUP_LEDGER.md"
OUT="$TMPROOT/out"
ERR="$TMPROOT/err"
EXP="$TMPROOT/exp"
IN="$TMPROOT/in"

cat > "$TMPROOT/default.md" <<'EOF'
# Cleanup Ledger

Intro mentions `app/intro.php` outside any heading.
- [ ] preamble item before any heading

## Items

### `app/one.php`
- [ ] one-open: first item
- [x] one-ticked item
  - [ ] indented item
* [ ] star item
-[ ] no-space item
- [ ]no-gap item
plain text line

### `app/two.php`
- [ ] two item one
- [ ] two item two

### `lib/a.php` + `lib/b.php` (est. −125)
- [ ] plus-item

### `lib/c.php`, `lib/d.php`
- [ ] comma-item

### `scripts/spike-1871/`
- [ ] dir-item

### `lib/c.php`
- [ ] second c item

## Closed items
- [ ] after-end item
EOF

use_default() { cp "$TMPROOT/default.md" "$LEDGER"; }

# run_paths <path>...: sends one path per line on stdin, from the repo root.
run_paths() {
    printf '%s\n' "$@" > "$IN"
    (cd "$FXREPO" && "$SCRIPT" < "$IN" > "$OUT" 2> "$ERR")
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

use_default

# --- Case 1: one heading, only the open column-0 item -------------------------
run_paths app/one.php
expect_lines "app/one.php: one-open: first item"
check_rc "1 match: exit 0" 0
check_out "1 match: only the open item, text keeps its ': '"

# --- Case 2: two items under one heading --------------------------------------
run_paths app/two.php
expect_lines "app/two.php: two item one" "app/two.php: two item two"
check_out "2 two items: both printed in file order"

# --- Case 3: a path with no heading -------------------------------------------
run_paths nothing/here.php app/intro.php
check_rc "3 no heading for the path: exit 0" 0
check_out_empty "3 no heading for the path, and text outside a heading: empty output"

# --- Case 4: an argument gives exit 1 -----------------------------------------
(cd "$FXREPO" && "$SCRIPT" app/one.php < /dev/null > "$OUT" 2> "$ERR")
RC=$?
check_rc "4 argument: exit 1" 1
check_out_empty "4 argument: empty stdout"
check_err_has "4 argument: usage text on stderr" "Usage"

# --- Case 5: `a + b (est. -125)` heading --------------------------------------
run_paths lib/a.php
expect_lines "lib/a.php: plus-item"
check_out "5 plus heading: first path matches"
run_paths lib/b.php
expect_lines "lib/b.php: plus-item"
check_out "5 plus heading: second path matches"
run_paths 'est.' '125' '(est.' '+'
check_out_empty "5 plus heading: text outside backticks is not a path"

# --- Case 6: comma-separated heading, and two headings for one path -----------
run_paths lib/d.php
expect_lines "lib/d.php: comma-item"
check_out "6 comma heading: second token matches"
run_paths lib/c.php
expect_lines "lib/c.php: comma-item" "lib/c.php: second c item"
check_out "6 two headings name one path: items of both, in file order"

# --- Case 7: directory token --------------------------------------------------
run_paths scripts/spike-1871/x.php
expect_lines "scripts/spike-1871/x.php: dir-item"
check_out "7 directory token: matches a file under it"
run_paths scripts/spike-1871/sub/deep.php
expect_lines "scripts/spike-1871/sub/deep.php: dir-item"
check_out "7 directory token: matches a nested file"
run_paths scripts/spike-18710/x scripts/spike-1871 scripts/spike-1871/ scripts/
check_out_empty "7 directory token: no match for a sibling name, the directory itself, or a parent"

# --- Case 8: two input paths for one heading print the item once --------------
run_paths lib/a.php lib/b.php
expect_lines "lib/a.php: plus-item"
check_out "8 two paths, one heading: printed once with the first input path"
run_paths lib/b.php lib/a.php
expect_lines "lib/b.php: plus-item"
check_out "8 two paths, reversed: printed once with the first input path"

# --- Case 9: exact match only -------------------------------------------------
run_paths lib/aXphp app/one.php.bak xapp/one.php app/one
check_out_empty "9 exact match: no regex, prefix, or suffix match"

# --- Case 10: CRLF ledger and CRLF input --------------------------------------
printf '### `crlf/a.php`\r\n- [ ] crlf item\r\n- [x] crlf done\r\n\r\n### `crlf/b.php`\r\n- [ ] other item\r\n' > "$LEDGER"
run_paths crlf/a.php
expect_lines "crlf/a.php: crlf item"
check_out "10 CRLF ledger: no trailing CR in the item"
printf 'crlf/b.php\r\n' > "$IN"
(cd "$FXREPO" && "$SCRIPT" < "$IN" > "$OUT" 2> "$ERR")
expect_lines "crlf/b.php: other item"
check_out "10 CRLF input path: CR removed before the match"

# --- Case 11: items do not leak past the next heading -------------------------
cat > "$LEDGER" <<'EOF'
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
#### `leak/c.php` deeper heading
- [ ] deeper item
EOF
run_paths leak/a.php
expect_lines "leak/a.php: a item"
check_out "11 no leak into the next ### heading (path a)"
run_paths leak/b.php
expect_lines "leak/b.php: b item"
check_out "11 no leak into a heading without paths (path b)"
run_paths leak/c.php
expect_lines "leak/c.php: c item"
check_out "11 no leak past a ## heading, even one that names the path (path c)"

# --- Case 12: indented and non-checkbox lines ---------------------------------
cat > "$LEDGER" <<'EOF'
### `fmt/a.php`
  - [ ] indented item
	- [ ] tab item
* [ ] star item
-[ ] no-space item
- [ ]no-gap item
- [] empty box
- [X] capital X
- [x] ticked item
> - [ ] quoted item
plain text line
1. [ ] numbered item
- [ ] valid item
EOF
run_paths fmt/a.php
expect_lines "fmt/a.php: valid item"
check_out "12 only a column-0 '- [ ] ' line is an item"

# --- Case 13: trailing whitespace is not printed ------------------------------
printf '### `ws/a.php`\n- [ ] spaces item   \n- [ ] tab item\t\n- [ ] crlf item \t\r\n' > "$LEDGER"
run_paths ws/a.php
expect_lines "ws/a.php: spaces item" "ws/a.php: tab item" "ws/a.php: crlf item"
check_out "13 trailing whitespace: spaces, TAB and CR are not printed"

# --- Case 14: control characters are removed from the printed item ------------
printf '### `ctl/a.php`\n- [ ] red \033[31mtext\001 here\tok\177\n' > "$LEDGER"
run_paths ctl/a.php
printf 'ctl/a.php: red [31mtext here\tok\n' > "$EXP"
check_out "14 control characters: removed from stdout, TAB kept"

# --- Case 15: a backslash in a path is literal --------------------------------
printf '### `w/a\\tb.php`\n- [ ] backslash item\n' > "$LEDGER"
run_paths 'w/a\tb.php'
expect_lines 'w/a\tb.php: backslash item'
check_out "15 backslash in a path: matched as literal text, not as an escape"

# --- Case 16: the ledger is read from the repo root ---------------------------
use_default
printf 'app/one.php\n' > "$IN"
(cd "$FXREPO/sub/dir" && "$SCRIPT" < "$IN" > "$OUT" 2> "$ERR")
RC=$?
expect_lines "app/one.php: one-open: first item"
check_rc "16 run from a subdirectory: exit 0" 0
check_out "16 run from a subdirectory: the root ledger is read"

# --- Case 17: empty stdin and blank lines -------------------------------------
rm -f "$LEDGER"
(cd "$FXREPO" && "$SCRIPT" < /dev/null > "$OUT" 2> "$ERR")
RC=$?
check_rc "17 empty stdin: exit 0, even with no ledger" 0
check_out_empty "17 empty stdin: empty stdout"
check_err_has "17 empty stdin: a note on stderr" "ledger-items-for-files.sh: no input paths, the ledger was not read"
printf '\n\n\r\n' > "$IN"
(cd "$FXREPO" && "$SCRIPT" < "$IN" > "$OUT" 2> "$ERR")
RC=$?
check_rc "17 blank lines only: exit 0" 0
check_err_has "17 blank lines only: a note on stderr" "no input paths"

# --- Case 18: missing or unreadable ledger, no git work tree: exit 2 -----------
run_paths app/one.php
check_rc "18 missing ledger: exit 2" 2
check_out_empty "18 missing ledger: empty stdout"
check_err_has "18 missing ledger: stderr names the file" "cannot read docs/development/CLEANUP_LEDGER.md"
mkdir "$LEDGER"
run_paths app/one.php
check_rc "18 ledger path is a directory: exit 2" 2
rmdir "$LEDGER"
if [ "$(id -u)" -ne 0 ]; then
    use_default
    chmod 000 "$LEDGER"
    run_paths app/one.php
    check_rc "18 unreadable ledger: exit 2" 2
    check_out_empty "18 unreadable ledger: empty stdout"
    chmod 644 "$LEDGER"
fi
NOGIT="$TMPROOT/nogit"
mkdir -p "$NOGIT"
printf 'app/one.php\n' > "$IN"
(cd "$NOGIT" && GIT_CEILING_DIRECTORIES="$TMPROOT" "$SCRIPT" < "$IN" > "$OUT" 2> "$ERR")
RC=$?
check_rc "18 not in a git work tree: exit 2" 2
check_err_has "18 not in a git work tree: stderr says so" "not in a git work tree"

harness_report
