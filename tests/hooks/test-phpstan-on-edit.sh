#!/bin/bash
#
# Regression test for .claude/hooks/phpstan-on-edit.sh: a new PHPStan error
# in a project PHP file exits 2 with the error on stderr; a clean file, a
# non-PHP file, and an upstream users/ file exit 0.
#
# Needs vendor/bin/phpstan. Without it the hook exits 0 by design, so the
# error case is skipped and reported, not passed.
#
# Usage: bash tests/hooks/test-phpstan-on-edit.sh
# Exit code: 0 if all cases pass, 1 otherwise.

set -u
cd "$(dirname "$0")/../.." || exit 1
ROOT="$PWD"
hook=.claude/hooks/phpstan-on-edit.sh
planted="app/_phpstan_hook_test_tmp.php"
fail=0
trap 'rm -f "$ROOT/$planted"' EXIT

run_hook() {
  jq -nc --arg f "$ROOT/$1" '{tool_input:{file_path:$f}}' \
    | CLAUDE_PROJECT_DIR="$ROOT" bash "$hook" 2>"$ROOT/.phpstan-hook-test.err"
  code=$?
  err="$(cat "$ROOT/.phpstan-hook-test.err")"
  rm -f "$ROOT/.phpstan-hook-test.err"
  return 0
}

check() {
  local expected="$1" path="$2" label="$3"
  run_hook "$path"
  if [ "$code" = "$expected" ]; then
    echo "ok    exit $code  $label"
  else
    echo "FAIL  expected exit $expected, got $code  $label"
    fail=1
  fi
}

check 0 "CLAUDE.md" "non-PHP file is ignored"
check 0 "users/init.php" "upstream users/ file is ignored"
check 0 "usersc/classes/Input.php" "clean project file passes"

if [ -x vendor/bin/phpstan ]; then
  printf '<?php\ndeclare(strict_types=1);\nfunction phpstan_hook_test_tmp(): int { return "x"; }\n' > "$planted"
  check 2 "$planted" "new PHPStan error exits 2"
  if printf '%s' "$err" | grep -q "$planted:3:"; then
    echo "ok    stderr names $planted:3"
  else
    echo "FAIL  stderr does not name $planted:3: $err"
    fail=1
  fi
else
  echo "skip  vendor/bin/phpstan missing: error case not tested"
fi

exit "$fail"
