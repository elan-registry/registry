#!/bin/bash
#
# Regression test for scripts/review-fingerprint.sh.
#
# HERMETIC: builds a throwaway repo (mktemp -d) and passes the base ref
# explicitly, so real repo refs are never read.
#
# Usage: bash tests/hooks/test-review-fingerprint.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY

export GIT_AUTHOR_NAME="test-review-fingerprint"
export GIT_AUTHOR_EMAIL="test-review-fingerprint@localhost"
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME"
export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/review-fingerprint.sh"
if [ ! -x "$SCRIPT" ]; then
    echo "FAIL: $SCRIPT not found or not executable" >&2
    exit 1
fi

TMPROOT="$(mktemp -d)" || exit 1

fp() { "$SCRIPT" base 2>/dev/null; }

REPO="$TMPROOT/repo"
git init -q "$REPO" >/dev/null 2>&1
cd "$REPO" || exit 1
git config user.name "$GIT_AUTHOR_NAME"
git config user.email "$GIT_AUTHOR_EMAIL"
git config commit.gpgsign false
git symbolic-ref HEAD refs/heads/base
echo base > README.md
echo 'ignored.txt' > .gitignore
git add README.md .gitignore
git commit -q -m base
git checkout -q -b feature

# 1. An empty diff gives a fingerprint.
empty="$(fp)"
if [[ "$empty" =~ ^[0-9a-f]{64}$ ]]; then
    pass "prints a 64-character hex fingerprint"
else
    fail "prints a 64-character hex fingerprint" "got: '$empty'"
fi

# 2. An uncommitted change changes the fingerprint.
echo change >> README.md
uncommitted="$(fp)"
if [ "$uncommitted" != "$empty" ]; then
    pass "an uncommitted change changes the fingerprint"
else
    fail "an uncommitted change changes the fingerprint"
fi

# 3. The real index is not changed.
if [ -z "$(git diff --cached --name-only)" ]; then
    pass "leaves the real index unchanged"
else
    fail "leaves the real index unchanged" "staged: $(git diff --cached --name-only | tr '\n' ' ')"
fi

# 4. Committing the same content keeps the fingerprint.
git commit -q -am change
committed="$(fp)"
if [ "$committed" = "$uncommitted" ]; then
    pass "same content gives the same fingerprint, committed or not"
else
    fail "same content gives the same fingerprint, committed or not" \
        "uncommitted=$uncommitted" "committed=$committed"
fi

# 5. An untracked file changes the fingerprint.
echo new > new.txt
with_untracked="$(fp)"
if [ "$with_untracked" != "$committed" ]; then
    pass "an untracked file changes the fingerprint"
else
    fail "an untracked file changes the fingerprint"
fi
rm -f new.txt

# 6. An ignored file does not change the fingerprint.
echo private > ignored.txt
with_ignored="$(fp)"
if [ "$with_ignored" = "$committed" ]; then
    pass "an ignored file does not change the fingerprint"
else
    fail "an ignored file does not change the fingerprint"
fi

# 7. An unknown base ref exits 1.
"$SCRIPT" no-such-ref >/dev/null 2>&1
rc=$?
if [ "$rc" -eq 1 ]; then
    pass "an unknown base ref exits 1"
else
    fail "an unknown base ref exits 1" "exit=$rc"
fi

# 8. Too many arguments exit 1.
"$SCRIPT" base extra >/dev/null 2>&1
rc=$?
if [ "$rc" -eq 1 ]; then
    pass "too many arguments exit 1"
else
    fail "too many arguments exit 1" "exit=$rc"
fi

harness_report
