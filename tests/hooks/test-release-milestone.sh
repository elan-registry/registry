#!/bin/bash
#
# Tests for scripts/release-milestone.sh: the full release run, and the
# resume path after the script stops part way.
#
# HERMETIC: each case builds a bare "origin" repo and a working clone under
# a temp dir. Real git runs against them, so every push goes to the temp
# bare repo. A stub `gh` goes first on PATH. It keeps the PR, release and
# milestone state in files, does the PR merge with real git in a scratch
# clone of the temp origin, and exits 99 on any argv it does not expect.
# No network calls. Never touches this repo's branches, tags, or remotes.
#
# Usage: bash tests/hooks/test-release-milestone.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/release-milestone.sh"
if [ ! -x "$SCRIPT" ]; then
    echo "FAIL: $SCRIPT not found or not executable" >&2
    exit 1
fi

TMPROOT="$(mktemp -d)" || exit 1

export GIT_AUTHOR_NAME="test-release-milestone"
export GIT_AUTHOR_EMAIL="test-release-milestone@localhost"
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME"
export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"

VERSION="v9.9.9"
PR=42
MS=7
NOTES_REL="docs/releases/RELEASE_NOTES_${VERSION}.md"
KEEP_REL="docs/plans/releases/${VERSION}-release-notes.md"
NOTES_TEXT="Release notes body for ${VERSION}."

STUBDIR="$TMPROOT/bin"
mkdir -p "$STUBDIR"
cat > "$STUBDIR/gh" <<'STUB'
#!/bin/bash
# State files in $STUB_STATE: pr_state, mergeable, merge_sha, calls,
# release_notes (present once the release exists), milestone_state,
# fail_release (present -> release create fails), push_after_merge
# (present -> one more commit lands on main right after the merge).
S="$STUB_STATE"
echo "$*" >> "$S/calls"
want_pr="${STUB_PR:-42}"
case "$*" in
    "pr view $want_pr --json state --jq .state")
        cat "$S/pr_state" ;;
    "pr view $want_pr --json mergeable --jq .mergeable")
        cat "$S/mergeable" ;;
    "pr view $want_pr --json mergeCommit --jq .mergeCommit.oid")
        cat "$S/merge_sha" ;;
    "pr merge $want_pr --merge --delete-branch")
        rm -rf "$S/merger"
        git clone -q "$STUB_ORIGIN" "$S/merger" || exit 1
        (
            cd "$S/merger" || exit 1
            git checkout -q main
            git merge -q --no-ff -m "Merge pull request #$want_pr" "origin/milestone/v9.9.9" || exit 1
            git push -q origin main || exit 1
            git push -q origin --delete milestone/v9.9.9 || exit 1
            git rev-parse HEAD > "$S/merge_sha"
            if [ -f "$S/push_after_merge" ]; then
                echo later > later.txt
                git add later.txt
                git commit -q -m "commit after the merge"
                git push -q origin main || exit 1
            fi
        ) || exit 1
        echo MERGED > "$S/pr_state" ;;
    "api repos/elan-registry/registry/milestones/7 --jq .title")
        echo "v9.9.9: Test milestone" ;;
    "api repos/elan-registry/registry/milestones/7 -X PATCH -f state=closed")
        echo closed > "$S/milestone_state" ;;
    "release view v9.9.9 --repo elan-registry/registry")
        [ -f "$S/release_notes" ] || exit 1 ;;
    "release create v9.9.9 --repo elan-registry/registry --title Elan Registry v9.9.9 --notes-file "*" --verify-tag --draft")
        if [ -f "$S/fail_release" ]; then
            echo "stub gh: simulated release create failure" >&2
            exit 1
        fi
        args="$*"
        notes="${args#*--notes-file }"
        notes="${notes%% --verify-tag*}"
        cp "$notes" "$S/release_notes" ;;
    *)
        echo "stub gh: unexpected argv: $*" >&2
        exit 99 ;;
esac
STUB
chmod +x "$STUBDIR/gh"

# A git wrapper that fails `git show` when FAIL_GIT_SHOW is set, and runs
# the real git for everything else.
REAL_GIT="$(command -v git)"
GITSTUBDIR="$TMPROOT/gitbin"
mkdir -p "$GITSTUBDIR"
cat > "$GITSTUBDIR/git" <<STUB
#!/bin/bash
if [ "\$1" = show ] && [ -n "\${FAIL_GIT_SHOW:-}" ]; then
    echo "stub git: simulated show failure" >&2
    exit 128
fi
exec "$REAL_GIT" "\$@"
STUB
chmod +x "$GITSTUBDIR/git"

# Builds a fresh origin, working clone and stub state for one case, and
# sets ORIGIN, WORK and STATE.
setup_case() {
    local dir="$TMPROOT/$1"
    ORIGIN="$dir/origin.git"
    WORK="$dir/work"
    STATE="$dir/state"
    mkdir -p "$STATE"
    git init -q --bare "$ORIGIN"
    git -C "$ORIGIN" symbolic-ref HEAD refs/heads/main
    git clone -q "$ORIGIN" "$WORK" 2>/dev/null
    (
        cd "$WORK" || exit 1
        git checkout -q -b main
        echo base > README.md
        git add README.md
        git commit -q -m base
        git push -q origin main
        git checkout -q -b "milestone/${VERSION}"
        mkdir -p docs/releases
        echo "$NOTES_TEXT" > "$NOTES_REL"
        echo feature > feature.txt
        git add "$NOTES_REL" feature.txt
        git commit -q -m "milestone work"
        git push -q origin "milestone/${VERSION}"
    ) || exit 1
    echo OPEN > "$STATE/pr_state"
    echo MERGEABLE > "$STATE/mergeable"
    : > "$STATE/calls"
}

run_release() {
    (cd "$WORK" && PATH="$STUBDIR:$PATH" STUB_STATE="$STATE" STUB_ORIGIN="$ORIGIN" "$SCRIPT" "$@")
}

# Checks the end state of a completed release. Prints a reason and returns
# 1 on the first check that fails.
check_released() {
    local tag_sha merge_sha
    [ "$(cat "$STATE/pr_state")" = "MERGED" ] || { echo "PR not merged"; return 1; }
    [ "$(grep -c '^pr merge' "$STATE/calls")" -eq 1 ] || { echo "pr merge not called exactly once"; return 1; }
    [ "$(cat "$STATE/release_notes" 2>/dev/null)" = "$NOTES_TEXT" ] || { echo "release notes content wrong"; return 1; }
    [ "$(cat "$STATE/milestone_state" 2>/dev/null)" = "closed" ] || { echo "milestone not closed"; return 1; }
    tag_sha="$(git -C "$ORIGIN" rev-parse "${VERSION}^{commit}" 2>/dev/null)" || { echo "tag not on origin"; return 1; }
    merge_sha="$(cat "$STATE/merge_sha")"
    [ "$tag_sha" = "$merge_sha" ] || { echo "tag not on merge commit"; return 1; }
    git -C "$ORIGIN" cat-file -e "main:${NOTES_REL}" 2>/dev/null && { echo "notes file still on main"; return 1; }
    [ ! -e "$WORK/$KEEP_REL" ] || { echo "saved notes copy not removed"; return 1; }
    return 0
}

# --- Case 1: full run -> 0 ------------------------------------------------
setup_case case1
OUT1="$(run_release "$VERSION" "$PR" "$MS" 2>&1)"
STATUS1=$?
WHY1="$(check_released)"
if [ "$STATUS1" -eq 0 ] && [ -z "$WHY1" ]; then
    pass "Case 1: full run -> exit 0, merged, tagged, released, milestone closed"
else
    fail "Case 1: full run -> exit 0" "exit: $STATUS1 (want 0)" "check: $WHY1" "output: [$OUT1]"
fi

# --- Case 2: stop after notes removal, then resume -> 0 ------------------
# The first run removes and pushes the notes, then stops on "not
# mergeable". The saved copy is deleted too, so the resume must restore it
# from history.
setup_case case2
echo CONFLICTING > "$STATE/mergeable"
OUT2A="$(run_release "$VERSION" "$PR" "$MS" 2>&1)"
STATUS2A=$?
REMOVED_ON_ORIGIN=1
git -C "$ORIGIN" cat-file -e "milestone/${VERSION}:${NOTES_REL}" 2>/dev/null && REMOVED_ON_ORIGIN=0
SAVED_COPY=0
[ "$(cat "$WORK/$KEEP_REL" 2>/dev/null)" = "$NOTES_TEXT" ] && SAVED_COPY=1
if [ "$STATUS2A" -eq 2 ] && [ "$REMOVED_ON_ORIGIN" -eq 1 ] && [ "$SAVED_COPY" -eq 1 ]; then
    pass "Case 2a: not mergeable after removal -> exit 2, removal pushed, notes saved in docs/plans/releases/"
else
    fail "Case 2a: not mergeable after removal -> exit 2" "exit: $STATUS2A (want 2)" \
        "removed on origin: $REMOVED_ON_ORIGIN, saved copy: $SAVED_COPY" "output: [$OUT2A]"
fi
rm -f "$WORK/$KEEP_REL"
echo MERGEABLE > "$STATE/mergeable"
OUT2B="$(run_release "$VERSION" "$PR" "$MS" 2>&1)"
STATUS2B=$?
WHY2="$(check_released)"
REMOVALS="$(git -C "$ORIGIN" log --full-history --oneline main -- "$NOTES_REL" | grep -c 'remove v9.9.9 release notes')"
if [ "$STATUS2B" -eq 0 ] && [ -z "$WHY2" ] && [ "$REMOVALS" -eq 1 ] \
    && printf '%s' "$OUT2B" | grep -q 'already removed in'; then
    pass "Case 2b: resume -> exit 0, one removal commit, notes restored from history"
else
    fail "Case 2b: resume -> exit 0" "exit: $STATUS2B (want 0)" "check: $WHY2" \
        "removal commits: $REMOVALS (want 1)" "output: [$OUT2B]"
fi

# --- Case 3: release create fails, then resume -> 0 ----------------------
setup_case case3
touch "$STATE/fail_release"
OUT3A="$(run_release "$VERSION" "$PR" "$MS" 2>&1)"
STATUS3A=$?
if [ "$STATUS3A" -eq 2 ] && printf '%s' "$OUT3A" | grep -q 'run the script again'; then
    pass "Case 3a: release create fails after merge and tag -> exit 2 with resume hint"
else
    fail "Case 3a: release create fails -> exit 2" "exit: $STATUS3A (want 2)" "output: [$OUT3A]"
fi
rm -f "$STATE/fail_release" "$WORK/$KEEP_REL"
OUT3B="$(run_release "$VERSION" "$PR" "$MS" 2>&1)"
STATUS3B=$?
WHY3="$(check_released)"
if [ "$STATUS3B" -eq 0 ] && [ -z "$WHY3" ] \
    && printf '%s' "$OUT3B" | grep -q 'already merged — skipping' \
    && printf '%s' "$OUT3B" | grep -q "Tag ${VERSION} already exists on the merge commit"; then
    pass "Case 3b: resume -> exit 0, merge and tag skipped, release created from restored notes"
else
    fail "Case 3b: resume -> exit 0" "exit: $STATUS3B (want 0)" "check: $WHY3" "output: [$OUT3B]"
fi

# --- Case 4: second run after a full release -> 0, nothing repeated ------
OUT4="$(run_release "$VERSION" "$PR" "$MS" 2>&1)"
STATUS4=$?
if [ "$STATUS4" -eq 0 ] && [ "$(grep -c '^release create' "$STATE/calls")" -eq 2 ] \
    && printf '%s' "$OUT4" | grep -q "Release ${VERSION} already exists"; then
    pass "Case 4: run after a full release -> exit 0, no new merge or release"
else
    fail "Case 4: run after a full release -> exit 0" "exit: $STATUS4 (want 0)" "output: [$OUT4]"
fi

# --- Case 5: notes file never on the branch -> 1 ------------------------
setup_case case5
(
    cd "$WORK" || exit 1
    git checkout -q main
    git branch -q -D "milestone/${VERSION}"
    git checkout -q -b "milestone/${VERSION}"
    echo feature > feature.txt
    git add feature.txt
    git commit -q -m "milestone work without notes"
    git push -q -f origin "milestone/${VERSION}"
) || exit 1
OUT5="$(run_release "$VERSION" "$PR" "$MS" 2>&1)"
STATUS5=$?
if [ "$STATUS5" -eq 1 ] && printf '%s' "$OUT5" | grep -q 'no commit on the branch removed it' \
    && ! grep -q '^pr merge' "$STATE/calls"; then
    pass "Case 5: notes file never on the branch -> exit 1, no merge"
else
    fail "Case 5: notes file never on the branch -> exit 1" "exit: $STATUS5 (want 1)" "output: [$OUT5]"
fi

# --- Case 6: deploy remote argument -> 1 ---------------------------------
setup_case case6
OUT6="$(run_release "$VERSION" prod "$MS" 2>&1)"
STATUS6=$?
if [ "$STATUS6" -eq 1 ] && printf '%s' "$OUT6" | grep -q "Refusing: 'prod'"; then
    pass "Case 6: 'prod' argument -> exit 1"
else
    fail "Case 6: 'prod' argument -> exit 1" "exit: $STATUS6 (want 1)" "output: [$OUT6]"
fi

# --- Case 7: dry run -> 0, nothing changes -------------------------------
setup_case case7
BEFORE="$(git -C "$ORIGIN" for-each-ref)"
OUT7="$(run_release --dry-run "$VERSION" "$PR" "$MS" 2>&1)"
STATUS7=$?
AFTER="$(git -C "$ORIGIN" for-each-ref)"
if [ "$STATUS7" -eq 0 ] && [ "$BEFORE" = "$AFTER" ] \
    && ! grep -qE '^(pr merge|release create|api .* -X PATCH)' "$STATE/calls" \
    && printf '%s' "$OUT7" | grep -q "\[dry-run\] cp ${NOTES_REL} ${KEEP_REL}"; then
    pass "Case 7: dry run -> exit 0, no ref or gh state change"
else
    fail "Case 7: dry run -> exit 0" "exit: $STATUS7 (want 0)" "output: [$OUT7]"
fi

# --- Case 8: a commit lands on main after the merge -> tag on merge ----
setup_case case8
touch "$STATE/push_after_merge"
OUT8="$(run_release "$VERSION" "$PR" "$MS" 2>&1)"
STATUS8=$?
WHY8="$(check_released)"
if [ "$STATUS8" -eq 0 ] && [ -z "$WHY8" ]; then
    pass "Case 8: commit on main after the merge -> exit 0, tag on the merge commit, not HEAD"
else
    fail "Case 8: commit on main after the merge -> tag on merge commit" "exit: $STATUS8 (want 0)" \
        "check: $WHY8" "output: [$OUT8]"
fi

# --- Case 9: local main diverged from origin/main -> 1, no merge ---------
setup_case case9
(
    cd "$WORK" || exit 1
    git checkout -q main
    echo stray > stray.txt
    git add stray.txt
    git commit -q -m "stray local commit"
    git checkout -q "milestone/${VERSION}"
) || exit 1
OTHER="$TMPROOT/case9/other"
git clone -q "$ORIGIN" "$OTHER" 2>/dev/null
(
    cd "$OTHER" || exit 1
    git checkout -q main
    echo remote > remote.txt
    git add remote.txt
    git commit -q -m "remote commit"
    git push -q origin main
) || exit 1
OUT9="$(run_release "$VERSION" "$PR" "$MS" 2>&1)"
STATUS9=$?
# Exit 1 means "nothing changed", so the check must run before Step 6
# removes the notes and pushes the milestone branch.
NOTES_ON_ORIGIN9=0
git -C "$ORIGIN" cat-file -e "milestone/${VERSION}:${NOTES_REL}" 2>/dev/null && NOTES_ON_ORIGIN9=1
if [ "$STATUS9" -eq 1 ] && printf '%s' "$OUT9" | grep -q 'commit(s) not on origin/main' \
    && ! grep -q '^pr merge' "$STATE/calls" && [ "$NOTES_ON_ORIGIN9" -eq 1 ]; then
    pass "Case 9: diverged local main -> exit 1 with the stray-commit message, no push, no merge"
else
    fail "Case 9: diverged local main -> exit 1, no push" "exit: $STATUS9 (want 1)" \
        "notes still on origin milestone branch: $NOTES_ON_ORIGIN9 (want 1)" "output: [$OUT9]"
fi

# --- Case 10: notes restore fails -> 2, no empty notes copy --------------
setup_case case10
touch "$STATE/fail_release"
run_release "$VERSION" "$PR" "$MS" >/dev/null 2>&1
rm -f "$STATE/fail_release" "$WORK/$KEEP_REL"
OUT10="$(cd "$WORK" && PATH="$GITSTUBDIR:$STUBDIR:$PATH" FAIL_GIT_SHOW=1 STUB_STATE="$STATE" \
    STUB_ORIGIN="$ORIGIN" "$SCRIPT" "$VERSION" "$PR" "$MS" 2>&1)"
STATUS10=$?
LEFTOVER="$(find "$WORK/docs/plans/releases" -name "${VERSION}-release-notes.md*" 2>/dev/null)"
if [ "$STATUS10" -eq 2 ] && [ -z "$LEFTOVER" ] && [ ! -f "$STATE/release_notes" ] \
    && printf '%s' "$OUT10" | grep -q 'Could not restore'; then
    pass "Case 10: git show fails during restore -> exit 2 with the restore message, no notes copy left behind"
else
    fail "Case 10: git show fails during restore -> exit 2" "exit: $STATUS10 (want 2)" \
        "left behind: [$LEFTOVER]" "output: [$OUT10]"
fi

harness_report
