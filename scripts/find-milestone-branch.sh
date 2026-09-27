#!/bin/bash
#
# Finds the milestone branch for a version, whether it exists only locally,
# only on origin, or both. start-issue.md previously repeated this
# local-vs-origin discovery inline (Step 3); start-milestone.md calls this
# same script when it needs to check whether a milestone branch already
# exists before creating one.
#
# Usage: scripts/find-milestone-branch.sh <version>
#   version  Required. E.g. v2.30.4 or milestone/v2.30.4 (the leading
#            "milestone/" is optional and stripped if given).
#
# Prints "milestone/<version>" on stdout when found. Does not fetch, check
# out, or create anything — callers act on the printed name.
#
# Exit codes:
#   0  Found (locally, on origin, or both). Name printed on stdout.
#   1  Not found, locally or on origin.
#   2  Usage error (missing or malformed argument).

set -euo pipefail

if [ "$#" -ne 1 ] || [ -z "$1" ]; then
    echo "Usage: $(basename "$0") <version>" >&2
    exit 2
fi

version="${1#milestone/}"
branch="milestone/${version}"

if git show-ref --verify --quiet "refs/heads/${branch}"; then
    printf '%s\n' "$branch"
    exit 0
fi

if git ls-remote --exit-code --heads origin "$branch" >/dev/null 2>&1; then
    printf '%s\n' "$branch"
    exit 0
fi

echo "find-milestone-branch.sh: '$branch' not found locally or on origin." >&2
exit 1
