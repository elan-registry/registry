#!/bin/bash
#
# Regression test for scripts/ledger-pr-body-add.sh.
#
# HERMETIC: the script under test makes no gh call and reads only its
# arguments and stdin. All fixtures are in a temp directory.
#
# Output goes in files and is compared with cmp. Nothing compares it through
# $(...), because that drops trailing newlines.
#
# Usage: bash tests/hooks/test-ledger-pr-body-add.sh
# Exit code: 0 if all cases pass, 1 otherwise.

set -u

REPO_ROOT="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REPO_ROOT/scripts/ledger-pr-body-add.sh"
ITEMS_SCRIPT="$REPO_ROOT/scripts/ledger-pr-body-items.sh"
for f in "$SCRIPT" "$ITEMS_SCRIPT"; do
    if [ ! -x "$f" ]; then
        echo "FAIL: $f not found or not executable" >&2
        exit 1
    fi
done

TMPROOT="$(mktemp -d)" || exit 1
trap 'rm -rf "$TMPROOT"' EXIT
cd "$TMPROOT" || exit 1

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

# check <name> <items file> <body file> <expected file>
# Runs the script and compares stdout with the expected file byte for byte.
check() {
    local name="$1" items="$2" body="$3" expected="$4" rc=0
    "$SCRIPT" "$items" < "$body" > out 2> err || rc=$?
    if [ "$rc" -ne 0 ]; then
        fail "$name" "exit $rc" "$(cat err)"
    elif ! cmp -s out "$expected"; then
        fail "$name" "output differs:" "$(diff "$expected" out)"
    else
        pass "$name"
    fi
}

printf 'app/a.php: fix x\n- app/b.php: do y: z\napp/a.php: fix x\n\n' > items_two

# --- A `- none` bullet is replaced, and the next section stays intact ------
printf 'Summary\n\n## Ledger items\n\n- none\n\n## Advisory items declined\n\n- foo\n' > body
printf 'Summary\n\n## Ledger items\n\n- app/a.php: fix x\n- app/b.php: do y: z\n\n## Advisory items declined\n\n- foo\n' > want
check "none bullet replaced; duplicates and blank lines dropped" items_two body want

# --- The failed-query `none` form is replaced too --------------------------
printf '## Ledger items\n\n- none (ledger query failed)\n' > body
printf '## Ledger items\n\n- app/a.php: fix x\n- app/b.php: do y: z\n' > want
check "none (ledger query failed) replaced at end of body" items_two body want

# --- An item that is already in the section is not added again -------------
printf '## Ledger items\n\n* app/a.php: fix x\n\n## Next\n' > body
printf '## Ledger items\n\n* app/a.php: fix x\n- app/b.php: do y: z\n\n## Next\n' > want
check "existing item kept, only the new one appended" items_two body want

# --- No new item: stdout is the same as stdin ------------------------------
printf 'Text\n\n## Ledger items\n\n- app/a.php: fix x\n- app/b.php: do y: z\n\n## Next\n' > body
check "no new item leaves the body unchanged" items_two body body

# --- No section, body with no final newline: a section is added ------------
printf 'Summary line' > body
printf 'Summary line\n\n## Ledger items\n\n- app/a.php: fix x\n- app/b.php: do y: z\n' > want
check "section added after a body with no final newline" items_two body want

# --- Empty body: the section is the whole body -----------------------------
: > body
printf '## Ledger items\n\n- app/a.php: fix x\n- app/b.php: do y: z\n' > want
check "section added to an empty body" items_two body want

# --- An empty items file changes nothing -----------------------------------
: > items_empty
printf '## Ledger items\n\n- none\n' > body
check "empty items file leaves the none bullet" items_empty body body

# --- CRLF body: the heading is found and existing lines keep their CR ------
printf '## Ledger items\r\n\r\n- app/a.php: fix x\r\n' > body
printf '## Ledger items\r\n\n- app/a.php: fix x\r\n- app/b.php: do y: z\n' > want
check "CRLF body: heading found, existing CR kept" items_two body want

# --- Round trip: ledger-pr-body-items.sh reads the added items -------------
printf 'Summary\n\n## Ledger items\n\n- none\n\n## Review decisions\n\n- x\n' > body
"$SCRIPT" items_two < body > out 2>/dev/null
"$ITEMS_SCRIPT" < out > got 2>/dev/null
printf -- '- app/a.php: fix x\n- app/b.php: do y: z\n' > want
if cmp -s got want; then
    pass "round trip through ledger-pr-body-items.sh"
else
    fail "round trip through ledger-pr-body-items.sh" "$(diff want got)"
fi

# --- Usage errors exit 1 ---------------------------------------------------
rc=0; "$SCRIPT" < body > /dev/null 2>&1 || rc=$?
if [ "$rc" -eq 1 ]; then pass "no argument exits 1"; else fail "no argument exits 1" "exit $rc"; fi
rc=0; "$SCRIPT" missing-file < body > /dev/null 2>&1 || rc=$?
if [ "$rc" -eq 1 ]; then pass "unreadable items file exits 1"; else fail "unreadable items file exits 1" "exit $rc"; fi
rc=0; "$SCRIPT" items_two extra < body > /dev/null 2>&1 || rc=$?
if [ "$rc" -eq 1 ]; then pass "two arguments exit 1"; else fail "two arguments exit 1" "exit $rc"; fi

echo
echo "${TESTS_RUN} run, ${TESTS_FAILED} failed"
[ "$TESTS_FAILED" -eq 0 ]
