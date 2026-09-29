#!/bin/bash
#
# Prints a fingerprint of a branch's changes: the SHA-256 of the diff from the
# merge base to the working tree, untracked files included. /execute-plan
# Step 7 records the fingerprint with the review lanes that ran clean.
# /review-pr skips a lane that is clean at the same fingerprint, so an
# unchanged diff is not reviewed twice (#2259).
#
# Usage: scripts/review-fingerprint.sh [base-ref]
#   base-ref  Optional. The ref to diff against. Defaults to the output of
#             scripts/resolve-base-branch.sh.
#
# WHY THE WORKING TREE, NOT HEAD. /execute-plan reviews changes before they
# are committed, and /review-pr reviews them after /commit. A HEAD-only
# fingerprint would differ between the two for the same content. This script
# stages the working tree into a temporary index, so the real index is not
# changed, and hashes `git diff --cached <merge-base>`. The same content gives
# the same fingerprint, committed or not. Files that .gitignore excludes
# (docs/plans/) are not part of it.
#
# On stdout: the 64-character hex fingerprint.
#
# Exit codes:
#   0  Fingerprint printed.
#   1  Usage error, or the base ref or merge base could not be found.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "$#" -gt 1 ]; then
    echo "Usage: $(basename "$0") [base-ref]" >&2
    exit 1
fi

base="${1:-}"
if [ -z "$base" ]; then
    if ! base="$("${SCRIPT_DIR}/resolve-base-branch.sh")"; then
        echo "review-fingerprint.sh: could not resolve a base ref." >&2
        exit 1
    fi
fi

if ! merge_base="$(git merge-base HEAD "$base" 2>/dev/null)"; then
    echo "review-fingerprint.sh: no merge base between HEAD and '$base'." >&2
    exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

export GIT_INDEX_FILE="${tmp_dir}/index"
git read-tree HEAD
git add -A
git diff --cached --binary --no-color --no-ext-diff "$merge_base" \
    | shasum -a 256 | cut -d' ' -f1
