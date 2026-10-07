#!/bin/bash
#
# Tests for scripts/build-summary-index.py: one folder per series, grouped by
# category, fresh/stale badges, the newest --keep pages kept, and the prompt
# shown collapsed without its front matter, and the Project health section
# drawn from health/<date>.json snapshots.
#
# HERMETIC: each case builds a fixture folder under a temp dir and runs the
# script with --dir. SUMMARY_TODAY pins today's date. No network calls.
#
# Usage: bash tests/hooks/test-build-summary-index.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
SCRIPT="$REAL_REPO/scripts/build-summary-index.py"

TMPROOT="$(mktemp -d)" || exit 1
# shellcheck disable=SC2329 # called only through the EXIT trap below
cleanup() { [ -n "${TMPROOT:-}" ] && rm -rf "$TMPROOT"; }
trap cleanup EXIT

TESTS_RUN=0
TESTS_FAILED=0
pass() { TESTS_RUN=$((TESTS_RUN + 1)); echo "PASS: $1"; }
fail() { TESTS_RUN=$((TESTS_RUN + 1)); TESTS_FAILED=$((TESTS_FAILED + 1)); echo "FAIL: $1"; shift; for l in "$@"; do echo "      $l"; done; }

# page <dir> <date> <lede>: one summary page.
page() {
    mkdir -p "$1"
    printf '<html><head><title>T</title></head><body><p class="lede">%s</p></body></html>\n' "$3" > "$1/$2.html"
}
# prompt <dir> <title> <category> <refresh_days>: a prompt.md with front matter.
prompt() {
    mkdir -p "$1"
    printf -- '---\ntitle: %s\ncategory: %s\nrefresh_days: %s\n---\n/summary Do the thing for %s.\n' "$2" "$3" "$4" "$2" > "$1/prompt.md"
}

build() { SUMMARY_TODAY=2026-10-10 python3 "$SCRIPT" --dir "$1" "${@:2}" 2>"$TMPROOT/err"; }

# --- Fixture --------------------------------------------------------------
F="$TMPROOT/s"
prompt "$F/alpha" "Alpha Status" "Status" 7
page "$F/alpha" 2026-10-01 "alpha one"
page "$F/alpha" 2026-10-05 "alpha two"
page "$F/alpha" 2026-10-08 "alpha three"
page "$F/alpha" 2026-10-09 "alpha four"
prompt "$F/beta" "Beta Review" "Review" 7
page "$F/beta" 2026-09-01 "beta old"
prompt "$F/gamma" "Gamma Health" "Health" 30
page "$F/gamma" 2026-10-02 "gamma lede"
page "$F/nopr" 2026-10-09 "no prompt here"
page "$F" 2026-10-09 "loose"
mv "$F/2026-10-09.html" "$F/loose-page.html"

OUT="$(build "$F")"; STATUS=$?
IDX="$F/index.html"

# --- Case 1: builds and reports --------------------------------------------
if [ "$STATUS" -eq 0 ] && printf '%s' "$OUT" | grep -q 'index.html: 4 series'; then
    pass "Case 1: builds the index and counts 4 series"
else
    fail "Case 1: builds the index and counts 4 series" "exit: $STATUS" "out: [$OUT]"
fi

# --- Case 2: keeps the newest 3 pages, deletes older ------------------------
if [ ! -f "$F/alpha/2026-10-01.html" ] && [ -f "$F/alpha/2026-10-05.html" ] \
    && printf '%s' "$OUT" | grep -q 'deleted alpha/2026-10-01.html'; then
    pass "Case 2: keeps the newest 3 pages and deletes the oldest"
else
    fail "Case 2: keeps the newest 3 pages and deletes the oldest" "out: [$OUT]" "$(ls "$F/alpha")"
fi

# --- Case 3: categories in the fixed order ---------------------------------
ORDER="$(grep -o '<h2>[A-Za-z]*' "$IDX" | sed 's/<h2>//' | grep -v -e '^Project$' -e '^How$' | tr '\n' ' ')"
if [ "$ORDER" = "Status Health Review Other " ]; then
    pass "Case 3: categories in order Status, Health, Review, Other"
else
    fail "Case 3: categories in order Status, Health, Review, Other" "got: [$ORDER]"
fi

# --- Case 4: stale vs fresh by refresh_days --------------------------------
# beta: 2026-09-01, refresh 7 -> stale. alpha: 2026-10-09 -> fresh.
BETA="$(sed -n '/Beta Review/,/<\/article>/p' "$IDX")"
ALPHA="$(sed -n '/Alpha Status/,/<\/article>/p' "$IDX")"
if printf '%s' "$BETA" | grep -q 'badge stale' && printf '%s' "$ALPHA" | grep -q 'badge fresh'; then
    pass "Case 4: a series older than refresh_days is stale, a recent one fresh"
else
    fail "Case 4: a series older than refresh_days is stale, a recent one fresh"
fi

# --- Case 5: latest page link, lede and older links ------------------------
if printf '%s' "$ALPHA" | grep -q 'href="alpha/2026-10-09.html"' \
    && printf '%s' "$ALPHA" | grep -q 'alpha four' \
    && printf '%s' "$ALPHA" | grep -q 'href="alpha/2026-10-05.html"'; then
    pass "Case 5: links the newest page, shows its lede and the older pages"
else
    fail "Case 5: links the newest page, shows its lede and the older pages"
fi

# --- Case 6: prompt collapsed, front matter stripped, file link ------------
if printf '%s' "$ALPHA" | grep -q '<details>' \
    && printf '%s' "$ALPHA" | grep -q '/summary Do the thing for Alpha Status' \
    && ! printf '%s' "$ALPHA" | grep -q 'refresh_days:' \
    && printf '%s' "$ALPHA" | grep -q 'href="alpha/prompt.md"'; then
    pass "Case 6: prompt is collapsed, without front matter, with a file link"
else
    fail "Case 6: prompt is collapsed, without front matter, with a file link"
fi

# --- Case 7: a series with no prompt.md says so ----------------------------
if grep -q 'No prompt.md' "$IDX"; then
    pass "Case 7: a series without prompt.md is flagged"
else
    fail "Case 7: a series without prompt.md is flagged"
fi

# --- Case 8: a loose page outside a series folder gets a warning -----------
if grep -q 'loose-page.html is not in a series folder' "$TMPROOT/err"; then
    pass "Case 8: a loose page outside a series folder is warned about"
else
    fail "Case 8: a loose page outside a series folder is warned about" "err: [$(cat "$TMPROOT/err")]"
fi

# --- Case 9: --keep 0 keeps every page -------------------------------------
G="$TMPROOT/k"
prompt "$G/one" "One" "Status" 7
for d in 2026-10-01 2026-10-02 2026-10-03 2026-10-04 2026-10-05; do page "$G/one" "$d" "x"; done
build "$G" --keep 0 >/dev/null
if [ "$(find "$G/one" -name '2026-*.html' | wc -l | tr -d ' ')" = "5" ]; then
    pass "Case 9: --keep 0 keeps every page"
else
    fail "Case 9: --keep 0 keeps every page"
fi

# --- Case 10: a missing folder exits 1 -------------------------------------
build "$TMPROOT/does-not-exist" >/dev/null; S10=$?
if [ "$S10" -eq 1 ] && grep -q 'no such folder' "$TMPROOT/err"; then
    pass "Case 10: a missing folder exits 1"
else
    fail "Case 10: a missing folder exits 1" "exit: $S10"
fi

# --- Case 11: HTML in a prompt is escaped -----------------------------------
H="$TMPROOT/h"
prompt "$H/x" "X" "Status" 7
printf '<script>alert(1)</script>\n' >> "$H/x/prompt.md"
page "$H/x" 2026-10-09 "x"
build "$H" >/dev/null
if grep -q '&lt;script&gt;alert(1)&lt;/script&gt;' "$H/index.html" && ! grep -q '<script>alert(1)' "$H/index.html"; then
    pass "Case 11: HTML in a prompt is escaped"
else
    fail "Case 11: HTML in a prompt is escaped"
fi

# --- Case 12: summary falls back to meta description, then chip + tagline --
M="$TMPROOT/m"
prompt "$M/meta" "Meta" "Status" 7
mkdir -p "$M/meta" "$M/chip"
printf '<html><head><title>T</title><meta name="description" content="from meta"></head></html>\n' > "$M/meta/2026-10-09.html"
prompt "$M/chip" "Chip" "Status" 7
printf '<html><body><div class="tagline">from tagline</div><div class="verdict-chip">from chip</div></body></html>\n' > "$M/chip/2026-10-09.html"
build "$M" >/dev/null
if grep -q 'from meta' "$M/index.html" && grep -q 'from chip. from tagline' "$M/index.html"; then
    pass "Case 12: summary falls back to meta description, then chip and tagline"
else
    fail "Case 12: summary falls back to meta description, then chip and tagline"
fi

# --- Case 13: no health snapshot says how to make one ----------------------
if grep -q 'No snapshot yet' "$IDX" && printf '%s' "$OUT" | grep -q 'no health snapshot'; then
    pass "Case 13: with no health snapshot the index says how to make one"
else
    fail "Case 13: with no health snapshot the index says how to make one" "out: [$OUT]"
fi

# --- Case 14: newest health snapshot drives the section ---------------------
# snap <dir> <date> <open total> <defects>: one health snapshot.
snap() {
    mkdir -p "$1/health"
    printf '{"velocity":[{"week_start":"2026-09-28","issues_closed":12,"prs_merged":9},{"week_start":"2026-10-05","issues_closed":8,"prs_merged":5}],"open":{"total":%s,"avg_age_days":20.5,"median_age_days":11,"oldest_days":90,"defects":%s,"features":2,"security":1,"maintenance":3,"other":0},"closed_30d":{"total":30,"defects":10,"features":8,"security":2,"maintenance":9,"other":1,"median_lead_days":4},"milestone":{"title":"v9.9.9: <Test>","closed":3,"total":4}}\n' "$3" "$4" > "$1/health/$2.json"
}
P="$TMPROOT/p"
prompt "$P/one" "One" "Status" 7
page "$P/one" 2026-10-09 "x"
snap "$P" 2026-09-20 40 10
snap "$P" 2026-10-09 44 22
POUT="$(build "$P")"
PIDX="$P/index.html"
# 10 issues closed/week, 44 open, 50% defects (22/44), trend from 40 to 44.
if grep -q '<b>10</b><span>issues closed / week' "$PIDX" \
    && grep -q '<b>44</b><span>open issues' "$PIDX" \
    && grep -q 'class="warn"><b>50<small>%' "$PIDX" \
    && grep -q 'Open issues over time' "$PIDX" \
    && grep -q 'v9.9.9: &lt;Test&gt;' "$PIDX" \
    && grep -q 'badge fresh">current' "$PIDX" \
    && printf '%s' "$POUT" | grep -q 'index.html: 1 series.*health 2026-10-09'; then
    pass "Case 14: newest health snapshot drives the section, with trend and escaping"
else
    fail "Case 14: newest health snapshot drives the section, with trend and escaping" "out: [$POUT]"
fi

# --- Case 15: an old snapshot is stale; a bad one is skipped with a warning -
Q="$TMPROOT/q"
snap "$Q" 2026-09-01 40 10
printf 'not json' > "$Q/health/2026-10-01.json"
build "$Q" >/dev/null
if grep -q 'badge stale">39 days old' "$Q/index.html" \
    && ! grep -q 'Open issues over time' "$Q/index.html" \
    && grep -q 'health/2026-10-01.json skipped' "$TMPROOT/err"; then
    pass "Case 15: an old snapshot is stale, a bad one is skipped with a warning"
else
    fail "Case 15: an old snapshot is stale, a bad one is skipped with a warning" "err: [$(cat "$TMPROOT/err")]"
fi

# --- Case 16: the "How this index is built" section -------------------------
if grep -q 'How this index is built' "$IDX" \
    && grep -q 'aria-label="How the summaries index is built"' "$IDX" \
    && grep -q 'launchctl bootstrap gui/' "$IDX" \
    && grep -q '&lt;string&gt;org.elanregistry.summary-health&lt;/string&gt;' "$IDX" \
    && grep -q "cd &#x27;$REAL_REPO&#x27;" "$IDX"; then
    pass "Case 16: index explains how it is built and how to refresh it daily"
else
    fail "Case 16: index explains how it is built and how to refresh it daily"
fi

echo ""
echo "$TESTS_RUN scenario(s) run, $TESTS_FAILED failed."
[ "$TESTS_FAILED" -eq 0 ]
