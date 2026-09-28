#!/bin/bash
#
# Sourceable library: _integration_runner() and _run_integration_suite().
#
# WHY THIS IS A SEPARATE FILE. .githooks/pre-push and
# scripts/run-verification-suite.sh both need to run the integration suite in
# the right place. Docker is the only supported dev environment (MAMP
# retired, #2180), and .env.test.local sets DB_HOST=db, which resolves only
# inside the Docker Compose network. A host-side `composer test:integration`
# cannot reach it and the bootstrap dies before PHPUnit prints a summary.
# Keeping one copy here, sourced by both callers, removes the risk of the
# two copies drifting apart (mirrors scripts/lib/pick-closest-base.sh, #2171).
#
# This file defines two functions. It does not run anything by itself.
# Source it, then call the functions — do not execute it directly.

# Echoes where the suite must run: "docker" when .env.test.local points at
# the Docker Compose `db` service (DB_HOST=db, with or without a :port),
# otherwise "host". `db` resolves only on the Compose network, and the `db`
# service publishes no host port, so a host-side run can never reach it
# (#2171). INTEGRATION_GATE_RUNNER=host|docker overrides the detection.
_integration_runner() {
    local env_file db_host
    case "${INTEGRATION_GATE_RUNNER:-}" in
        host|docker)
            printf '%s\n' "$INTEGRATION_GATE_RUNNER"
            return 0
            ;;
        '')
            ;;
        *)
            echo "  WARNING: INTEGRATION_GATE_RUNNER='$INTEGRATION_GATE_RUNNER' is not 'host' or 'docker' — ignored; detecting from .env.test.local." >&2
            ;;
    esac

    env_file="$(git rev-parse --show-toplevel 2>/dev/null)/.env.test.local"
    db_host="$(grep -E '^[[:space:]]*DB_HOST[[:space:]]*=' "$env_file" 2>/dev/null | tail -n 1)"
    db_host="${db_host#*=}"
    db_host="$(printf '%s' "$db_host" | tr -d '\r"'"'" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    db_host="${db_host%%:*}"

    if [ "$db_host" = "db" ]; then
        echo "docker"
    else
        echo "host"
    fi
}

# Exit code reserved for a pre-flight failure in _run_integration_suite —
# the runner never got as far as starting PHPUnit, so this is not a test
# result. Kept clear of PHPUnit's own exit codes (0-3, plus 255 on a fatal
# error) and of the shell's 126-165 range, so callers can tell "could not
# run" apart from any PHPUnit, composer or docker result.
INTEGRATION_PREFLIGHT_FAIL=90

# Runs the integration suite where the test database is reachable and
# returns its exit status. In Docker mode it runs inside this checkout's
# `app` container (as www-data, see docker-compose.yml's header).
#
# A pre-flight problem — no `docker` on PATH, `docker compose ps` failing, or
# the app service not running — returns $INTEGRATION_PREFLIGHT_FAIL with an
# actionable message, never a silent skip. Once PHPUnit itself starts inside
# the container, this function returns PHPUnit's own exit code unchanged
# (including a fatal error or an out-of-memory kill), so a caller must not
# read every non-zero return here as "could not run".
_run_integration_suite() {
    local running ps_err
    if [ "$(_integration_runner)" = "docker" ]; then
        if ! command -v docker >/dev/null 2>&1; then
            echo "  .env.test.local points at the Docker 'db' service (DB_HOST=db), but 'docker' is not on PATH." >&2
            return "$INTEGRATION_PREFLIGHT_FAIL"
        fi
        # A failing `compose ps` (daemon down, no compose plugin) is reported
        # with docker's own error, not misdiagnosed as a stopped stack.
        ps_err="$(mktemp 2>/dev/null || echo /dev/null)"
        if ! running="$(docker compose ps --status running -q app 2>"$ps_err")"; then
            echo "  Could not query this checkout's Docker stack ('docker compose ps' failed):" >&2
            sed 's/^/    /' "$ps_err" >&2 2>/dev/null
            [ "$ps_err" != /dev/null ] && rm -f "$ps_err"
            return "$INTEGRATION_PREFLIGHT_FAIL"
        fi
        [ "$ps_err" != /dev/null ] && rm -f "$ps_err"
        if [ -z "$running" ]; then
            echo "  .env.test.local points at the Docker 'db' service, but this checkout's stack is not running." >&2
            echo "  Start it with: docker compose up -d" >&2
            return "$INTEGRATION_PREFLIGHT_FAIL"
        fi
        echo "  (running in the Docker 'app' container: .env.test.local has DB_HOST=db)"
        docker compose exec -T -u www-data app composer test:integration
        return $?
    fi
    composer test:integration
}
