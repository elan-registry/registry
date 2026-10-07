#!/bin/bash
#
# Tests for scripts/project-health.py: issue-kind classification, open-issue
# age, velocity, current milestone, snapshot pruning, and the exit code when
# gh fails.
#
# HERMETIC: a stub gh on PATH answers each API call from fixture JSON.
# SUMMARY_TODAY pins today's date. No network calls.
#
# Usage: bash tests/hooks/test-project-health.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/project-health.py"

TMPROOT="$(mktemp -d)" || exit 1
# shellcheck disable=SC2329 # called only through the EXIT trap below
cleanup() { [ -n "${TMPROOT:-}" ] && rm -rf "$TMPROOT"; }
trap cleanup EXIT

TESTS_RUN=0
TESTS_FAILED=0
pass() { TESTS_RUN=$((TESTS_RUN + 1)); echo "PASS: $1"; }
fail() { TESTS_RUN=$((TESTS_RUN + 1)); TESTS_FAILED=$((TESTS_FAILED + 1)); echo "FAIL: $1"; shift; for l in "$@"; do echo "      $l"; done; }

# --- Stub gh ----------------------------------------------------------------
# Each API call answers from one fixture file, so a case can break one
# response. Open issues come as two --slurp pages: one per kind rule, plus a
# PR that must be ignored. Ages on 2026-10-10: 10, 20, 30, 40, 50, 60 days.
F="$TMPROOT/fx"
mkdir -p "$TMPROOT/bin" "$F"
cat > "$F/open.json" <<'JSON'
[[
 {"title":"fix(email): x","labels":[{"name":"enhancement"}],"created_at":"2026-09-30T00:00:00Z"},
 {"title":"feat: y","labels":[{"name":"bug"}],"created_at":"2026-09-20T00:00:00Z"},
 {"title":"security: z","labels":[],"created_at":"2026-09-10T00:00:00Z"},
 {"title":"tech-debt: w","labels":[],"created_at":"2026-08-31T00:00:00Z"}
],[
 {"title":"No prefix here","labels":[{"name":"signal:defect"}],"created_at":"2026-08-21T00:00:00Z"},
 {"title":"Plain title","labels":[],"created_at":"2026-08-11T00:00:00Z"},
 {"title":"a PR","labels":[],"created_at":"2026-01-01T00:00:00Z","pull_request":{}}
]]
JSON
cat > "$F/closed.json" <<'JSON'
{"total_count":3,"items":[
 {"title":"bug: a","labels":[],"created_at":"2026-10-01T00:00:00Z","closed_at":"2026-10-03T00:00:00Z"},
 {"title":"feat: b","labels":[],"created_at":"2026-09-20T00:00:00Z","closed_at":"2026-09-26T00:00:00Z"},
 {"title":"chore: c","labels":[],"created_at":"2026-09-25T00:00:00Z","closed_at":"2026-10-05T00:00:00Z"}
]}
JSON
cat > "$F/milestones.json" <<'JSON'
[
 {"title":"v2.30.10: later","open_issues":5,"closed_issues":0},
 {"title":"v2.30.8: done","open_issues":0,"closed_issues":4},
 {"title":"v2.30.9: now","open_issues":2,"closed_issues":6},
 {"title":"Backlog","open_issues":40,"closed_issues":0}
]
JSON
echo '{"total_count":7,"items":[]}' > "$F/count.json"
echo '{"total_count":3,"items":[]}' > "$F/merged.json"
cp -R "$F" "$TMPROOT/fx.orig"

cat > "$TMPROOT/bin/gh" <<STUB
#!/bin/bash
[ -n "\${GH_FAIL:-}" ] && { echo "HTTP 401: Bad credentials" >&2; exit 1; }
case "\$*" in
  *"is:pr is:merged"*)            cat "$F/merged.json" ;;
  *"per_page=1 "*|*"per_page=1")  cat "$F/count.json" ;;
  *search/issues*)                cat "$F/closed.json" ;;
  *"--slurp"*issues?state=open*)  cat "$F/open.json" ;;
  *milestones*)                   cat "$F/milestones.json" ;;
  *) echo "stub gh: unexpected \$*" >&2; exit 1 ;;
esac
STUB
chmod +x "$TMPROOT/bin/gh"

run() { PATH="$TMPROOT/bin:$PATH" SUMMARY_TODAY="${DAY:-2026-10-10}" python3 "$SCRIPT" --dir "$1" "${@:2}" 2>"$TMPROOT/err"; }
val() { jq -c "$2" "$1"; }

D="$TMPROOT/s"
mkdir -p "$D"
OUT="$(run "$D" --weeks 4)"; STATUS=$?
J="$D/health/2026-10-10.json"

# --- Case 1: writes the snapshot -------------------------------------------
if [ "$STATUS" -eq 0 ] && [ -f "$J" ] && printf '%s' "$OUT" | grep -q '6 open, 28 issues closed in 4 weeks'; then
    pass "Case 1: writes health/<today>.json and reports totals"
else
    fail "Case 1: writes health/<today>.json and reports totals" "exit: $STATUS" "out: [$OUT]" "err: [$(cat "$TMPROOT/err")]"
fi

# --- Case 2: title prefix wins over labels; labels decide otherwise --------
KINDS="$(val "$J" '.open | [.defects, .features, .security, .maintenance, .other]')"
if [ "$KINDS" = "[2,1,1,1,1]" ]; then
    pass "Case 2: title prefix decides first, labels second, pull requests ignored"
else
    fail "Case 2: title prefix decides first, labels second, pull requests ignored" "got: $KINDS"
fi

# --- Case 3: open-issue age ------------------------------------------------
AGES="$(val "$J" '.open | [.avg_age_days, .median_age_days, .oldest_days]')"
if [ "$AGES" = "[35.0,35.0,60]" ]; then
    pass "Case 3: average, median and oldest open-issue age"
else
    fail "Case 3: average, median and oldest open-issue age" "got: $AGES"
fi

# --- Case 4: closed mix and lead time --------------------------------------
CLOSED="$(val "$J" '.closed_30d | [.total, .defects, .features, .maintenance, .median_lead_days]')"
if [ "$CLOSED" = "[3,1,1,1,6]" ]; then
    pass "Case 4: closed-in-30-days mix and median lead time"
else
    fail "Case 4: closed-in-30-days mix and median lead time" "got: $CLOSED"
fi

# --- Case 5: velocity weeks ------------------------------------------------
VEL="$(val "$J" '[.velocity[0], .velocity[-1]] | map([.week_start, .issues_closed, .prs_merged])')"
if [ "$VEL" = '[["2026-09-12",7,3],["2026-10-03",7,3]]' ]; then
    pass "Case 5: one velocity row per week, oldest first, ending last week"
else
    fail "Case 5: one velocity row per week, oldest first, ending last week" "got: $VEL"
fi

# --- Case 6: current milestone is the lowest version with open issues ------
MS="$(val "$J" '.milestone')"
if [ "$MS" = '{"title":"v2.30.9: now","closed":6,"total":8}' ]; then
    pass "Case 6: current milestone = lowest version with open issues (numeric sort)"
else
    fail "Case 6: current milestone = lowest version with open issues (numeric sort)" "got: $MS"
fi

# --- Case 7: --keep prunes the oldest snapshots ----------------------------
DAY=2026-10-11 run "$D" --weeks 1 --keep 2 >/dev/null
DAY=2026-10-12 run "$D" --weeks 1 --keep 2 >/dev/null
LEFT="$(find "$D/health" -name '*.json' | sort | xargs -n1 basename | tr '\n' ' ')"
if [ "$LEFT" = "2026-10-11.json 2026-10-12.json " ]; then
    pass "Case 7: --keep keeps only the newest snapshots"
else
    fail "Case 7: --keep keeps only the newest snapshots" "got: [$LEFT]"
fi

# --- Case 8: a gh failure exits 2 and writes nothing -----------------------
E="$TMPROOT/e"
mkdir -p "$E"
GH_FAIL=1 run "$E" >/dev/null; S8=$?
if [ "$S8" -eq 2 ] && [ ! -d "$E/health" ] && grep -q 'Bad credentials' "$TMPROOT/err"; then
    pass "Case 8: a gh failure exits 2 and writes nothing"
else
    fail "Case 8: a gh failure exits 2 and writes nothing" "exit: $S8" "err: [$(cat "$TMPROOT/err")]"
fi

# bad <case> <fixture> <json> <stderr text>: one broken gh response must exit
# 2 with that message and write nothing. Each case gets its own folder and
# fresh fixtures, so one failure cannot cascade into the next case.
bad() {
    local dir="$TMPROOT/bad-$1" status
    rm -rf "$F" && cp -R "$TMPROOT/fx.orig" "$F"
    printf '%s' "$3" > "$F/$2"
    mkdir -p "$dir"
    run "$dir" >/dev/null; status=$?
    if [ "$status" -eq 2 ] && [ ! -d "$dir/health" ] && grep -q "$4" "$TMPROOT/err" \
        && ! grep -q Traceback "$TMPROOT/err"; then
        pass "Case $1: $2 = $3 exits 2 and writes nothing"
    else
        fail "Case $1: $2 = $3 exits 2 and writes nothing" "exit: $status" "err: [$(cat "$TMPROOT/err")]"
    fi
}

# --- Case 9: each wrong-shape gh response exits 2 --------------------------
bad 9a open.json '{"message":"odd"}' 'open issues: expected a JSON array'
bad 9b open.json '[{}]' 'open issues page: expected a JSON array'
bad 9c count.json '{"message":"API rate limit exceeded"}' 'no total_count'
bad 9d count.json '{"total_count":7,"incomplete_results":true,"items":[]}' 'incomplete results'
bad 9e closed.json '{"total_count":3}' 'search items: expected a JSON array'
bad 9f milestones.json '{}' 'milestones: expected a JSON array'
rm -rf "$F" && cp -R "$TMPROOT/fx.orig" "$F"

# --- Case 9g: a usage error exits 1 -----------------------------------------
run "$E" --weeks 0 >/dev/null; S9G=$?
run "$E" --no-such-flag >/dev/null; S9H=$?
if [ "$S9G" -eq 1 ] && [ "$S9H" -eq 1 ]; then
    pass "Case 9g: a bad --weeks and an unknown flag exit 1"
else
    fail "Case 9g: a bad --weeks and an unknown flag exit 1" "--weeks 0: $S9G" "unknown flag: $S9H"
fi

# --- Case 10: a missing folder exits 1 --------------------------------------
run "$TMPROOT/nope" >/dev/null; S10=$?
if [ "$S10" -eq 1 ] && grep -q 'no such folder' "$TMPROOT/err"; then
    pass "Case 10: a missing folder exits 1"
else
    fail "Case 10: a missing folder exits 1" "exit: $S10"
fi

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."
[ "$TESTS_FAILED" -eq 0 ]
