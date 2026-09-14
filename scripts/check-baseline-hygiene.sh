#!/usr/bin/env bash
# Fix-when-you-touch-it PHPStan baseline hygiene check.
#
# phpstan.neon includes phpstan-baseline.neon, so a normal `phpstan analyse`
# run silently suppresses every pre-existing baseline entry for a file — it
# only ever reports *new* errors. This script is the only reliable way to
# see whether a file this change touched still carries old baseline debt.
#
# Usage: pass the list of changed *.php paths on stdin, one per line.
# Callers derive that list however fits their context (git diff, gh pr view
# --json files, working-tree status) — this script only does the baseline
# lookup, not the diffing.
#
# Prints "BASELINE OVERRIDE: <path>" for each touched file that still has a
# phpstan-baseline.neon entry (see CODING_STANDARDS.md — PHPStan Baseline
# Hygiene for the decision tree). Exits:
#   0 = check ran; see output for any BASELINE OVERRIDE lines (none means
#       clean)
#   2 = could not run the check at all (baseline file not found at the
#       expected path). This is NOT the same as "clean" — a missing
#       baseline file that silently produced no output would be
#       indistinguishable from a genuinely clean result, which is exactly
#       the failure this exit code exists to prevent. Run from the repo
#       root, or set BASELINE_FILE explicitly if calling from elsewhere.

set -euo pipefail

BASELINE_FILE="${BASELINE_FILE:-phpstan-baseline.neon}"

if [ ! -f "$BASELINE_FILE" ]; then
  echo "check-baseline-hygiene.sh: baseline file '$BASELINE_FILE' not found (cwd: $PWD)." >&2
  echo "Cannot verify baseline hygiene — this is NOT evidence of a clean baseline." >&2
  exit 2
fi

while IFS= read -r f; do
  [ -z "$f" ] && continue
  case "$f" in
    *.php)
      if grep -qF "path: $f" "$BASELINE_FILE"; then
        echo "BASELINE OVERRIDE: $f"
      fi
      ;;
  esac
done

exit 0
