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
# phpstan-baseline.neon entry. Exits 0 always (callers decide how to react;
# see CODING_STANDARDS.md — PHPStan Baseline Hygiene for the decision tree).

set -euo pipefail

BASELINE_FILE="${BASELINE_FILE:-phpstan-baseline.neon}"

while IFS= read -r f; do
  [ -z "$f" ] && continue
  case "$f" in
    *.php)
      if grep -qF "path: $f" "$BASELINE_FILE" 2>/dev/null; then
        echo "BASELINE OVERRIDE: $f"
      fi
      ;;
  esac
done
