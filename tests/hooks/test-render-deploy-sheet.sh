#!/bin/bash
#
# Regression test for scripts/render-deploy-sheet.sh: matches in a large
# (>64 KB) diff (#2225), and no false flags from modified files (#2250).
#
# HERMETIC: builds a throwaway git repo under a temp dir and runs the real
# script against synthetic branches there. Never touches this repo's own
# branches or history.
#
# Usage: bash tests/hooks/test-render-deploy-sheet.sh

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/render-deploy-sheet.sh"
if [ ! -f "$SCRIPT" ]; then
    echo "FAIL: $SCRIPT not found" >&2
    exit 1
fi

TMPROOT="$(mktemp -d)" || exit 1

# --- Build a throwaway git repo -------------------------------------------

REPO="$TMPROOT/repo"
mkdir -p "$REPO"
cd "$REPO" || exit 1
git init -q -b main 2>/dev/null || { git init -q; git checkout -q -b main 2>/dev/null || true; }
git config user.name "test-render-deploy-sheet"
git config user.email "test-render-deploy-sheet@localhost"

echo "base" > base.txt
git add base.txt
git commit -q -m "base commit on main"

# --- Case 1: CREATE TRIGGER early in a migration file that is itself ------
# >64 KB. The trigger check now reads only the added migration's own content
# (via `git show`), not the whole diff, so the padding must live inside that
# migration file, after the CREATE TRIGGER line, to exercise the same pipe-
# buffer edge case as #2225.
git checkout -q -b milestone/vtest main
mkdir -p database/migrations
{
    echo "<?php"
    echo "-- CREATE TRIGGER test_trigger"
    # 20000 lines (~300 KB), far past a pipe buffer.
    awk 'BEGIN { for (i = 0; i < 20000; i++) print "padding line " i }'
} > database/migrations/20990101000000_test.php
git add database/migrations/20990101000000_test.php
git commit -q -m "add trigger migration with large content"

FILE_BYTES="$(git show "milestone/vtest:database/migrations/20990101000000_test.php" | wc -c | tr -d ' ')"
if [ "$FILE_BYTES" -le 70000 ]; then
    fail "Case 1: migration file fixture too small to exercise the bug" \
        "file bytes: $FILE_BYTES (want > 70000)"
else
    OUT1="$(bash "$SCRIPT" vtest 2>/dev/null)"
    RC1=$?
    if [ "$RC1" -eq 0 ] \
        && printf '%s\n' "$OUT1" | grep -q '^migration: TRUE$' \
        && printf '%s\n' "$OUT1" | grep -q '^trigger-migration: TRUE$'; then
        pass "Case 1: CREATE TRIGGER early in a >64KB migration file is detected"
    else
        fail "Case 1: CREATE TRIGGER early in a >64KB migration file is detected" \
            "exit: $RC1" "file bytes: $FILE_BYTES" "output: [$OUT1]"
    fi
fi

# --- Case 2: control — added migration WITHOUT CREATE TRIGGER -------------
git checkout -q -b milestone/vctrl main
mkdir -p database/migrations
{
    echo "<?php"
    echo "-- ordinary migration, no trigger"
} > database/migrations/20990101000001_control.php
git add database/migrations/20990101000001_control.php
git commit -q -m "add control migration"

OUT2="$(bash "$SCRIPT" vctrl 2>/dev/null)"
RC2=$?
if [ "$RC2" -eq 0 ] \
    && printf '%s\n' "$OUT2" | grep -q '^migration: TRUE$' \
    && ! printf '%s\n' "$OUT2" | grep -q '^trigger-migration: TRUE$'; then
    pass "Case 2 (control): migration without CREATE TRIGGER -> migration TRUE, trigger-migration absent"
else
    fail "Case 2 (control): migration without CREATE TRIGGER -> migration TRUE, trigger-migration absent" \
        "exit: $RC2" "output: [$OUT2]"
fi

# --- Case 3: .env.example changes AND a large DIFF_FILES name list --------
git checkout -q -b milestone/vmany main
echo "FOO=bar" > .env.example
mkdir -p many
# Few, long names: the file count only has to push the DIFF_FILES name list
# past 70 KB, and fewer files keep the fixture's own build time down.
i=0
while [ "$i" -lt 800 ]; do
    printf 'x' > "many/generated-file-name-padded-to-be-very-long-for-fewer-files-in-this-fixture-$(printf '%04d' "$i").txt"
    i=$((i + 1))
done
git add .env.example many
git commit -q -m "change .env.example + add many files"

DIFF_NAME_BYTES="$(git diff --name-only "main...milestone/vmany" | wc -c | tr -d ' ')"
if [ "$DIFF_NAME_BYTES" -le 70000 ]; then
    fail "Case 3: DIFF_FILES fixture too small to exercise the bug" \
        "name-list bytes: $DIFF_NAME_BYTES (want > 70000)"
else
    OUT3="$(bash "$SCRIPT" vmany 2>/dev/null)"
    RC3=$?
    if [ "$RC3" -eq 0 ] \
        && printf '%s\n' "$OUT3" | grep -q '^env-vars: TRUE$'; then
        pass "Case 3: .env.example match survives a >64KB DIFF_FILES name list"
    else
        fail "Case 3: .env.example match survives a >64KB DIFF_FILES name list" \
            "exit: $RC3" "name-list bytes: $DIFF_NAME_BYTES" "output: [$OUT3]"
    fi
fi

git checkout -q main

# --- Case 4: modified migration on an existing file -----------------------
# A migration that already exists on main and is only edited on the branch
# is not a new deploy action (deploy applies only new, unapplied files), so
# it must read as migration-modified: CHECK, not migration: TRUE.
mkdir -p database/migrations
echo "<?php -- original migration" > database/migrations/20990102000000_edit_me.php
git add database/migrations/20990102000000_edit_me.php
git commit -q -m "add migration later modified by milestone/vmodmig"

git checkout -q -b milestone/vmodmig main
{
    echo "<?php -- edited migration"
    echo "-- CREATE TRIGGER edit_me_trigger"
} > database/migrations/20990102000000_edit_me.php
git add database/migrations/20990102000000_edit_me.php
git commit -q -m "modify existing migration"

OUT4="$(bash "$SCRIPT" vmodmig 2>/dev/null)"
RC4=$?
if [ "$RC4" -eq 0 ] \
    && printf '%s\n' "$OUT4" | grep -q '^migration-modified: CHECK$' \
    && printf '%s\n' "$OUT4" | grep -q '  - database/migrations/20990102000000_edit_me.php' \
    && ! printf '%s\n' "$OUT4" | grep -q '^migration: TRUE$' \
    && ! printf '%s\n' "$OUT4" | grep -q '^trigger-migration: TRUE$'; then
    pass "Case 4: modifying an existing migration (even with CREATE TRIGGER) -> migration-modified: CHECK, not migration: TRUE or trigger-migration: TRUE"
else
    fail "Case 4: modifying an existing migration (even with CREATE TRIGGER) -> migration-modified: CHECK, not migration: TRUE or trigger-migration: TRUE" \
        "exit: $RC4" "output: [$OUT4]"
fi

git checkout -q main

# --- Case 4b: added and modified migrations on one branch -----------------
# Case 4 adds no migration, so the trigger scan never runs there. Here the
# branch adds a trigger-free migration and edits one that contains
# CREATE TRIGGER. A trigger scan that also read modified migrations would
# set trigger-migration, which is the false flag #2250 removes.
git checkout -q -b milestone/vmixedmig main
{
    echo "<?php -- edited again"
    echo "-- CREATE TRIGGER edit_me_trigger"
} > database/migrations/20990102000000_edit_me.php
echo "<?php -- new migration, no trigger" > database/migrations/20990103000000_new.php
git add database/migrations
git commit -q -m "modify a migration with a trigger + add a trigger-free migration"

OUT4B="$(bash "$SCRIPT" vmixedmig 2>/dev/null)"
RC4B=$?
if [ "$RC4B" -eq 0 ] \
    && printf '%s\n' "$OUT4B" | grep -q '^migration: TRUE$' \
    && printf '%s\n' "$OUT4B" | grep -qx '  - database/migrations/20990103000000_new.php' \
    && printf '%s\n' "$OUT4B" | grep -q '^migration-modified: CHECK$' \
    && printf '%s\n' "$OUT4B" | grep -qx '  - database/migrations/20990102000000_edit_me.php' \
    && ! printf '%s\n' "$OUT4B" | grep -q '^trigger-migration: TRUE$'; then
    pass "Case 4b: a trigger in a modified migration does not set trigger-migration when an added one has none"
else
    fail "Case 4b: a trigger in a modified migration does not set trigger-migration when an added one has none" \
        "exit: $RC4B" "output: [$OUT4B]"
fi

git checkout -q main

# --- Case 5: CREATE TRIGGER in a non-migration file, plus an added ---------
# migration without one. trigger-migration must key off the added
# migration's OWN content (read via git show), not a match anywhere in the
# whole diff.
git checkout -q -b milestone/vfalsetrigger main
mkdir -p database/migrations
echo "-- CREATE TRIGGER mentioned here, not a migration file" > notes.sql
{
    echo "<?php"
    echo "-- ordinary migration, no trigger"
} > database/migrations/20990103000000_no_trigger.php
git add notes.sql database/migrations/20990103000000_no_trigger.php
git commit -q -m "add non-migration file with CREATE TRIGGER + trigger-free migration"

OUT5="$(bash "$SCRIPT" vfalsetrigger 2>/dev/null)"
RC5=$?
if [ "$RC5" -eq 0 ] \
    && printf '%s\n' "$OUT5" | grep -q '^migration: TRUE$' \
    && ! printf '%s\n' "$OUT5" | grep -q '^trigger-migration: TRUE$'; then
    pass "Case 5: CREATE TRIGGER outside the added migration does not set trigger-migration"
else
    fail "Case 5: CREATE TRIGGER outside the added migration does not set trigger-migration" \
        "exit: $RC5" "output: [$OUT5]"
fi

git checkout -q main

# --- Case 6: securePage( in files under an excluded directory, or in a ------
# non-.php file. new-pages excludes tests/, database/, scripts/, vendor/,
# users/, and only ever looks at .php files. None of these must be reported.
git checkout -q -b milestone/vnonpage main
mkdir -p docs tests/unit scripts
echo "securePage(" > docs/x.md
echo "<?php securePage(" > tests/unit/XTest.php
echo "securePage(" > scripts/x.sh
git add docs tests/unit scripts
git commit -q -m "add securePage( mentions in a non-.php file or an excluded dir"

OUT6="$(bash "$SCRIPT" vnonpage 2>/dev/null)"
RC6=$?
if [ "$RC6" -eq 0 ] \
    && ! printf '%s\n' "$OUT6" | grep -q '^new-pages:'; then
    pass "Case 6: securePage( in a non-.php file or an excluded dir does not set new-pages"
else
    fail "Case 6: securePage( in a non-.php file or an excluded dir does not set new-pages" \
        "exit: $RC6" "output: [$OUT6]"
fi

git checkout -q main

# --- Case 7: mixed added PHP files across included and excluded locations --
# new-pages must list every added .php file with securePage( outside the
# excluded directories (app/, error/, usersc/, docs/, and root all count now),
# and skip both an added .php file that lacks securePage( and one under an
# excluded directory that has it.
git checkout -q -b milestone/vmixedpages main
mkdir -p app usersc error docs database/seeds tests
echo "<?php securePage(\$php_self);" > app/x.php
echo "<?php securePage(\$php_self);" > usersc/y.php
echo "<?php // no guard here" > app/z.php
echo "<?php securePage(\$php_self);" > error/403.php
echo "<?php securePage(\$php_self);" > docs/guide.php
echo "<?php securePage(\$php_self);" > newpage.php
echo "<?php securePage(\$php_self);" > database/seeds/x.php
echo "<?php securePage(\$php_self);" > tests/y.php
git add app usersc error docs newpage.php database/seeds tests
git commit -q -m "add mixed guarded/unguarded php files across included and excluded dirs"

OUT7="$(bash "$SCRIPT" vmixedpages 2>/dev/null)"
RC7=$?
if [ "$RC7" -eq 0 ] \
    && printf '%s\n' "$OUT7" | grep -q '^new-pages: TRUE$' \
    && printf '%s\n' "$OUT7" | grep -qx '  - app/x.php' \
    && printf '%s\n' "$OUT7" | grep -qx '  - usersc/y.php' \
    && printf '%s\n' "$OUT7" | grep -qx '  - error/403.php' \
    && printf '%s\n' "$OUT7" | grep -qx '  - docs/guide.php' \
    && printf '%s\n' "$OUT7" | grep -qx '  - newpage.php' \
    && ! printf '%s\n' "$OUT7" | grep -qx '  - app/z.php' \
    && ! printf '%s\n' "$OUT7" | grep -qx '  - database/seeds/x.php' \
    && ! printf '%s\n' "$OUT7" | grep -qx '  - tests/y.php'; then
    pass "Case 7: new-pages lists guarded pages outside the excluded dirs, skips unguarded and excluded ones"
else
    fail "Case 7: new-pages lists guarded pages outside the excluded dirs, skips unguarded and excluded ones" \
        "exit: $RC7" "output: [$OUT7]"
fi

git checkout -q main

# --- Case 8: new-pages content is read from the branch, not the working ---
# tree. The script must report the same result whether the milestone branch
# or main is currently checked out, because it reads file content with
# `git show BRANCH:file`, not from disk.
git checkout -q -b milestone/vfrombranch main
mkdir -p app
echo "<?php securePage(\$php_self);" > app/x.php
git add app
git commit -q -m "add guarded page, read while main is checked out"

git checkout -q main
OUT8="$(bash "$SCRIPT" vfrombranch 2>/dev/null)"
RC8=$?
if [ "$RC8" -eq 0 ] \
    && printf '%s\n' "$OUT8" | grep -q '^new-pages: TRUE$' \
    && printf '%s\n' "$OUT8" | grep -q '  - app/x.php'; then
    pass "Case 8: new-pages is detected from the branch while main is checked out"
else
    fail "Case 8: new-pages is detected from the branch while main is checked out" \
        "exit: $RC8" "output: [$OUT8]"
fi

# --- Case 8b: editing an existing guarded page is not a new page ----------
# main already has a guarded page (app/p.php); the branch only edits it.
# new-pages must not fire, since ADDED_FILES (not the modified-file list)
# drives the check, and the file is a modification relative to main, not an
# addition.
mkdir -p app
echo "<?php securePage(\$php_self);" > app/p.php
git add app
git commit -q -m "add existing guarded page on main"

git checkout -q -b milestone/vexistingpageedit main
echo "<?php securePage(\$php_self); // edited" > app/p.php
git add app
git commit -q -m "edit existing guarded page"

OUT8B="$(bash "$SCRIPT" vexistingpageedit 2>/dev/null)"
RC8B=$?
if [ "$RC8B" -eq 0 ] \
    && ! printf '%s\n' "$OUT8B" | grep -q '^new-pages:'; then
    pass "Case 8b: editing an existing guarded page does not set new-pages"
else
    fail "Case 8b: editing an existing guarded page does not set new-pages" \
        "exit: $RC8B" "output: [$OUT8B]"
fi

git checkout -q main

# --- Case 9: origin/main is preferred over a stale local main, and the ----
# script's OWN fetch is what makes it current (not a pre-existing ref).
# A bare origin, and REPO2 as one clone of it. A SECOND clone (REPO2B) is
# the one that pushes the origin-only migration commit straight to the bare
# origin, so REPO2's own refs/remotes/origin/main never sees it via a push
# side effect. The milestone branch is created in REPO2B (so it contains the
# origin-only commit) and pushed; back in REPO2, only
# `git fetch origin milestone/vorigin:milestone/vorigin` is run explicitly
# (never main), so REPO2's origin/main stays stale until the script itself
# runs `git fetch origin main`. If that fetch did not happen, origin/main in
# REPO2 would still lack the migration commit, and diffing the milestone
# branch (which has it) against that stale base would report it as added.
REPO2="$TMPROOT/repo2"
REPO2B="$TMPROOT/repo2b"
ORIGIN2="$TMPROOT/origin2.git"
mkdir -p "$REPO2"
# -b main: a clone of a bare repo whose HEAD names another branch (git's
# default is master on CI) checks out nothing, and the push of main fails.
git init -q --bare -b main "$ORIGIN2" 2>/dev/null \
    || { git init -q --bare "$ORIGIN2"; git -C "$ORIGIN2" symbolic-ref HEAD refs/heads/main; }

(
    cd "$REPO2" || exit 1
    git init -q -b main 2>/dev/null || { git init -q; git checkout -q -b main 2>/dev/null || true; }
    git config user.name "test-render-deploy-sheet"
    git config user.email "test-render-deploy-sheet@localhost"
    echo "base" > base.txt
    git add base.txt
    git commit -q -m "base commit on main"
    git remote add origin "$ORIGIN2"
    git push -q origin main
)

git clone -q "$ORIGIN2" "$REPO2B" 2>/dev/null
(
    cd "$REPO2B" || exit 1
    git config user.name "test-render-deploy-sheet"
    git config user.email "test-render-deploy-sheet@localhost"

    # A commit pushed straight to the bare origin from this second clone —
    # REPO2's origin/main never learns of it via a push side effect there.
    mkdir -p database/migrations
    echo "<?php -- origin-only migration" > database/migrations/20990104000000_origin_only.php
    git add database/migrations/20990104000000_origin_only.php
    git commit -q -m "origin-only migration"
    git push -q origin main

    git checkout -q -b milestone/vorigin origin/main
    echo "unrelated" > unrelated.txt
    git add unrelated.txt
    git commit -q -m "milestone change unrelated to the origin-only migration"
    git push -q origin milestone/vorigin
)

# In REPO2, fetch only the milestone ref explicitly (never main), so
# origin/main there stays stale until the script under test fetches it.
(cd "$REPO2" && git fetch -q origin milestone/vorigin:milestone/vorigin)
OUT9_OUT=""  # the Bonus check reads it under set -u, even on fixture failure
STALE_ORIGIN_MAIN="$(cd "$REPO2" && git rev-parse origin/main)"
FRESH_ORIGIN_MAIN="$(cd "$REPO2B" && git rev-parse origin/main)"
if [ "$STALE_ORIGIN_MAIN" = "$FRESH_ORIGIN_MAIN" ]; then
    fail "Case 9: fixture setup — REPO2's origin/main is not actually stale" \
        "stale: $STALE_ORIGIN_MAIN" "fresh: $FRESH_ORIGIN_MAIN"
else
    OUT9_OUT="$(cd "$REPO2" && bash "$SCRIPT" vorigin 2>"$TMPROOT/case9.err")"
    RC9=$?
    OUT9_ERR="$(cat "$TMPROOT/case9.err")"
    if [ "$RC9" -eq 0 ] \
        && printf '%s\n' "$OUT9_ERR" | grep -q '^base: origin/main ' \
        && ! printf '%s\n' "$OUT9_OUT" | grep -q '^migration: TRUE$' \
        && ! printf '%s\n' "$OUT9_OUT" | grep -q 'base:'; then
        pass "Case 9: the script's own fetch makes origin/main current; stdout carries no base: line"
    else
        fail "Case 9: the script's own fetch makes origin/main current; stdout carries no base: line" \
            "exit: $RC9" "stderr: [$OUT9_ERR]" "stdout: [$OUT9_OUT]"
    fi
fi

# --- Case 10: no origin remote --------------------------------------------
# Without an origin remote, the script must fall back to local main, warn
# on stderr, and still exit 0.
git checkout -q -b milestone/vnoorigin main
echo "no-origin change" > no-origin.txt
git add no-origin.txt
git commit -q -m "change with no origin remote configured"

OUT10_OUT="$(bash "$SCRIPT" vnoorigin 2>"$TMPROOT/case10.err")"
OUT10_STATUS=$?
OUT10_ERR="$(cat "$TMPROOT/case10.err")"
if [ "$OUT10_STATUS" -eq 0 ] \
    && printf '%s\n' "$OUT10_ERR" | grep -q '^base: main ' \
    && printf '%s\n' "$OUT10_ERR" | grep -qi 'warning'; then
    pass "Case 10: no origin remote -> falls back to local main with a warning, exit 0"
else
    fail "Case 10: no origin remote -> falls back to local main with a warning, exit 0" \
        "exit status: $OUT10_STATUS" "stderr: [$OUT10_ERR]"
fi

# --- Case 11: origin remote configured but unreachable, no stale ref -------
# origin points at a nonexistent path and refs/remotes/origin/main does not
# exist yet, so the fetch fails with nothing to fall back on for origin.
# The script must warn on stderr, fall back to local main, and exit 0.
REPO4="$TMPROOT/repo4"
mkdir -p "$REPO4"
(
    cd "$REPO4" || exit 1
    git init -q -b main 2>/dev/null || { git init -q; git checkout -q -b main 2>/dev/null || true; }
    git config user.name "test-render-deploy-sheet"
    git config user.email "test-render-deploy-sheet@localhost"
    echo "base" > base.txt
    git add base.txt
    git commit -q -m "base commit on main"
    git remote add origin "$TMPROOT/does-not-exist.git"

    git checkout -q -b milestone/vfetchfail main
    echo "change" > change.txt
    git add change.txt
    git commit -q -m "change with an unreachable origin"
)

OUT11_OUT="$(cd "$REPO4" && bash "$SCRIPT" vfetchfail 2>"$TMPROOT/case11.err")"
OUT11_STATUS=$?
OUT11_ERR="$(cat "$TMPROOT/case11.err")"
if [ "$OUT11_STATUS" -eq 0 ] \
    && printf '%s\n' "$OUT11_ERR" | grep -qi 'fetch' \
    && printf '%s\n' "$OUT11_ERR" | grep -q '^base: main '; then
    pass "Case 11: unreachable origin, no stale ref -> fetch-failed warning, falls back to local main, exit 0"
else
    fail "Case 11: unreachable origin, no stale ref -> fetch-failed warning, falls back to local main, exit 0" \
        "exit status: $OUT11_STATUS" "stderr: [$OUT11_ERR]"
fi

# --- Case 12: origin remote unreachable, but a stale origin/main ref exists -
# Same unreachable origin, but refs/remotes/origin/main already exists from
# an earlier successful fetch. The failed fetch must still warn, and the
# script must use that (possibly stale) origin/main rather than local main.
REPO5="$TMPROOT/repo5"
mkdir -p "$REPO5"
(
    cd "$REPO5" || exit 1
    git init -q -b main 2>/dev/null || { git init -q; git checkout -q -b main 2>/dev/null || true; }
    git config user.name "test-render-deploy-sheet"
    git config user.email "test-render-deploy-sheet@localhost"
    echo "base" > base.txt
    git add base.txt
    git commit -q -m "base commit on main"
    git remote add origin "$TMPROOT/does-not-exist.git"

    STALE_SHA="$(git rev-parse main)"
    git update-ref refs/remotes/origin/main "$STALE_SHA"

    git checkout -q -b milestone/vstalefetchfail main
    echo "change" > change.txt
    git add change.txt
    git commit -q -m "change with an unreachable origin and a stale origin/main ref"
)

OUT12_OUT="$(cd "$REPO5" && bash "$SCRIPT" vstalefetchfail 2>"$TMPROOT/case12.err")"
OUT12_STATUS=$?
OUT12_ERR="$(cat "$TMPROOT/case12.err")"
if [ "$OUT12_STATUS" -eq 0 ] \
    && printf '%s\n' "$OUT12_ERR" | grep -qi 'fetch' \
    && printf '%s\n' "$OUT12_ERR" | grep -q '^base: origin/main '; then
    pass "Case 12: unreachable origin with a stale origin/main ref -> fetch-failed warning, uses stale origin/main, exit 0"
else
    fail "Case 12: unreachable origin with a stale origin/main ref -> fetch-failed warning, uses stale origin/main, exit 0" \
        "exit status: $OUT12_STATUS" "stderr: [$OUT12_ERR]"
fi

# --- Case 13: neither origin/main nor local main resolves ------------------
# A repo with only the milestone branch: no local main, no origin remote at
# all. The script must exit 2 with empty stdout.
REPO6="$TMPROOT/repo6"
mkdir -p "$REPO6"
(
    cd "$REPO6" || exit 1
    git init -q -b milestone/vnomain 2>/dev/null \
        || { git init -q; git checkout -q -b milestone/vnomain 2>/dev/null || true; }
    git config user.name "test-render-deploy-sheet"
    git config user.email "test-render-deploy-sheet@localhost"
    echo "only commit" > only.txt
    git add only.txt
    git commit -q -m "only commit, on the milestone branch, no main"
)

OUT13_OUT="$(cd "$REPO6" && bash "$SCRIPT" vnomain 2>"$TMPROOT/case13.err")"
OUT13_STATUS=$?
OUT13_ERR="$(cat "$TMPROOT/case13.err")"
if [ "$OUT13_STATUS" -eq 2 ] && [ -z "$OUT13_OUT" ]; then
    pass "Case 13: neither origin/main nor local main resolves -> exit 2, empty stdout"
else
    fail "Case 13: neither origin/main nor local main resolves -> exit 2, empty stdout" \
        "exit status: $OUT13_STATUS" "stdout: [$OUT13_OUT]" "stderr: [$OUT13_ERR]"
fi

# --- Case 14: a rename must still be flagged, both for a migration and for -
# a moved page. Both files already exist on main; the milestone branch only
# renames/moves them. --no-renames is what makes a rename show as a delete
# plus an add (status D + A), so the new path is flagged as added instead of
# dropping out of both the added and modified lists with an R status.
git checkout -q main
mkdir -p database/migrations app/owner
{
    echo "<?php"
    echo "-- CREATE TRIGGER renamed_trigger"
} > database/migrations/20990101000100_a.php
echo "<?php securePage(\$php_self);" > app/old.php
git add database/migrations/20990101000100_a.php app/old.php
git commit -q -m "add migration and page later renamed/moved by a milestone"

git checkout -q -b milestone/vrenamed main
git mv database/migrations/20990101000100_a.php database/migrations/20990101000200_a.php
git mv app/old.php app/owner/new.php
git commit -q -m "rename migration and move page"

OUT14="$(bash "$SCRIPT" vrenamed 2>/dev/null)"
RC14=$?
if [ "$RC14" -eq 0 ] \
    && printf '%s\n' "$OUT14" | grep -q '^migration: TRUE$' \
    && printf '%s\n' "$OUT14" | grep -qx '  - database/migrations/20990101000200_a.php' \
    && printf '%s\n' "$OUT14" | grep -q '^trigger-migration: TRUE$' \
    && printf '%s\n' "$OUT14" | grep -q '^new-pages: TRUE$' \
    && printf '%s\n' "$OUT14" | grep -qx '  - app/owner/new.php'; then
    pass "Case 14: a renamed migration and a moved page are both flagged as added"
else
    fail "Case 14: a renamed migration and a moved page are both flagged as added" \
        "exit: $RC14" "output: [$OUT14]"
fi

git checkout -q main

# --- Case 15: a failed read of a branch file exits 2 ------------------------
# A `git` shim first on PATH fails every `git show` and passes all other
# calls to the real git. The script must exit 2, not read the failure as
# "no CREATE TRIGGER". migration: TRUE is already on stdout at that point,
# because the script prints it before it reads the migration's content.
REAL_GIT="$(command -v git)"
REPO7="$TMPROOT/repo7"
SHIM_DIR="$TMPROOT/shim"
mkdir -p "$REPO7" "$SHIM_DIR"
cat > "$SHIM_DIR/git" <<EOF
#!/bin/bash
if [ "\${1:-}" = "show" ]; then exit 1; fi
exec "$REAL_GIT" "\$@"
EOF
chmod +x "$SHIM_DIR/git"
(
    cd "$REPO7" || exit 1
    git init -q -b main 2>/dev/null || { git init -q; git checkout -q -b main 2>/dev/null || true; }
    git config user.name "test-render-deploy-sheet"
    git config user.email "test-render-deploy-sheet@localhost"
    echo "base" > base.txt
    git add base.txt
    git commit -q -m "base commit on main"

    git checkout -q -b milestone/vreadfail main
    mkdir -p database/migrations
    echo "<?php -- CREATE TRIGGER unread" > database/migrations/20990105000000_unread.php
    git add database/migrations
    git commit -q -m "add migration that the shim will not let the script read"
)

OUT15_OUT="$(cd "$REPO7" && PATH="$SHIM_DIR:$PATH" bash "$SCRIPT" vreadfail 2>"$TMPROOT/case15.err")"
RC15=$?
OUT15_ERR="$(cat "$TMPROOT/case15.err")"
if [ "$RC15" -eq 2 ] \
    && printf '%s\n' "$OUT15_ERR" | grep -q '^Could not read milestone/vreadfail:database/migrations/20990105000000_unread.php' \
    && printf '%s\n' "$OUT15_OUT" | grep -q '^migration: TRUE$' \
    && ! printf '%s\n' "$OUT15_OUT" | grep -q '^trigger-migration:'; then
    pass "Case 15: a failed git show of a branch file -> exit 2 with a Could not read message"
else
    fail "Case 15: a failed git show of a branch file -> exit 2 with a Could not read message" \
        "exit: $RC15" "stderr: [$OUT15_ERR]" "stdout: [$OUT15_OUT]"
fi

# --- Case 16: a non-ASCII page path is listed unquoted ----------------------
# Without -c core.quotePath=false, git prints the path as "app/caf\303\251.php"
# in quotes, which fails the .php match, and new-pages drops the page.
REPO8="$TMPROOT/repo8"
mkdir -p "$REPO8"
(
    cd "$REPO8" || exit 1
    git init -q -b main 2>/dev/null || { git init -q; git checkout -q -b main 2>/dev/null || true; }
    git config user.name "test-render-deploy-sheet"
    git config user.email "test-render-deploy-sheet@localhost"
    echo "base" > base.txt
    git add base.txt
    git commit -q -m "base commit on main"

    git checkout -q -b milestone/vnonascii main
    mkdir -p app
    echo "<?php securePage(\$php_self);" > "app/café.php"
    git add app
    git commit -q -m "add guarded page with a non-ASCII name"
)

OUT16="$(cd "$REPO8" && bash "$SCRIPT" vnonascii 2>/dev/null)"
RC16=$?
if [ "$RC16" -eq 0 ] \
    && printf '%s\n' "$OUT16" | grep -q '^new-pages: TRUE$' \
    && printf '%s\n' "$OUT16" | grep -qx '  - app/café.php'; then
    pass "Case 16: a non-ASCII page path is listed unquoted under new-pages"
else
    fail "Case 16: a non-ASCII page path is listed unquoted under new-pages" \
        "exit: $RC16" "output: [$OUT16]"
fi

# --- Case 17: every excluded directory, plus one control page --------------
# Each excluded directory gets a guarded .php file. Only the control page
# under app/ may appear under new-pages.
REPO9="$TMPROOT/repo9"
mkdir -p "$REPO9"
(
    cd "$REPO9" || exit 1
    git init -q -b main 2>/dev/null || { git init -q; git checkout -q -b main 2>/dev/null || true; }
    git config user.name "test-render-deploy-sheet"
    git config user.email "test-render-deploy-sheet@localhost"
    echo "base" > base.txt
    git add base.txt
    git commit -q -m "base commit on main"

    git checkout -q -b milestone/vexcluded main
    mkdir -p scripts vendor/a users tests database app
    echo "<?php securePage(\$php_self);" > scripts/x.php
    echo "<?php securePage(\$php_self);" > vendor/a/b.php
    echo "<?php securePage(\$php_self);" > users/z.php
    echo "<?php securePage(\$php_self);" > tests/t.php
    echo "<?php securePage(\$php_self);" > database/d.php
    echo "<?php securePage(\$php_self);" > app/ok.php
    git add scripts vendor users tests database app
    git commit -q -m "add guarded php files in every excluded dir, plus one control page"
)

OUT17="$(cd "$REPO9" && bash "$SCRIPT" vexcluded 2>/dev/null)"
RC17=$?
OUT17_PAGES="$(printf '%s\n' "$OUT17" | grep '^  - ')"
if [ "$RC17" -eq 0 ] \
    && printf '%s\n' "$OUT17" | grep -q '^new-pages: TRUE$' \
    && [ "$OUT17_PAGES" = "  - app/ok.php" ]; then
    pass "Case 17: new-pages skips scripts/, vendor/, users/, tests/ and database/, lists only the control page"
else
    fail "Case 17: new-pages skips scripts/, vendor/, users/, tests/ and database/, lists only the control page" \
        "exit: $RC17" "output: [$OUT17]"
fi

# --- Case 18: admin-scripts counts only added files in its scope ----------
# main has maintenance/old.php. The branch adds fix/new.php, edits old.php
# and adds other/x.php. Only fix/new.php is a new admin script. An edited
# script needs no new deploy action, and other/ is outside the scope.
REPO10="$TMPROOT/repo10"
mkdir -p "$REPO10"
(
    cd "$REPO10" || exit 1
    git init -q -b main 2>/dev/null || { git init -q; git checkout -q -b main 2>/dev/null || true; }
    git config user.name "test-render-deploy-sheet"
    git config user.email "test-render-deploy-sheet@localhost"
    mkdir -p app/admin/scripts/maintenance
    echo "<?php // original" > app/admin/scripts/maintenance/old.php
    git add app
    git commit -q -m "base commit on main with an existing maintenance script"

    git checkout -q -b milestone/vadminscripts main
    mkdir -p app/admin/scripts/fix app/admin/scripts/other
    echo "<?php // new fix" > app/admin/scripts/fix/new.php
    echo "<?php // edited" > app/admin/scripts/maintenance/old.php
    echo "<?php // out of scope" > app/admin/scripts/other/x.php
    git add app
    git commit -q -m "add a fix script, edit a maintenance script, add an out-of-scope script"
)

OUT18="$(cd "$REPO10" && bash "$SCRIPT" vadminscripts 2>/dev/null)"
RC18=$?
if [ "$RC18" -eq 0 ] \
    && printf '%s\n' "$OUT18" | grep -q '^admin-scripts: TRUE$' \
    && printf '%s\n' "$OUT18" | grep -qx '  - app/admin/scripts/fix/new.php' \
    && ! printf '%s\n' "$OUT18" | grep -q 'app/admin/scripts/maintenance/old.php' \
    && ! printf '%s\n' "$OUT18" | grep -q 'app/admin/scripts/other/x.php'; then
    pass "Case 18: admin-scripts lists an added fix script, not an edited maintenance script or an out-of-scope one"
else
    fail "Case 18: admin-scripts lists an added fix script, not an edited maintenance script or an out-of-scope one" \
        "exit: $RC18" "output: [$OUT18]"
fi

# --- Bonus: stdout contract — never carries base: or Warning ---------------
ALL_STDOUT="$OUT1
$OUT2
$OUT3
$OUT4B
$OUT4
$OUT5
$OUT6
$OUT7
$OUT8
$OUT8B
$OUT9_OUT
$OUT10_OUT
$OUT11_OUT
$OUT12_OUT
$OUT13_OUT
$OUT14
$OUT15_OUT
$OUT16
$OUT17
$OUT18"
if ! printf '%s\n' "$ALL_STDOUT" | grep -qi 'base:\|warning'; then
    pass "Bonus: stdout never contains base: or Warning across all cases"
else
    fail "Bonus: stdout never contains base: or Warning across all cases" \
        "stdout: [$ALL_STDOUT]"
fi

git checkout -q main

harness_report
