#!/bin/bash
#
# Tests for scripts/check-deploy-sheet-fresh.sh: the deploy sheet is stale
# only when a deploy input changed after the stamped commit. A later commit
# that changes only release notes, docs, or ordinary code keeps it fresh.
#
# HERMETIC: builds a throwaway git repo under a temp dir, with a
# milestone/v9.9.9 branch and a stamp file in docs/plans/releases/. Never
# touches this repo's own branches, history, or docs/plans/.
#
# Usage: bash tests/hooks/test-check-deploy-sheet-fresh.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/check-deploy-sheet-fresh.sh"
if [ ! -x "$SCRIPT" ]; then
    echo "FAIL: $SCRIPT not found or not executable" >&2
    exit 1
fi

TMPROOT="$(mktemp -d)" || exit 1
# shellcheck disable=SC2329 # called only through the EXIT trap below
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

VERSION="v9.9.9"
REPO="$TMPROOT/repo"
STAMP="$REPO/docs/plans/releases/${VERSION}-deploy.md.sha"
mkdir -p "$REPO"
cd "$REPO" || exit 1
git init -q
git config user.name "test-check-deploy-sheet-fresh"
git config user.email "test-check-deploy-sheet-fresh@localhost"
mkdir -p app/owner database/migrations docs/plans/releases docs/development
echo "<?php securePage(\$php_self);" > app/owner/old_page.php
echo "base" > README.md
git add app README.md
git commit -q -m "base"
git checkout -q -b "milestone/${VERSION}"

# Stamps the current tip, the way /finish-milestone Step 6.6 does.
stamp() { git rev-parse "milestone/${VERSION}" > "$STAMP"; }

# commit_file <path> <content> — adds or changes one file and commits it.
commit_file() {
    mkdir -p "$(dirname "$1")"
    printf '%s\n' "$2" > "$1"
    git add "$1"
    git commit -q -m "change $1"
}

# expect_status <want> <description> [<grep pattern for output>]
expect_status() {
    local want="$1" desc="$2" pattern="${3:-}" out status
    out="$("$SCRIPT" "$VERSION" 2>&1)"
    status=$?
    if [ "$status" -eq "$want" ] && { [ -z "$pattern" ] || printf '%s' "$out" | grep -q -- "$pattern"; }; then
        pass "$desc"
    else
        fail "$desc" "exit: $status (want $want)" "output: [$out]"
    fi
}

# --- Case 1: stamp is the tip -> 0 ---------------------------------------
stamp
expect_status 0 "Case 1: stamp equals branch tip -> exit 0" "still the branch tip"

# --- Case 2: later commits change only notes, docs and code -> 0 ---------
stamp
commit_file docs/releases/RELEASE_NOTES_${VERSION}.md "notes"
commit_file CLAUDE.md "claude"
commit_file usersc/classes/NewClass.php "<?php class NewClass {}"
commit_file app/owner/old_page.php "<?php securePage(\$php_self); // review fix"
expect_status 0 "Case 2: notes, CLAUDE.md, new class, edit of a page -> exit 0" "no deploy input changed"

# --- Case 3: one case per deploy input -> 1 ------------------------------
check_input() {
    local desc="$1" path="$2" content="$3"
    stamp
    commit_file "$path" "$content"
    expect_status 1 "Case 3: $desc -> exit 1" "$path"
}
check_input "new migration" database/migrations/20990101000000_x.php "<?php"
check_input "edited migration" database/migrations/20990101000000_x.php "<?php // edited"
check_input "post-receive hook" scripts/server-hooks/post-receive "#!/bin/sh"
check_input ".env.example" .env.example "NEW_VAR="
check_input "fix script" app/admin/scripts/fix/99-Fix.php "<?php"
check_input "maintenance script" app/admin/scripts/maintenance/clean.php "<?php"
check_input "deploy sheet template" docs/development/RELEASE_INSTRUCTIONS_TEMPLATE.md "template"
check_input "new securePage page" app/owner/new_page.php "<?php securePage(\$php_self);"

# --- Case 4: deleted securePage page -> 1 --------------------------------
stamp
git rm -q app/owner/old_page.php
git commit -q -m "delete page"
expect_status 1 "Case 4: deleted securePage page -> exit 1" "D app/owner/old_page.php"

# --- Case 5: new PHP under an excluded directory -> 0 --------------------
stamp
commit_file tests/unit/NewTest.php "<?php securePage(\$php_self);"
commit_file scripts/tool.php "<?php securePage(\$php_self);"
expect_status 0 "Case 5: securePage( under tests/ and scripts/ -> exit 0"

# --- Case 6: no stamp file -> 2 ------------------------------------------
rm -f "$STAMP"
expect_status 2 "Case 6: no stamp file -> exit 2" "No stamp file"

# --- Case 7: corrupt stamp -> 2 ------------------------------------------
printf 'abc\ndef\n' > "$STAMP"
expect_status 2 "Case 7: corrupt stamp -> exit 2" "single valid 40-char SHA"

# --- Case 8: stamped commit not in repo -> 2 -----------------------------
printf '%s\n' "0123456789abcdef0123456789abcdef01234567" > "$STAMP"
expect_status 2 "Case 8: unknown stamped commit -> exit 2" "not present in this repo"

# --- Case 9: milestone branch missing -> 2 -------------------------------
stamp
git checkout -q -b other
git branch -q -D "milestone/${VERSION}"
expect_status 2 "Case 9: milestone branch missing -> exit 2" "Could not resolve"

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
