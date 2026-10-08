# shellcheck shell=bash
# Shared harness for tests/hooks/test-*.sh. Source it; do not run it.
# It lives in lib/ so that the CI loop over tests/hooks/test-*.sh skips it.
#
# A test that must clean up more than $TMPROOT defines its own cleanup()
# after it sources this file. The EXIT trap calls cleanup() by name.

TESTS_RUN=0
TESTS_FAILED=0

# shellcheck disable=SC2329 # called only through the EXIT trap below
cleanup() {
    cd / || true
    [ -n "${TMPROOT:-}" ] && rm -rf "$TMPROOT"
}
trap cleanup EXIT

pass() { TESTS_RUN=$((TESTS_RUN + 1)); echo "PASS: $1"; }

# fail <label> [detail-line ...]
fail() {
    TESTS_RUN=$((TESTS_RUN + 1))
    TESTS_FAILED=$((TESTS_FAILED + 1))
    echo "FAIL: $1"
    shift
    local line
    for line in "$@"; do echo "      $line"; done
}

harness_report() {
    echo ""
    echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."
    if [ "$TESTS_FAILED" -gt 0 ]; then
        exit 1
    fi
    exit 0
}
