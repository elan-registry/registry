#!/bin/bash
#
# Regression test for .claude/hooks/guard-private-paths.sh: a forced
# `git add` of docs/plans/ or _noupload/ is denied; everything else passes.
#
# Usage: bash tests/hooks/test-guard-private-paths.sh
# Exit code: 0 if all cases pass, 1 otherwise.

set -u
cd "$(dirname "$0")/../.." || exit 1
hook=.claude/hooks/guard-private-paths.sh
fail=0

check() {
  local expected="$1" cmd="$2" got
  got="$(jq -nc --arg c "$cmd" '{tool_input:{command:$c}}' | bash "$hook" \
    | jq -r '.hookSpecificOutput.permissionDecision // empty')"
  got="${got:-allow}"
  if [ "$got" = "$expected" ]; then
    echo "ok    $expected  $cmd"
  else
    echo "FAIL  expected $expected, got $got  $cmd"
    fail=1
  fi
}

check deny  'git add -f docs/plans/issues/x.md'
check deny  'git add --force _noupload/audit-reports/a.md'
check deny  'git add -Af ./docs/plans'
check deny  'cd x && git -C . add -f docs/plans/a'
check allow 'git add docs/plans/x.md'
check allow 'git add -f scripts/x.sh'
check allow 'git add -f scripts/x.sh && cat docs/plans/README.md'
check allow 'git commit -m "mention git add -f docs/plans"'
check allow 'git status'

exit "$fail"
