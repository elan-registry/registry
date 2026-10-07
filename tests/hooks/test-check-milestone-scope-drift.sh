#!/bin/bash
#
# Tests for scripts/check-milestone-scope-drift.sh: the release-notes
# "Issues Resolved" issue set against the milestone's current membership.
#
# HERMETIC: a stub `gh` goes first on PATH. It accepts only the exact argv
# the script must send (endpoint with state=all and per_page=100,
# --paginate, and the exact --jq filter) and exits 99 on anything else. It
# then runs that --jq filter with the real `jq` over a JSON fixture, so the
# pull-request filter is exercised, not copied. The fixture holds one JSON
# array per "page", the way `gh api --paginate` concatenates them.
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
want_jq='.[] | select(.pull_request == null) | "\(.number)\t\(.state)\t\(.title)"'
want_ep="repos/elan-registry/registry/issues?milestone=${STUB_MILESTONE:-7}&state=all&per_page=100"
if [ "$#" -ne 5 ] || [ "$1" != "api" ] || [ "$2" != "--paginate" ] \
    || [ "$3" != "$want_ep" ] || [ "$4" != "--jq" ] || [ "$5" != "$want_jq" ]; then
    echo "stub gh: unexpected argv: $*" >&2
    exit 99
fi
jq -r "$5" "$STUB_MEMBERS_FILE"
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

# Stages the stub's membership as two JSON "pages": page 1 holds one pull
# request (#9001, which the script's filter must drop) and the first issue;
# page 2 holds the rest. Each issue is closed with title "Title N".
issue_json() { printf '{"number":%s,"state":"closed","title":"Title %s"}' "$1" "$1"; }
write_members() {
    local page1='{"number":9001,"state":"closed","title":"A PR","pull_request":{"url":"x"}}'
    local page2="" n
    if [ "$#" -gt 0 ]; then
        page1="${page1},$(issue_json "$1")"
        shift
    fi
    for n in "$@"; do
        page2="${page2:+${page2},}$(issue_json "$n")"
    done
    printf '[%s]\n[%s]\n' "$page1" "$page2" > "$STUB_MEMBERS_FILE"
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

# --- Case 9: mixed-width numbers, exact match -> 0 -------------------------
# Numeric sort fed to `comm` misreports these; lexical sort must be used.
write_notes 95 999 2300
write_members 2300 95 999
OUT9="$(run_check "$VERSION" 7 2>&1)"
STATUS9=$?
if [ "$STATUS9" -eq 0 ] && printf '%s' "$OUT9" | grep -q '— 3 issues\.'; then
    pass "Case 9: mixed-width issue numbers, exact match -> exit 0"
else
    fail "Case 9: mixed-width issue numbers, exact match -> exit 0" \
        "exit: $STATUS9 (want 0)" "output: [$OUT9]"
fi

# --- Case 10: mixed-width numbers, mismatch -> 1, no false lines -----------
write_notes 999 2300
write_members 2300 2301
OUT10="$(run_check "$VERSION" 7 2>&1)"
STATUS10=$?
if [ "$STATUS10" -eq 1 ] \
    && printf '%s' "$OUT10" | grep -q '#999 — moved out' \
    && printf '%s' "$OUT10" | grep -q '#2301 — added' \
    && ! printf '%s' "$OUT10" | grep -q '#2300 '; then
    pass "Case 10: mixed-width mismatch -> exit 1, only #999 and #2301 reported"
else
    fail "Case 10: mixed-width mismatch -> exit 1, only #999 and #2301 reported" \
        "exit: $STATUS10 (want 1)" "output: [$OUT10]"
fi

# --- Case 11: milestone has no issues (only a PR) -> 2 ---------------------
write_notes 101
write_members
OUT11="$(run_check "$VERSION" 7 2>&1)"
STATUS11=$?
if [ "$STATUS11" -eq 2 ] && printf '%s' "$OUT11" | grep -q 'has no issues in any state'; then
    pass "Case 11: empty milestone (PR filtered out) -> exit 2"
else
    fail "Case 11: empty milestone (PR filtered out) -> exit 2" \
        "exit: $STATUS11 (want 2)" "output: [$OUT11]"
fi

# --- Case 12: a real bullet in a later "## " section is not counted -------
write_notes 101
printf '%s\n' "- [#4444](https://github.com/elan-registry/registry/issues/4444) — later section." >> "$NOTES"
write_members 101
OUT12="$(run_check "$VERSION" 7 2>&1)"
STATUS12=$?
if [ "$STATUS12" -eq 0 ]; then
    pass "Case 12: bullet under a later section is ignored -> exit 0"
else
    fail "Case 12: bullet under a later section is ignored -> exit 0" \
        "exit: $STATUS12 (want 0)" "output: [$OUT12]"
fi

# --- Case 13: "### Issues Resolved" (wrong level) -> 2 ---------------------
write_notes 101
sed -i.bak 's/^## Issues Resolved$/### Issues Resolved/' "$NOTES" && rm -f "$NOTES.bak"
write_members 101
OUT13="$(run_check "$VERSION" 7 2>&1)"
STATUS13=$?
if [ "$STATUS13" -eq 2 ]; then
    pass "Case 13: wrong heading level for Issues Resolved -> exit 2"
else
    fail "Case 13: wrong heading level for Issues Resolved -> exit 2" \
        "exit: $STATUS13 (want 2)" "output: [$OUT13]"
fi

# --- Case 14: duplicate entries collapse -> 0 with the right count ---------
write_notes 101 102 101
write_members 101 102
OUT14="$(run_check "$VERSION" 7 2>&1)"
STATUS14=$?
if [ "$STATUS14" -eq 0 ] && printf '%s' "$OUT14" | grep -q '— 2 issues\.'; then
    pass "Case 14: duplicate notes entry collapses -> exit 0, 2 issues"
else
    fail "Case 14: duplicate notes entry collapses -> exit 0, 2 issues" \
        "exit: $STATUS14 (want 0)" "output: [$OUT14]"
fi

# --- Case 15: the stub rejects a changed gh call (guards the stub itself) --
write_notes 101
write_members 101
OUT15="$(cd "$WORKDIR" && STUB_MILESTONE=8 PATH="$STUBDIR:$PATH" "$CHECK_SCRIPT" "$VERSION" 7 2>&1)"
STATUS15=$?
if [ "$STATUS15" -eq 2 ] && printf '%s' "$OUT15" | grep -q 'unexpected argv'; then
    pass "Case 15: stub rejects an argv it does not expect -> script exit 2"
else
    fail "Case 15: stub rejects an argv it does not expect -> script exit 2" \
        "exit: $STATUS15 (want 2)" "output: [$OUT15]"
fi

# --- Report ------------------------------------------------------------

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."

if [ "$TESTS_FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
