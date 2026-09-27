#!/usr/bin/env bash
# Runs the full pre-review verification suite (unit + integration tests,
# docs check, PHPStan) and parses the real result out of PHPUnit's summary
# line — because the exit code alone is not proof the suite ran.
#
# WHY this exists (both failure modes verified against this repo):
#   1. An unreachable database exits 0 with zero tests run. UserSpice's
#      connection failure calls an uncatchable die() in users/classes/DB.php
#      (gitignored upstream — grep for it, don't rely on a line number)
#      during bootstrap, so PHPUnit never gets to print a summary. Observed
#      output is exactly two lines — "NOTE: Loaded test environment from
#      .env.test.local" and "Could not connect to database. Please check
#      your configuration." — and $? is 0.
#   2. Individually skipped tests also exit 0. IntegrationTestCase::
#      requireDatabase() calls markTestSkipped(), and neither
#      phpunit-unit.xml nor phpunit-integration.xml sets failOnSkipped,
#      failOnWarning, or failOnRisky.
#   3. `test:full` is two separate PHPUnit invocations (unit, then
#      integration) — there is no combined total line, so both summaries
#      must be checked independently. Checking only the first hides a dead
#      second suite.
#
# This script is the parsing logic every command (`review-pr`,
# `finish-milestone`, `execute-plan`, `finish-issue`) used to re-derive
# inline. Keep the interpretation rules here in one place.
#
# Usage: scripts/run-verification-suite.sh
#   (run from the repo root; no arguments)
#
# Exit codes:
#   0 = both PHPUnit suites reported a clean, non-empty OK line, docs check
#       passed, and PHPStan reported no errors
#   1 = at least one component failed, was empty, skipped, warned, or
#       otherwise did not cleanly pass — see stdout for which
#   2 = a component could not run at all (missing composer, missing
#       vendor/bin/phpstan, etc.) — this is NOT the same as "failed", it
#       means the check itself never executed
set -uo pipefail

FAIL=0

strip_ansi() { sed 's/\x1b\[[0-9;]*m//g'; }

echo "== composer test:full =="
if ! command -v composer >/dev/null 2>&1; then
  echo "composer not found on PATH — cannot run the suite." >&2
  exit 2
fi

TEST_OUTPUT="$(composer test:full 2>&1)"
TEST_EXIT=$?
SUMMARY_LINES="$(printf '%s\n' "$TEST_OUTPUT" | strip_ansi | grep -E '^(OK|OK, but|FAILURES|ERRORS|WARNINGS|Tests:|No tests executed)')"

echo "$SUMMARY_LINES"

OK_COUNT="$(printf '%s\n' "$SUMMARY_LINES" | grep -c '^OK (')"
ZERO_COUNT_HIT="$(printf '%s\n' "$SUMMARY_LINES" | grep -cE '^OK \(0 tests')"

if [ "$TEST_EXIT" -ne 0 ]; then
  echo "RESULT test:full = FAIL (non-zero exit $TEST_EXIT)"
  FAIL=1
elif [ -z "$SUMMARY_LINES" ]; then
  echo "RESULT test:full = FAIL (no summary line — bootstrap likely died before PHPUnit reported)"
  FAIL=1
elif [ "$OK_COUNT" -ne 2 ]; then
  echo "RESULT test:full = FAIL (expected 2 clean 'OK (N tests, M assertions)' lines — unit + integration — got $OK_COUNT)"
  FAIL=1
elif [ "$ZERO_COUNT_HIT" -gt 0 ]; then
  echo "RESULT test:full = FAIL (a suite reported OK with 0 tests — it ran nothing)"
  FAIL=1
else
  echo "RESULT test:full = PASS ($OK_COUNT suites, both non-zero)"
fi

echo
echo "== composer check:docs =="
if ! command -v composer >/dev/null 2>&1; then
  echo "composer not found on PATH — cannot run the docs check." >&2
  exit 2
fi
DOCS_OUTPUT="$(composer check:docs 2>&1)"
DOCS_EXIT=$?
echo "$DOCS_OUTPUT" | tail -5
if [ "$DOCS_EXIT" -ne 0 ]; then
  echo "RESULT check:docs = FAIL (exit $DOCS_EXIT)"
  FAIL=1
else
  echo "RESULT check:docs = PASS"
fi

echo
echo "== vendor/bin/phpstan analyse =="
if [ ! -x vendor/bin/phpstan ]; then
  echo "vendor/bin/phpstan not found — run composer install first." >&2
  exit 2
fi
PHPSTAN_OUTPUT="$(vendor/bin/phpstan analyse --no-progress --memory-limit=512M 2>&1)"
PHPSTAN_EXIT=$?
echo "$PHPSTAN_OUTPUT" | tail -10
if [ "$PHPSTAN_EXIT" -ne 0 ]; then
  echo "RESULT phpstan = FAIL (exit $PHPSTAN_EXIT)"
  FAIL=1
else
  echo "RESULT phpstan = PASS (no new errors — baseline entries on pre-existing files are still suppressed; check touched files separately, see CODING_STANDARDS.md)"
fi

echo
if [ "$FAIL" -eq 0 ]; then
  echo "VERIFICATION SUITE: PASS"
  exit 0
else
  echo "VERIFICATION SUITE: FAIL — see RESULT lines above"
  exit 1
fi
