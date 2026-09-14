#!/usr/bin/env bash
# Verifies a milestone version is strictly newer than the repo's last
# release tag. Pure mechanical semver comparison — no judgment call, so it
# doesn't belong as prose an LLM has to execute and reason about manually
# (lexicographic string comparison would wrongly rank v2.9.0 > v2.10.0).
#
# Usage: scripts/check-version-newer.sh <candidate-version> [<last-tag>]
#
# <candidate-version>: e.g. v2.17.0 (the 'v' prefix is optional, accepted
#   either way for caller convenience).
# <last-tag>: optional — defaults to `git describe --tags --abbrev=0` in the
#   current repo. Pass explicitly to check against something other than the
#   working tree's current tag history (e.g. in a worktree or CI checkout
#   without full tag history fetched).
#
# Exit codes:
#   0 = candidate is strictly newer than the last tag — proceed
#   1 = candidate is NOT newer (equal or older) — do not proceed
#   2 = could not parse one or both versions as semver — ambiguous, stop and ask

set -euo pipefail

CANDIDATE="${1:?Usage: check-version-newer.sh <candidate-version> [<last-tag>]}"
LAST_TAG="${2:-$(git describe --tags --abbrev=0 2>/dev/null || true)}"

if [ -z "$LAST_TAG" ]; then
  echo "No prior tag found (git describe --tags --abbrev=0 returned nothing) — nothing to compare against." >&2
  echo "If this is genuinely the first release, that's expected; otherwise investigate before proceeding." >&2
  exit 2
fi

strip_v() { echo "${1#v}"; }

parse_semver() {
  local v
  v="$(strip_v "$1")"
  if [[ ! "$v" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
    return 1
  fi
  echo "${BASH_REMATCH[1]} ${BASH_REMATCH[2]} ${BASH_REMATCH[3]}"
}

CANDIDATE_PARSED="$(parse_semver "$CANDIDATE")" || {
  echo "Could not parse candidate version '$CANDIDATE' as semver (expected vX.Y.Z)." >&2
  exit 2
}
LAST_PARSED="$(parse_semver "$LAST_TAG")" || {
  echo "Could not parse last tag '$LAST_TAG' as semver (expected vX.Y.Z)." >&2
  exit 2
}

read -r c_major c_minor c_patch <<< "$CANDIDATE_PARSED"
read -r l_major l_minor l_patch <<< "$LAST_PARSED"

is_newer=0
if [ "$c_major" -gt "$l_major" ]; then
  is_newer=1
elif [ "$c_major" -eq "$l_major" ] && [ "$c_minor" -gt "$l_minor" ]; then
  is_newer=1
elif [ "$c_major" -eq "$l_major" ] && [ "$c_minor" -eq "$l_minor" ] && [ "$c_patch" -gt "$l_patch" ]; then
  is_newer=1
fi

if [ "$is_newer" -eq 1 ]; then
  echo "$CANDIDATE is newer than $LAST_TAG"
  exit 0
else
  echo "$CANDIDATE is NOT newer than $LAST_TAG (equal or older)" >&2
  exit 1
fi
