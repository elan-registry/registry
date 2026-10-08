#!/bin/bash
#
# Regression test for scripts/provision-schema.sh's DB_HOST=db guard.
#
# On a developer's host the name `db` can resolve through the DNS search
# domain to a different machine, and the script runs DROP DATABASE. The guard
# must stop a host run before any mysql call.
#
# HERMETIC: each case uses a temp env file and stub `mysql` and `composer`
# clients that only log their calls. No real database is touched.
#
# Usage: bash tests/hooks/test-provision-schema-guard.sh
# Exit code: 0 if all cases pass, 1 otherwise.

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"

REPO_ROOT="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REPO_ROOT/scripts/provision-schema.sh"

# The guard passes inside a container by design, so the host-only cases
# cannot run there.
if [ -f /.dockerenv ]; then
    echo "SKIP: /.dockerenv exists. Run this test on the host or a CI runner."
    exit 0
fi

TMPROOT="$(mktemp -d)" || exit 1

MYSQL_LOG="$TMPROOT/mysql.log"
mkdir -p "$TMPROOT/bin"
# Exit non-zero so a run that gets past the guards stops at the first mysql
# call instead of going on to composer and phinx.
cat > "$TMPROOT/bin/mysql" <<EOF
#!/bin/bash
echo "\$*" >> "$MYSQL_LOG"
exit 1
EOF
printf '#!/bin/bash\nexit 0\n' > "$TMPROOT/bin/composer"
chmod +x "$TMPROOT/bin/mysql" "$TMPROOT/bin/composer"

# Runs the script against a temp env file with the given DB_HOST. Sets
# RUN_EXIT, RUN_OUTPUT and MYSQL_CALLS.
run_provision() {
    local db_host="$1"
    shift
    local env_file="$TMPROOT/env.test"
    printf 'DB_HOST=%s\nDB_NAME=provision_guard_test\nDB_USER=u\nDB_PASS=p\n' "$db_host" > "$env_file"
    rm -f "$MYSQL_LOG"
    RUN_OUTPUT="$(PATH="$TMPROOT/bin:$PATH" MYSQL_BIN="$TMPROOT/bin/mysql" \
        bash "$SCRIPT" --env-file "$env_file" "$@" 2>&1)"
    RUN_EXIT=$?
    if [ -f "$MYSQL_LOG" ]; then
        MYSQL_CALLS="$(wc -l < "$MYSQL_LOG" | tr -d ' ')"
    else
        MYSQL_CALLS=0
    fi
}

assert_blocked() {
    local description="$1"
    if [ "$RUN_EXIT" -eq 0 ]; then
        fail "$description" "expected a non-zero exit, got 0"
    elif ! grep -q "DB_HOST=db resolves only inside the Docker Compose network" <<< "$RUN_OUTPUT"; then
        fail "$description" "guard message missing from output:" "$RUN_OUTPUT"
    elif ! grep -q "docker compose exec -u www-data app scripts/provision-schema.sh" <<< "$RUN_OUTPUT"; then
        fail "$description" "container command missing from output:" "$RUN_OUTPUT"
    elif [ "$MYSQL_CALLS" -ne 0 ]; then
        fail "$description" "mysql was called $MYSQL_CALLS time(s)"
    else
        pass "$description"
    fi
}

run_provision db
assert_blocked "Case 1: DB_HOST=db on the host is refused before any mysql call"

run_provision db:3306
assert_blocked "Case 2: DB_HOST=db:3306 on the host is refused before any mysql call"

run_provision db --force
assert_blocked "Case 3: --force does not bypass the DB_HOST=db guard"

# Control case: proves the stub records calls, so a zero count above means
# the guard stopped the run and not that the stub was bypassed.
run_provision 127.0.0.1
if grep -q "resolves only inside the Docker Compose network" <<< "$RUN_OUTPUT"; then
    fail "Case 4: DB_HOST=127.0.0.1 does not trigger the guard" "guard fired:" "$RUN_OUTPUT"
elif [ "$MYSQL_CALLS" -lt 1 ]; then
    fail "Case 4: DB_HOST=127.0.0.1 does not trigger the guard" "stub mysql was not called:" "$RUN_OUTPUT"
else
    pass "Case 4: DB_HOST=127.0.0.1 does not trigger the guard"
fi

harness_report
