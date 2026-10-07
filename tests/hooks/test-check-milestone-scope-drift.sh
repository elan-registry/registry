#!/bin/bash
#
# Tests for scripts/check-milestone-scope-drift.sh: the release-notes
# "Issues Resolved" issue set against the milestone's current membership.
#
# HERMETIC: a stub `gh` goes first on PATH and prints the staged membership
# lines (the real script's --jq filter output: "number<TAB>state<TAB>title").
# STUB_GH_FAIL=1 makes the stub fail like an auth or network error. Each case
# runs the script from a temp directory that holds its own
# docs/releases/RELEASE_NOTES_<version>.md fixture. No network calls.
#
# Usage: bash tests/hooks/test-check-milestone-scope-drift.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
CHECK_SCRIPT="$REAL_REPO/scripts/check-milestone-scope-drift.sh"
if [ ! -x "$CHECK_SCRIPT" ]; then
    echo "FAIL: $CHECK_SCRIPT not found or not executable" >&2
    exit 1
fi

TMPROOT="$(mktemp -d)" || exit 1
# shellcheck disable=SC2329 # called only through the EXIT trap below
cleanup() {
    cd / || true
    [ -n "${TMPROOT:-}" ] && rm -rf "$TMPROOT"
}
trap cleanup EXIT

TESTS_RUN=0
TESTS_FAILED=0

pass() { TESTS_RUN=$((TESTS_RUN + 1)); echo "PASS: $1"; }
fail() {
    TESTS_RUN=$((TESTS_RUN + 1))
    TESTS_FAILED=$((TESTS_FAILED + 1))
    echo "FAIL: $1"
    shift
    local line
    for line in "$@"; do echo "      $line"; done
}

STUBDIR="$TMPROOT/bin"
WORKDIR="$TMPROOT/repo"
mkdir -p "$STUBDIR" "$WORKDIR/docs/releases"
STUB_MEMBERS_FILE="$TMPROOT/members.txt"
export STUB_MEMBERS_FILE

cat > "$STUBDIR/gh" <<'STUB'
#!/bin/bash
if [ "${STUB_GH_FAIL:-0}" = "1" ]; then
    echo "gh: simulated API failure (stub)" >&2
    exit 1
fi
cat "$STUB_MEMBERS_FILE" 2>/dev/null
exit 0
STUB
chmod +x "$STUBDIR/gh"

VERSION="v9.9.9"
NOTES="$WORKDIR/docs/releases/RELEASE_NOTES_${VERSION}.md"

# Writes a release-notes fixture whose "Issues Resolved" section lists the
# given issue numbers. #1895 appears only as a cross-reference inside the
# first bullet, and #4242 only in another section, so neither may count.
write_notes() {
    {
        echo "# Elan Registry ${VERSION} Release Notes"
        echo ""
        echo "## User-Facing Changes"
        echo ""
        echo "- Something shipped ([#4242](https://github.com/elan-registry/registry/issues/4242))."
        echo ""
        echo "## Issues Resolved"
        echo ""
        local first=1 n
        for n in "$@"; do
            if [ "$first" -eq 1 ]; then
                echo "- [#${n}](https://github.com/elan-registry/registry/issues/${n}) — Entry. Deferred to [#1895](https://github.com/elan-registry/registry/issues/1895)."
                first=0
            else
                echo "- [#${n}](https://github.com/elan-registry/registry/issues/${n}) — Entry."
            fi
        done
        echo ""
        echo "## Deployment Notes"
        echo ""
        echo "See [#4343](https://github.com/elan-registry/registry/issues/4343)."
    } > "$NOTES"
}

# Stages the stub's membership output: one "N<TAB>closed<TAB>Title N" line
# per argument.
write_members() {
    : > "$STUB_MEMBERS_FILE"
    local n
    for n in "$@"; do
        printf '%s\tclosed\tTitle %s\n' "$n" "$n" >> "$STUB_MEMBERS_FILE"
    done
}

run_check() {
    (cd "$WORKDIR" && PATH="$STUBDIR:$PATH" "$CHECK_SCRIPT" "$@")
}

# --- Case 1: exact match -> 0 ---------------------------------------------
write_notes 101 102 103
write_members 103 101 102
OUT1="$(run_check "$VERSION" 7 2>&1)"
STATUS1=$?
if [ "$STATUS1" -eq 0 ] && printf '%s' "$OUT1" | grep -q 'Milestone scope matches release notes — 3 issues\.'; then
    pass "Case 1: exact match (cross-reference and other-section links ignored) -> exit 0"
else
    fail "Case 1: exact match (cross-reference and other-section links ignored) -> exit 0" \
        "exit: $STATUS1 (want 0)" "output: [$OUT1]"
fi

# --- Case 2: in release notes, not in membership -> 1, "moved out" ---------
write_notes 101 102 103
write_members 101 102
OUT2="$(run_check "$VERSION" 7 2>&1)"
STATUS2=$?
if [ "$STATUS2" -eq 1 ] \
    && printf '%s' "$OUT2" | grep -q '#103 — moved out of milestone since release notes were written' \
    && ! printf '%s' "$OUT2" | grep -q 'added to milestone'; then
    pass "Case 2: issue in release notes only -> exit 1, reported as moved out"
else
    fail "Case 2: issue in release notes only -> exit 1, reported as moved out" \
        "exit: $STATUS2 (want 1)" "output: [$OUT2]"
fi

# --- Case 3: in membership, not in release notes -> 1, "added" -------------
write_notes 101 102
write_members 101 102 104
OUT3="$(run_check "$VERSION" 7 2>&1)"
STATUS3=$?
if [ "$STATUS3" -eq 1 ] \
    && printf '%s' "$OUT3" | grep -q '#104 — added to milestone, missing from release notes (closed: Title 104)' \
    && ! printf '%s' "$OUT3" | grep -q 'moved out'; then
    pass "Case 3: issue in membership only -> exit 1, reported as added, missing from release notes"
else
    fail "Case 3: issue in membership only -> exit 1, reported as added, missing from release notes" \
        "exit: $STATUS3 (want 1)" "output: [$OUT3]"
fi

# --- Case 4: both directions at once -> 1, both reported -------------------
write_notes 101 102 103
write_members 101 102 104
OUT4="$(run_check "$VERSION" 7 2>&1)"
STATUS4=$?
if [ "$STATUS4" -eq 1 ] \
    && printf '%s' "$OUT4" | grep -q '#103 — moved out of milestone' \
    && printf '%s' "$OUT4" | grep -q '#104 — added to milestone, missing from release notes'; then
    pass "Case 4: mismatch in both directions -> exit 1, both reported"
else
    fail "Case 4: mismatch in both directions -> exit 1, both reported" \
        "exit: $STATUS4 (want 1)" "output: [$OUT4]"
fi

# --- Case 5: release notes file missing -> 2 -------------------------------
rm -f "$NOTES"
write_members 101
OUT5="$(run_check "$VERSION" 7 2>&1)"
STATUS5=$?
if [ "$STATUS5" -eq 2 ] && printf '%s' "$OUT5" | grep -q 'No release notes at'; then
    pass "Case 5: release notes file missing -> exit 2"
else
    fail "Case 5: release notes file missing -> exit 2" "exit: $STATUS5 (want 2)" "output: [$OUT5]"
fi

# --- Case 6: gh api call fails -> 2 ----------------------------------------
write_notes 101
OUT6="$(cd "$WORKDIR" && STUB_GH_FAIL=1 PATH="$STUBDIR:$PATH" "$CHECK_SCRIPT" "$VERSION" 7 2>&1)"
STATUS6=$?
if [ "$STATUS6" -eq 2 ] && printf '%s' "$OUT6" | grep -q 'simulated API failure'; then
    pass "Case 6: gh api call fails -> exit 2, gh's error shown"
else
    fail "Case 6: gh api call fails -> exit 2, gh's error shown" "exit: $STATUS6 (want 2)" "output: [$OUT6]"
fi

# --- Case 7: no Issues Resolved entries -> 2, not "everything added" -------
write_notes
write_members 101 102
OUT7="$(run_check "$VERSION" 7 2>&1)"
STATUS7=$?
if [ "$STATUS7" -eq 2 ] && printf '%s' "$OUT7" | grep -q 'No "- \[#N\]'; then
    pass "Case 7: no Issues Resolved entries -> exit 2"
else
    fail "Case 7: no Issues Resolved entries -> exit 2" "exit: $STATUS7 (want 2)" "output: [$OUT7]"
fi

# --- Case 8: bad arguments -> 2 --------------------------------------------
write_notes 101
write_members 101
OUT8A="$(run_check "$VERSION" 2>&1)"
STATUS8A=$?
OUT8B="$(run_check "$VERSION" abc 2>&1)"
STATUS8B=$?
if [ "$STATUS8A" -eq 2 ] && [ "$STATUS8B" -eq 2 ]; then
    pass "Case 8: missing or non-numeric milestone number -> exit 2"
else
    fail "Case 8: missing or non-numeric milestone number -> exit 2" \
        "exit (missing): $STATUS8A (want 2)" "exit (non-numeric): $STATUS8B (want 2)" \
        "output: [$OUT8A] [$OUT8B]"
fi

# --- Report ------------------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
