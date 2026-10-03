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
#   3. The unit and integration suites are two separate PHPUnit invocations
#      — there is no combined total line, so both summaries must be checked
#      independently. Checking only one hides a dead other suite.
#   4. Docker is the only supported dev environment (MAMP retired, #2180).
#      .env.test.local sets DB_HOST=db, which resolves only inside the
#      Docker Compose network, so the integration suite cannot run on the
#      host in the normal case — see scripts/lib/integration-runner.sh
#      (shared with .githooks/pre-push, #2245). Only that library's own
#      pre-flight checks (stopped stack, no docker on PATH, `docker compose
#      ps` failing) return $INTEGRATION_PREFLIGHT_FAIL and are read as
#      "could not run". Any other non-zero exit from inside the container —
#      a PHP fatal error, an out-of-memory kill, a container dying mid-run —
#      is a real FAIL, not an environment problem, even with no PHPUnit
#      summary line to parse.
#
# This script is the parsing logic every command (`review-pr`,
# `finish-milestone`, `execute-plan`, `finish-issue`) used to re-derive
# inline. Keep the interpretation rules here in one place.
#
# Usage: scripts/run-verification-suite.sh
#   (run from the repo root; no arguments)
#
# Exit codes (a FAIL always wins over a COULD NOT RUN):
#   0 = every component (unit, integration, docs, PHPStan) passed
#   1 = at least one component FAILED — a clean summary line was missing or
#       unclean, or the component exited non-zero for a reason other than
#       the integration pre-flight below. Every component still runs; see
#       the RESULT lines for which one(s) failed.
#   2 = no component FAILED, but the integration suite COULD NOT RUN — a
#       pre-flight problem in scripts/lib/integration-runner.sh (missing
#       `docker`, `docker compose ps` failing, or a stopped stack), not a
#       test result. `composer` missing or `vendor/bin/phpstan` missing is
#       also reported this way, before any component runs.
set -uo pipefail

FAIL=0
COULD_NOT_RUN=0

strip_ansi() { sed 's/\x1b\[[0-9;]*m//g'; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib/integration-runner.sh
source "${SCRIPT_DIR}/lib/integration-runner.sh"

if ! command -v composer >/dev/null 2>&1; then
  echo "composer not found on PATH — cannot run the suite." >&2
  exit 2
fi

# Parses one PHPUnit run's captured output against the summary-line
# contract shared with test:full's old combined check. Sets FAIL=1 on
# anything that isn't a clean, non-empty, non-zero OK line. Args: $1 label
# (for the RESULT line), $2 captured combined stdout+stderr, $3 exit code.
_check_phpunit_summary() {
  local label="$1" output="$2" exit_code="$3" summary_lines ok_count zero_count_hit

  summary_lines="$(printf '%s\n' "$output" | strip_ansi | grep -E '^(OK|OK, but|FAILURES|ERRORS|WARNINGS|Tests:|No tests executed)')"
  echo "$summary_lines"

  ok_count="$(printf '%s\n' "$summary_lines" | grep -c '^OK (')"
  zero_count_hit="$(printf '%s\n' "$summary_lines" | grep -cE '^OK \(0 tests')"

  if [ "$exit_code" -ne 0 ]; then
    echo "RESULT $label = FAIL (non-zero exit $exit_code)"
    FAIL=1
  elif [ -z "$summary_lines" ]; then
    echo "RESULT $label = FAIL (no summary line — bootstrap likely died before PHPUnit reported)"
    FAIL=1
  elif [ "$ok_count" -ne 1 ]; then
    echo "RESULT $label = FAIL (expected 1 clean 'OK (N tests, M assertions)' line, got $ok_count)"
    FAIL=1
  elif [ "$zero_count_hit" -gt 0 ]; then
    echo "RESULT $label = FAIL (suite reported OK with 0 tests — it ran nothing)"
    FAIL=1
  else
    echo "RESULT $label = PASS"
  fi
}

echo "== composer test:unit =="
UNIT_OUTPUT="$(composer test:unit 2>&1)"
UNIT_EXIT=$?
_check_phpunit_summary "test:unit" "$UNIT_OUTPUT" "$UNIT_EXIT"

echo
echo "== composer test:integration =="
if [ "$(_integration_runner)" = "docker" ]; then
  echo "  (Docker checkout — .env.test.local has DB_HOST=db; running via scripts/lib/integration-runner.sh)"
fi
INTEGRATION_OUTPUT="$(_run_integration_suite 2>&1)"
INTEGRATION_EXIT=$?
if [ "$INTEGRATION_EXIT" -eq "$INTEGRATION_PREFLIGHT_FAIL" ]; then
  # The runner itself could not start PHPUnit at all (stopped stack, no
  # docker on PATH, `docker compose ps` failing) — no summary line exists to
  # parse, and this is not a test result. Its own message already explains
  # why: print it and record "could not run". Every other component still
  # runs below — an environment problem here must not hide a FAIL already
  # recorded for test:unit, or skip check:docs and PHPStan.
  echo "$INTEGRATION_OUTPUT"
  echo "RESULT test:integration = COULD NOT RUN (see message above)"
  COULD_NOT_RUN=1
else
  # Any other non-zero exit — a PHP fatal error, an out-of-memory kill, or a
  # container that died mid-run — is a real FAIL, not an environment
  # problem, whether or not PHPUnit reached its summary line. Print the
  # last ~20 lines so the failure itself is visible without deciding
  # PHPStan/docs shouldn't run.
  if [ "$INTEGRATION_EXIT" -ne 0 ] \
    && ! printf '%s\n' "$INTEGRATION_OUTPUT" | strip_ansi | grep -qE '^(OK|OK, but|FAILURES|ERRORS|WARNINGS|Tests:|No tests executed)'; then
    echo "$INTEGRATION_OUTPUT" | tail -20
    echo "RESULT test:integration = FAIL (non-zero exit $INTEGRATION_EXIT, no PHPUnit summary line — see output above)"
    FAIL=1
  else
    _check_phpunit_summary "test:integration" "$INTEGRATION_OUTPUT" "$INTEGRATION_EXIT"
  fi
fi

echo
echo "== composer check:docs =="
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
if [ "$FAIL" -eq 1 ]; then
  echo "VERIFICATION SUITE: FAIL — see RESULT lines above"
  exit 1
elif [ "$COULD_NOT_RUN" -eq 1 ]; then
  echo "VERIFICATION SUITE: COULD NOT RUN — see RESULT lines above"
  exit 2
else
  echo "VERIFICATION SUITE: PASS"
  exit 0
fi
