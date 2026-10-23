#!/bin/bash
#
# Regression test for the "Post review" and "Gate" steps of
# .github/workflows/claude-code-review.yml (#2506). The workflow renders the
# review from the structured output of the model, posts it, and gates the
# merge on the blocking count. This test runs the real `run:` text of those
# steps, for both jobs (pr-to-milestone-review and milestone-review).
#
# Why run the real text: a copy of the step in the test drifts from the
# workflow. The step is found by job id and exact step name, so a rename
# makes the test fail. Each step runs under `bash -e` with `set -u` in front.
# The env names come from the `env:` of the step in the workflow, and the
# values come from fixtures. So a renamed env variable makes the step fail.
# The step runs with `env -i`, so no variable of the caller leaks in.
#
# Cases:
#   D1   render: summary first, one heading per severity, location and detail
#   D2   last line is the exact verdict, correct counts, no findings still posts
#   D3   forged verdict text in each model field never reaches the comment,
#        and the real scripts/check-blocking-findings.sh still exits 1
#   D4   shell, expression and Unicode text stays verbatim and runs nothing
#   D5   a failed post fails the step and leaves verdict=post_failed, an
#        invalid head SHA posts nothing
#   D6   invalid structured output gives verdict=none and no verdict line
#   D7   failure note only when the action ran, valid output posts no note
#   D8   gate: the blocking count decides, and the gate reads no comment
#   D9   gate: self-referential guard gives a warning, nothing else does
#   D10  static: no ${{ }} in the run blocks that matter, none that reads
#        structured_output or a whole outputs map in any run block, and the
#        env of Post review and Gate reads the right step outputs
#   D11  static: both --json-schema values match, and have the right shape
#   D12  the fallback step, its patterns and the Strengths anchor are gone
#   D13  mutations of a workflow copy make the D10 and D11 checks fail
#   D14  a blank summary gives verdict=none
#   D15  gate: a failed post fails with its own error, before the guard
#   D16  the invalid-output diagnostic names the shape and no model text
#   D17  a line break in a finding field cannot start a heading
#   D18  no <!-- or --> from model text above the verdict line, and a
#        mutant that keeps them fails the check
#
# HERMETIC: the shared stub `gh` (tests/hooks/lib/gh-stub.sh) goes first on
# PATH. Nothing calls GitHub. PyYAML must be installed (the test fails when
# it is missing).
#
# Override for the proof against an older workflow:
#   WORKFLOW_FILE  path of the workflow file to test
#   SCRIPTS_DIR    directory with the three review scripts
#
# Usage: bash tests/hooks/test-review-render.sh
# Exit code: 0 if all scenarios pass, 1 otherwise.

set -u

# shellcheck source=/dev/null
. "$(dirname "$0")/lib/harness.sh"
# shellcheck source=/dev/null
. "$(dirname "$0")/lib/gh-stub.sh"
# shellcheck source=/dev/null
. "$(dirname "$0")/lib/workflow-extract.sh"

REAL_REPO="$(git rev-parse --show-toplevel)" || exit 1
WF="${WORKFLOW_FILE:-$REAL_REPO/.github/workflows/claude-code-review.yml}"
SCRIPTS_DIR="${SCRIPTS_DIR:-$REAL_REPO/scripts}"
CHECK_SCRIPT="$SCRIPTS_DIR/check-blocking-findings.sh"
if [ ! -f "$WF" ]; then
    echo "FAIL: $WF not found" >&2
    exit 1
fi
if [ ! -x "$CHECK_SCRIPT" ]; then
    echo "FAIL: $CHECK_SCRIPT not found or not executable" >&2
    exit 1
fi
JQ_PATH="$(command -v jq)" || { echo "FAIL: jq is missing" >&2; exit 1; }
JQ_DIR="$(dirname "$JQ_PATH")"

JOBS="pr-to-milestone-review milestone-review"
HEAD_SHA_FIXTURE="0123456789abcdef0123456789abcdef01234567"
RUN_URL_FIXTURE="https://github.com/elan-registry/registry/actions/runs/123456"

TMPROOT="$(mktemp -d)" || exit 1
gh_stub_install "$TMPROOT/bin" || exit 1

# ---------------------------------------------------------------------------
# Fixture values. A test sets FX_* before it runs a step. A name that the
# workflow declares but this test does not know gets a marker value.
# ---------------------------------------------------------------------------
FX_STRUCTURED=""
FX_HEAD="$HEAD_SHA_FIXTURE"
FX_EXEC="set-by-case"
FX_VERDICT=""
FX_BLOCKING=""
FX_GH_FAIL=0
FX_MAIN_BLOB=""
FX_HEAD_BLOB=""
FV=""

# fixture_value <env-name>: sets FV.
fixture_value() {
    case "$1" in
        GH_TOKEN) FV="fake-token" ;;
        STRUCTURED_OUTPUT) FV="$FX_STRUCTURED" ;;
        HEAD_SHA | EVENT_HEAD_SHA) FV="$FX_HEAD" ;;
        PR_NUM) FV="42" ;;
        REPO) FV="elan-registry/registry" ;;
        RUN_URL) FV="$RUN_URL_FIXTURE" ;;
        EXECUTION_FILE) FV="$FX_EXEC" ;;
        VERDICT) FV="$FX_VERDICT" ;;
        BLOCKING) FV="$FX_BLOCKING" ;;
        *) FV="unknown-env-name-in-test" ;;
    esac
}

# new_case: makes a fresh directory with its own cwd, RUNNER_TEMP and stub
# files. Sets CASE.
CASE_N=0
new_case() {
    CASE_N=$((CASE_N + 1))
    CASE="$TMPROOT/case$CASE_N"
    mkdir -p "$CASE/cwd" "$CASE/tmp" "$CASE/posted"
    : > "$CASE/gh.log"
    : > "$CASE/github_output"
}

# run_step <workflow> <job> <step-name>
# Runs the step in $CASE. Sets STEP_RC, and writes $CASE/out and $CASE/err.
# Returns 99 when the step or its env cannot be read from the workflow.
run_step() {
    local wf="$1" job="$2" step="$3" line name envs key
    # Each extraction starts python3 and parses the whole workflow. The cases
    # run the same steps many times, so keep one extraction per step.
    key="$TMPROOT/extract-cache/$(printf '%s|%s|%s' "$wf" "$job" "$step" | cksum | tr ' ' '-')"
    if [ ! -f "$key.env" ] || [ ! -f "$key.sh" ]; then
        mkdir -p "$TMPROOT/extract-cache"
        wf_step_env "$wf" "$job" "$step" > "$key.env.tmp" 2> "$CASE/extract.err" || {
            STEP_RC=99
            cat "$CASE/extract.err" > "$CASE/err"
            return 99
        }
        {
            echo "set -u"
            wf_step_run "$wf" "$job" "$step"
        } > "$key.sh.tmp" 2> "$CASE/extract.err" || {
            STEP_RC=99
            cat "$CASE/extract.err" > "$CASE/err"
            return 99
        }
        mv "$key.env.tmp" "$key.env"
        mv "$key.sh.tmp" "$key.sh"
    fi
    envs="$(cat "$key.env")"
    cp "$key.sh" "$CASE/step.sh"
    local args=(
        "PATH=$TMPROOT/bin:$JQ_DIR:/usr/bin:/bin"
        "HOME=$CASE"
        "RUNNER_TEMP=$CASE/tmp"
        "GITHUB_OUTPUT=$CASE/github_output"
        "STUB_LOG=$CASE/gh.log"
        "STUB_POSTED_DIR=$CASE/posted"
        "STUB_GH_FAIL=$FX_GH_FAIL"
        "STUB_MAIN_BLOB=$FX_MAIN_BLOB"
        "STUB_HEAD_BLOB=$FX_HEAD_BLOB"
    )
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        name="${line%%=*}"
        fixture_value "$name"
        args+=("$name=$FV")
    done <<< "$envs"
    (cd "$CASE/cwd" && env -i "${args[@]}" bash -e "$CASE/step.sh") \
        > "$CASE/out" 2> "$CASE/err"
    STEP_RC=$?
    return 0
}

# post_case <job> <structured-output> [exec-file-value]
# POST_WF, when set, names another workflow file (a mutant) to run.
post_case() {
    FX_STRUCTURED="$2"
    FX_EXEC="${3-set-by-case}"
    FX_VERDICT=""
    FX_BLOCKING=""
    FX_GH_FAIL=0
    new_case
    [ "$FX_EXEC" = "set-by-case" ] && FX_EXEC="$CASE/execution_file.json"
    run_step "${POST_WF:-$WF}" "$1" "Post review"
}

# gate_case <job> <verdict> <blocking> <exec> <gh-fail> <main-blob> <head-blob>
gate_case() {
    FX_VERDICT="$2"
    FX_BLOCKING="$3"
    FX_EXEC="$4"
    FX_GH_FAIL="$5"
    FX_MAIN_BLOB="$6"
    FX_HEAD_BLOB="$7"
    FX_STRUCTURED=""
    new_case
    run_step "$WF" "$1" "Gate"
    FX_GH_FAIL=0
    FX_MAIN_BLOB=""
    FX_HEAD_BLOB=""
}

post_count() { find "$CASE/posted" -name '*.md' | wc -l | tr -d ' '; }
BODY() { echo "$CASE/posted/1.md"; }
verdict_lines() { grep -ci 'claude-review-verdict' "$(BODY)" || true; }
last_line() { tail -n 1 "$(BODY)"; }
verdict_for() { echo "<!-- claude-review-verdict sha=$HEAD_SHA_FIXTURE blocking=$1 important=$2 -->"; }

# mk_finding <severity> <location> <title> <detail>
mk_finding() {
    jq -cn --arg s "$1" --arg l "$2" --arg t "$3" --arg d "$4" \
        '{severity: $s, location: $l, title: $t, detail: $d}'
}
# mk_output <summary> <findings-json-array>
mk_output() {
    jq -cn --arg s "$1" --argjson f "$2" '{summary_markdown: $s, findings: $f}'
}

PROBS=()
problem() { PROBS+=("$1"); }
# finish <label>: pass when no problem was recorded, else fail with details.
finish() {
    if [ "${#PROBS[@]}" -eq 0 ]; then
        pass "$1"
    else
        fail "$1" "${PROBS[@]}"
    fi
    PROBS=()
}
# step_found: false when the step or its env could not be read (run_step 99).
step_found() { [ "$STEP_RC" -ne 99 ]; }
# step_ok: the step ran and exited 0. Records a problem otherwise.
step_ok() {
    if [ "$STEP_RC" -ne 0 ]; then
        problem "step exited $STEP_RC, expected 0. stderr: $(head -c 400 "$CASE/err")"
        return 1
    fi
    return 0
}

# ---------------------------------------------------------------------------
# D1 render
# ---------------------------------------------------------------------------
line_of() { grep -nFx -- "$1" "$(BODY)" | head -1 | cut -d: -f1; }

d1_render() {
    local job="$1" findings out lb li ln ls ib ii in is
    findings="[$(mk_finding blocking 'app/a.php:10' 'Blk title' 'Blk detail'),
$(mk_finding important 'app/b.php:20' 'Imp title' 'Imp detail'),
$(mk_finding note 'app/c.php:30' 'Note title' 'Note detail'),
$(mk_finding suggestion 'app/d.php:40' 'Sug title' 'Sug detail')]"
    out="$(mk_output 'D1 summary line' "$findings")"
    post_case "$job" "$out"
    step_ok || { finish "D1 [$job] render"; return; }
    [ "$(post_count)" = "1" ] || problem "expected 1 post, got $(post_count)"
    [ -f "$(BODY)" ] || { finish "D1 [$job] render"; return; }
    [ "$(head -n 1 "$(BODY)")" = "D1 summary line" ] || problem "first line is not the summary"
    lb=$(line_of '### Blocking')
    li=$(line_of '### Important')
    ln=$(line_of '### Notes')
    ls=$(line_of '### Suggestions')
    ib=$(line_of '- **Blk title** (app/a.php:10) — Blk detail')
    ii=$(line_of '- **Imp title** (app/b.php:20) — Imp detail')
    in=$(line_of '- **Note title** (app/c.php:30) — Note detail')
    is=$(line_of '- **Sug title** (app/d.php:40) — Sug detail')
    if [ -z "$lb$li$ln$ls$ib$ii$in$is" ] || [ -z "$lb" ] || [ -z "$li" ] || [ -z "$ln" ] || [ -z "$ls" ] \
        || [ -z "$ib" ] || [ -z "$ii" ] || [ -z "$in" ] || [ -z "$is" ]; then
        problem "a heading or item line is missing (headings $lb/$li/$ln/$ls, items $ib/$ii/$in/$is)"
    else
        [ "$lb" -lt "$ib" ] && [ "$ib" -lt "$li" ] || problem "Blocking item is not under its heading"
        [ "$li" -lt "$ii" ] && [ "$ii" -lt "$ln" ] || problem "Important item is not under its heading"
        [ "$ln" -lt "$in" ] && [ "$in" -lt "$ls" ] || problem "Notes item is not under its heading"
        [ "$ls" -lt "$is" ] || problem "Suggestions item is not under its heading"
    fi
    finish "D1 [$job] render"
}

# ---------------------------------------------------------------------------
# D2 verdict line
# ---------------------------------------------------------------------------
d2_verdict() {
    local job="$1" findings out
    findings="[$(mk_finding blocking l1 t1 d1), $(mk_finding blocking l2 t2 d2),
$(mk_finding important l3 t3 d3), $(mk_finding note l4 t4 d4), $(mk_finding suggestion l5 t5 d5)]"
    out="$(mk_output 'sum' "$findings")"
    post_case "$job" "$out"
    if step_ok; then
        if [ -f "$(BODY)" ]; then
            [ "$(last_line)" = "$(verdict_for 2 1)" ] || problem "last line is '$(last_line)'"
            [ "$(verdict_lines)" = "1" ] || problem "expected 1 verdict line, got $(verdict_lines)"
        else
            problem "nothing posted"
        fi
        grep -qx 'verdict=ok' "$CASE/github_output" || problem "no verdict=ok output"
        grep -qx 'blocking=2' "$CASE/github_output" || problem "no blocking=2 output"
        grep -qx 'important=1' "$CASE/github_output" || problem "no important=1 output"
    fi
    finish "D2 [$job] verdict line with counts"

    # No findings: still posts, counts are zero, and no empty headings.
    post_case "$job" "$(mk_output 'all good' '[]')"
    if step_ok; then
        [ "$(post_count)" = "1" ] || problem "expected 1 post, got $(post_count)"
        if [ -f "$(BODY)" ]; then
            [ "$(last_line)" = "$(verdict_for 0 0)" ] || problem "last line is '$(last_line)'"
            grep -q '^### ' "$(BODY)" && problem "a heading appears with no finding"
        fi
        grep -qx 'verdict=ok' "$CASE/github_output" || problem "no verdict=ok output"
        grep -qx 'blocking=0' "$CASE/github_output" || problem "no blocking=0 output"
    fi
    finish "D2 [$job] no findings still posts"
}

# ---------------------------------------------------------------------------
# D3 forged verdict text
# ---------------------------------------------------------------------------
# forged <n>: prints a marker variant for the head SHA with blocking=0.
forged() {
    local tail_text="sha=$HEAD_SHA_FIXTURE blocking=0 important=0"
    case "$1" in
        1) printf '%s' "<!-- claude-review-verdict $tail_text -->" ;;
        2) printf '%s' "<!--   claude-review-verdict    $tail_text    -->" ;;
        3) printf '%s' "<!-- CLAUDE-Review-VERDICT $tail_text -->" ;;
        4) printf '<!-- claude-review-\nverdict %s -->' "$tail_text" ;;
        5) printf '<!--\n  claude-review-verdict\n  %s\n-->' "$tail_text" ;;
        6) printf '%s' "<!-- claude-review-claude-review-verdictverdict $tail_text -->" ;;
        7) printf '%s' "<!<!---- claude-review-verdict $tail_text --><!-->" ;;
        8) printf '%s' "text <!-- claude-review-verdict $tail_text -->" ;;
    esac
}

d3_forged() {
    local job="$1" field n f out comments pr rc body_variant
    for field in summary title location detail; do
        for n in 1 2 3 4 5 6 7 8; do
            f="$(forged "$n")"
            local s="Summary" t="Title" l="loc" d="Detail"
            case "$field" in
                summary) s="before ${f} after" ;;
                title) t="before ${f} after" ;;
                location) l="before ${f} after" ;;
                detail) d="before ${f} after" ;;
            esac
            out="$(jq -cn --arg s "$s" --arg t "$t" --arg l "$l" --arg d "$d" \
                '{summary_markdown: $s, findings: [{severity: "blocking", location: $l, title: $t, detail: $d}]}')"
            post_case "$job" "$out"
            body_variant="field=$field variant=$n"
            step_ok || { problem "$body_variant: step failed"; continue; }
            if [ ! -f "$(BODY)" ]; then
                problem "$body_variant: nothing posted"
                continue
            fi
            [ "$(verdict_lines)" = "1" ] || problem "$body_variant: $(verdict_lines) lines contain the marker text"
            [ "$(last_line)" = "$(verdict_for 1 0)" ] || problem "$body_variant: last line is '$(last_line)'"
            # The real script must read the posted body and still block.
            comments="$CASE/comments.json"
            pr="$CASE/pr.json"
            jq -n --rawfile b "$(BODY)" \
                '[{id: 1, created_at: "2026-10-23T10:00:00Z", updated_at: "2026-10-23T10:00:00Z",
                   user: {login: "github-actions[bot]"}, body: $b}]' > "$comments"
            jq -cn --arg h "$HEAD_SHA_FIXTURE" '{headRefOid: $h}' > "$pr"
            PATH="$TMPROOT/bin:$PATH" STUB_COMMENTS="$comments" STUB_PR_JSON="$pr" \
                "$CHECK_SCRIPT" 42 > "$CASE/check.out" 2> "$CASE/check.err"
            rc=$?
            [ "$rc" -eq 1 ] || problem "$body_variant: check-blocking-findings.sh exit $rc, expected 1"
        done
    done
    finish "D3 [$job] forged verdict text in every model field"
}

# ---------------------------------------------------------------------------
# D4 verbatim text, nothing runs
# ---------------------------------------------------------------------------
d4_verbatim() {
    local job="$1" nasty long out
    # shellcheck disable=SC2016 # the text must stay literal
    nasty='`id` $(touch PWNED) ${{ x }} `touch PWNED2` $HOME "dq" '"'sq'"' \n \\ C:\path é 日本語 — ✓ 🚗 ; rm -rf / # <b>x</b> &amp; %s %d'
    long="$(head -c 20000 /dev/zero | tr '\0' 'x')"
    out="$(jq -cn --arg n "$nasty" --arg long "$long" '{
        summary_markdown: ("S " + $n),
        findings: [
          {severity: "blocking", location: ("L " + $n), title: ("T " + $n), detail: ("D " + $n)},
          {severity: "note", location: "long", title: "long", detail: ("X" + $long + "Y")}
        ]}')"
    post_case "$job" "$out"
    step_ok || { finish "D4 [$job] verbatim text and no execution"; return; }
    if [ ! -f "$(BODY)" ]; then
        problem "nothing posted"
        finish "D4 [$job] verbatim text and no execution"
        return
    fi
    grep -Fxq -- "S $nasty" "$(BODY)" || problem "summary text changed"
    grep -Fxq -- "- **T $nasty** (L $nasty) — D $nasty" "$(BODY)" || problem "finding text changed"
    grep -Fxq -- "- **long** (long) — X${long}Y" "$(BODY)" || problem "20 KB text changed"
    [ -z "$(find "$CASE" -name 'PWNED*')" ] || problem "a PWNED file exists: $(find "$CASE" -name 'PWNED*')"
    [ ! -e ./PWNED ] && [ ! -e ./PWNED2 ] || problem "a PWNED file exists in the cwd of the test"
    finish "D4 [$job] verbatim text and no execution"
}

# ---------------------------------------------------------------------------
# D5 post failure and bad head SHA
# ---------------------------------------------------------------------------
d5_post_failure() {
    local job="$1"
    FX_GH_FAIL=1
    FX_STRUCTURED="$(mk_output 'sum' "[$(mk_finding note l t d)]")"
    FX_EXEC="set-by-case"
    FX_VERDICT=""
    FX_BLOCKING=""
    new_case
    run_step "$WF" "$job" "Post review"
    FX_GH_FAIL=0
    step_found || problem "cannot read the step"
    [ "$STEP_RC" -ne 0 ] || problem "a failed gh pr comment did not fail the step"
    grep -qx 'verdict=ok' "$CASE/github_output" && problem "verdict=ok written although the post failed"
    # Actions uses the last value of a key, so the last verdict line counts.
    [ "$(grep '^verdict=' "$CASE/github_output" | tail -n 1)" = "verdict=post_failed" ] \
        || problem "the last verdict output is not verdict=post_failed: [$(grep '^verdict=' "$CASE/github_output" | tr '\n' ' ')]"
    finish "D5 [$job] a failed post fails the step and writes verdict=post_failed"

    FX_HEAD="not-a-sha"
    post_case "$job" "$(mk_output 'sum' '[]')"
    FX_HEAD="$HEAD_SHA_FIXTURE"
    step_found || problem "cannot read the step"
    [ "$STEP_RC" -ne 0 ] || problem "an invalid head SHA did not fail the step"
    [ "$(post_count)" = "0" ] || problem "a comment was posted with an invalid head SHA"
    grep -qx 'verdict=ok' "$CASE/github_output" && problem "verdict=ok written with an invalid head SHA"
    finish "D5 [$job] an invalid head SHA posts nothing"
}

# ---------------------------------------------------------------------------
# D6 / D7 invalid output
# ---------------------------------------------------------------------------
invalid_inputs() {
    printf '%s\n' \
        '' \
        'null' \
        '{}' \
        '[]' \
        'not json at all' \
        '{"summary_markdown":"s"}' \
        '{"summary_markdown":"s","findings":{}}' \
        '{"summary_markdown":1,"findings":[]}' \
        '{"summary_markdown":"s","findings":[{"location":"l","title":"t","detail":"d"}]}' \
        '{"summary_markdown":"s","findings":[{"severity":"critical","location":"l","title":"t","detail":"d"}]}' \
        '{"summary_markdown":"s","findings":[{"severity":"BLOCKING","location":"l","title":"t","detail":"d"}]}' \
        '{"summary_markdown":"s","findings":[{"severity":"note","location":"l","title":"t"}]}' \
        '{"summary_markdown":"s","findings":[{"severity":"note","location":1,"title":"t","detail":"d"}]}' \
        '{"summary_markdown":"s","findings":[]}{"summary_markdown":"s","findings":[]}'
}

d6_invalid() {
    local job="$1" input
    while IFS= read -r input; do
        post_case "$job" "$input"
        step_ok || continue
        grep -qx 'verdict=none' "$CASE/github_output" || problem "input '$input': no verdict=none"
        grep -qx 'verdict=ok' "$CASE/github_output" && problem "input '$input': verdict=ok written"
        if [ -f "$(BODY)" ] && [ "$(verdict_lines)" != "0" ]; then
            problem "input '$input': the posted note has verdict text"
        fi
    done <<< "$(invalid_inputs)"
    finish "D6 [$job] invalid output gives verdict=none and no verdict line"
}

d7_failure_note() {
    local job="$1" input
    while IFS= read -r input; do
        post_case "$job" "$input"
        step_ok || continue
        if [ "$(post_count)" != "1" ]; then
            problem "input '$input': expected 1 note, got $(post_count)"
            continue
        fi
        [ "$(verdict_lines)" = "0" ] || problem "input '$input': note has verdict text"
        grep -qF -- "$RUN_URL_FIXTURE" "$(BODY)" || problem "input '$input': note has no run URL"
    done <<< "$(invalid_inputs)"
    finish "D7 [$job] failure note when the action ran"

    post_case "$job" "" ""
    if step_ok; then
        [ "$(post_count)" = "0" ] || problem "a comment was posted with no execution file"
        grep -q 'pr comment' "$CASE/gh.log" && problem "gh pr comment was called with no execution file"
        grep -qx 'verdict=none' "$CASE/github_output" || problem "no verdict=none output"
    fi
    finish "D7 [$job] no note when the action did not run"

    post_case "$job" "$(mk_output 'sum' "[$(mk_finding note l t d)]")"
    if step_ok; then
        [ "$(post_count)" = "1" ] || problem "expected 1 post, got $(post_count)"
        if [ -f "$(BODY)" ]; then
            grep -q 'did not return a valid review' "$(BODY)" && problem "a failure note was posted"
            [ "$(verdict_lines)" = "1" ] || problem "the review has no verdict line"
        else
            problem "nothing posted"
        fi
    fi
    finish "D7 [$job] valid output posts no note"
}

# ---------------------------------------------------------------------------
# D8 / D9 gate
# ---------------------------------------------------------------------------
d8_gate() {
    local job="$1" row verdict blocking want
    # Rows are "verdict|blocking|expected exit". The gate gets no important
    # count, so a review with important findings only reaches it as
    # blocking=0.
    for row in "ok|0|0" "ok|1|1" "ok|12|1" "ok|abc|1" "ok||1" "none||1"; do
        IFS='|' read -r verdict blocking want <<< "$row"
        gate_case "$job" "$verdict" "$blocking" "set-by-case" 1 "" ""
        if [ "$STEP_RC" = "99" ]; then problem "$row: cannot read the step"; continue; fi
        [ "$STEP_RC" = "$want" ] || problem "verdict='$verdict' blocking='$blocking': exit $STEP_RC, expected $want"
        if [ "$want" = "1" ] && ! grep -q '^::error::' "$CASE/out"; then
            problem "verdict='$verdict' blocking='$blocking': no ::error:: line"
        fi
        if [ "$verdict" = "ok" ] && grep -qE 'comments|pr view' "$CASE/gh.log"; then
            problem "verdict=ok: the gate read a comment or the PR"
        fi
    done
    finish "D8 [$job] gate follows the blocking count and works with gh failing"
}

d9_guard() {
    local job="$1"
    # No execution file, blobs differ: warning, exit 0.
    gate_case "$job" "none" "" "" 0 "mainblob111" "headblob222"
    [ "$STEP_RC" = "0" ] || problem "guard case: exit $STEP_RC, expected 0"
    grep -q '^::warning::' "$CASE/out" || problem "guard case: no ::warning:: line"
    grep -q "ref=$HEAD_SHA_FIXTURE" "$CASE/gh.log" || problem "guard case: no blob lookup at the head SHA"
    # Same for an empty verdict (the step that never ran writes no output).
    gate_case "$job" "" "" "" 0 "mainblob111" "headblob222"
    [ "$STEP_RC" = "0" ] || problem "empty verdict guard case: exit $STEP_RC, expected 0"
    # Blobs equal: error.
    gate_case "$job" "none" "" "" 0 "sameblob" "sameblob"
    [ "$STEP_RC" = "1" ] || problem "equal blobs: exit $STEP_RC, expected 1"
    grep -q '^::error::' "$CASE/out" || problem "equal blobs: no ::error:: line"
    # Execution file present: error, even when blobs differ.
    gate_case "$job" "none" "" "set-by-case" 0 "mainblob111" "headblob222"
    [ "$STEP_RC" = "1" ] || problem "execution file with no output: exit $STEP_RC, expected 1"
    # Lookup failure: fail closed.
    gate_case "$job" "none" "" "" 1 "mainblob111" "headblob222"
    [ "$STEP_RC" = "1" ] || problem "blob lookup fails: exit $STEP_RC, expected 1"
    gate_case "$job" "none" "" "" 0 "" "headblob222"
    [ "$STEP_RC" = "1" ] || problem "empty main blob: exit $STEP_RC, expected 1"
    finish "D9 [$job] self-referential guard warns only when it must"
}

# ---------------------------------------------------------------------------
# D14 blank summary
# ---------------------------------------------------------------------------
d14_blank_summary() {
    local job="$1" summary
    for summary in "" "   " $' \n\t \r\n'; do
        post_case "$job" "$(mk_output "$summary" "[$(mk_finding note l t d)]")"
        step_ok || continue
        grep -qx 'verdict=none' "$CASE/github_output" || problem "summary '$summary': no verdict=none"
        grep -qx 'verdict=ok' "$CASE/github_output" && problem "summary '$summary': verdict=ok written"
        [ "$(post_count)" = "1" ] || { problem "summary '$summary': expected 1 note, got $(post_count)"; continue; }
        [ "$(verdict_lines)" = "0" ] || problem "summary '$summary': the note has verdict text"
        grep -q '^::warning::The structured output is not valid: .*summary=blank' "$CASE/out" \
            || problem "summary '$summary': no diagnostic with summary=blank"
    done
    finish "D14 [$job] a blank summary gives verdict=none"
}

# ---------------------------------------------------------------------------
# D15 gate on a failed post
# ---------------------------------------------------------------------------
d15_post_failed_gate() {
    local job="$1" want="::error::The review output is valid, but the PR comment post failed. See the Post review log."
    # The guard conditions are all true here (no execution file, blobs
    # differ). A failed post must still fail, before the guard runs.
    gate_case "$job" "post_failed" "" "" 0 "mainblob111" "headblob222"
    step_found || problem "cannot read the step"
    [ "$STEP_RC" = "1" ] || problem "guard conditions true: exit $STEP_RC, expected 1"
    grep -qxF -- "$want" "$CASE/out" || problem "guard conditions true: no post-failed error. stdout: $(head -c 300 "$CASE/out")"
    grep -q 'contents' "$CASE/gh.log" && problem "the gate looked up the workflow blob for a failed post"
    gate_case "$job" "post_failed" "0" "set-by-case" 0 "" ""
    [ "$STEP_RC" = "1" ] || problem "blocking=0: exit $STEP_RC, expected 1"
    grep -qxF -- "$want" "$CASE/out" || problem "blocking=0: no post-failed error"
    finish "D15 [$job] gate: a failed post fails with its own error, before the guard"
}

# ---------------------------------------------------------------------------
# D16 the invalid-output diagnostic has no model text
# ---------------------------------------------------------------------------
d16_diagnostic() {
    local job="$1" s="zq9SENTINEL7x" input want f
    while IFS='|' read -r input want; do
        post_case "$job" "$input"
        step_ok || continue
        grep -q "^::warning::The structured output is not valid: $want" "$CASE/out" \
            || problem "input '$input': no diagnostic '$want'. stdout: $(head -c 300 "$CASE/out")"
        for f in "$CASE/out" "$CASE/err" "$CASE/github_output" "$CASE"/posted/*.md; do
            [ -f "$f" ] || continue
            grep -qF -- "$s" "$f" && problem "input '$input': model text reached $(basename "$f")"
        done
    done <<EOF
{"summary_markdown":"$s","findings":{}}|values=1 type=object keys=\[findings,summary_markdown\]$
{"summary_markdown":"s","findings":[{"severity":"critical","location":"$s","title":"$s","detail":"$s"}]}|values=1 type=object .*bad_severities=\[critical\]
{"summary_markdown":"s","findings":[{"severity":"$s bad","location":"l","title":"t","detail":"d"}]}|.*bad_severities=\[<other>\]
{"$s key":"$s","findings":[]}|values=1 type=object keys=\[findings,<other>\]
{"summary_markdown":"  ","findings":[],"x":"$s"}|.*summary=blank
"$s"|values=1 type=string$
$s is not json|not JSON (length
EOF
    finish "D16 [$job] the invalid-output diagnostic names the shape and no model text"
}

# ---------------------------------------------------------------------------
# D17 a line break in a finding field cannot start a heading
# ---------------------------------------------------------------------------
d17_line_breaks() {
    local job="$1" out
    out="$(jq -cn '{summary_markdown: "Sum", findings: [{severity: "note",
        location: "l\r\n### Blocking", title: "a\n### Blocking\nb", detail: "d\n\n### Blocking\r- x"}]}')"
    post_case "$job" "$out"
    if step_ok && [ -f "$(BODY)" ]; then
        grep -q '^### Blocking' "$(BODY)" && problem "a line starts with '### Blocking': $(grep -n '^### Blocking' "$(BODY)" | head -n 1)"
        grep -qxF -- '- **a ### Blocking b** (l ### Blocking) — d ### Blocking - x' "$(BODY)" \
            || problem "the finding is not one list item line"
        [ "$(last_line)" = "$(verdict_for 0 0)" ] || problem "last line is '$(last_line)'"
    else
        problem "the step failed or posted nothing"
    fi
    finish "D17 [$job] a line break in a finding field stays inside its list item"
}

# ---------------------------------------------------------------------------
# D18 HTML comment delimiters in model text (takes a workflow path, so the
# mutant check can reuse it)
# ---------------------------------------------------------------------------
# d18_delims <workflow> <job>: records a problem for each delimiter that
# reaches the body above the verdict line.
d18_delims() {
    local wf="$1" job="$2" out
    out="$(jq -cn '{summary_markdown: "S <!-- hid --> mid -->x<!--\nline2 <!--",
        findings: [{severity: "note", location: "L <!-- a -->", title: "T --> b <!--",
        detail: "D <!-<!---- c ---->>"}]}')"
    POST_WF="$wf" post_case "$job" "$out"
    if ! step_ok || [ ! -f "$(BODY)" ]; then
        problem "the step failed or posted nothing"
        return
    fi
    sed '$d' "$(BODY)" | grep -nF -e '<!--' -e '-->' > "$CASE/delims.out" \
        && problem "a delimiter is above the verdict line: $(head -n 1 "$CASE/delims.out")"
    [ "$(last_line)" = "$(verdict_for 0 0)" ] || problem "last line is '$(last_line)'"
}

# ---------------------------------------------------------------------------
# D10 static checks on ${{ }} (take a workflow path, so D13 can reuse them)
# ---------------------------------------------------------------------------
# d10_check <workflow>: prints each violation, returns 1 when there is one.
d10_check() {
    python3 -I - "$1" <<'PY'
import re
import sys

try:
    import yaml
except ImportError:
    sys.stderr.write("D10: PyYAML is missing\n")
    sys.exit(1)

with open(sys.argv[1], encoding="utf-8") as handle:
    doc = yaml.load(handle, Loader=yaml.BaseLoader)

bad = 0
reads_output = re.compile(r"\$\{\{[^}]*structured_output", re.S)
# An expression that names steps must end at one output field. toJSON(steps)
# or steps.claude-review.outputs alone would print every output, and
# structured_output with them.
one_field = re.compile(r"\bsteps\b(?!\.[A-Za-z0-9_-]+\.outputs\.[A-Za-z0-9_]+)")
CLAUDE = "${{ steps.claude-review.outputs.%s }}"
HEAD = "${{ steps.head.outputs.sha }}"


def wiring(label, env, expected):
    global bad
    for key, want in expected.items():
        got = env.get(key)
        if got != want:
            print("D10: wiring: %s env %s is %r, expected %r" % (label, key, got, want))
            bad = 1


for job, spec in doc["jobs"].items():
    steps = spec.get("steps", [])
    names = [s.get("name") for s in steps]
    for must in ("Post review", "Gate"):
        if must not in names:
            print("D10: step missing in %s: %s" % (job, must))
            bad = 1
    ids = [s.get("id") for s in steps]
    for must in ("claude-review", "head"):
        if must not in ids:
            print("D10: no step with id %s in %s" % (must, job))
            bad = 1
    post = next((s for s in steps if s.get("name") == "Post review"), {})
    gate = next((s for s in steps if s.get("name") == "Gate"), {})
    post_id = post.get("id")
    if not post_id:
        print("D10: wiring: %s / Post review has no id" % job)
        bad = 1
    wiring("%s / Post review" % job, post.get("env") or {}, {
        "STRUCTURED_OUTPUT": CLAUDE % "structured_output",
        "EXECUTION_FILE": CLAUDE % "execution_file",
        "HEAD_SHA": HEAD,
    })
    wiring("%s / Gate" % job, gate.get("env") or {}, {
        "VERDICT": "${{ steps.%s.outputs.verdict }}" % post_id,
        "BLOCKING": "${{ steps.%s.outputs.blocking }}" % post_id,
        "EXECUTION_FILE": CLAUDE % "execution_file",
        "HEAD_SHA": HEAD,
    })
    for step in steps:
        run = step.get("run", "")
        label = "%s / %s" % (job, step.get("name"))
        if "${{" in run and step.get("name") in ("Post review", "Gate"):
            print("D10: ${{ }} in run block of %s" % label)
            bad = 1
        if reads_output.search(run):
            print("D10: ${{ }} reads structured_output in run block of %s" % label)
            bad = 1
        for expr in re.findall(r"\$\{\{(.*?)\}\}", run, re.S):
            if one_field.search(expr):
                print("D10: whole steps object or outputs map in run block of %s" % label)
                bad = 1
sys.exit(bad)
PY
}

# ---------------------------------------------------------------------------
# D11 static checks on the schema (take a workflow path)
# ---------------------------------------------------------------------------
# d11_check <workflow>: prints each violation, returns 1 when there is one.
d11_check() {
    local wf="$1" job schema first="" norm bad=0 sev
    for job in $JOBS; do
        if ! schema="$(wf_json_schema "$wf" "$job" 2>&1)"; then
            echo "D11: no --json-schema in job $job ($schema)"
            bad=1
            continue
        fi
        if ! printf '%s' "$schema" | jq -e . > /dev/null 2>&1; then
            echo "D11: --json-schema of $job is not valid JSON"
            bad=1
            continue
        fi
        norm="$(printf '%s' "$schema" | jq -S -c .)"
        if [ -z "$first" ]; then
            first="$norm"
        elif [ "$first" != "$norm" ]; then
            echo "D11: the --json-schema values of the two jobs differ (job $job)"
            bad=1
        fi
        printf '%s' "$schema" | jq -e '(.required | sort) == ["findings", "summary_markdown"]' > /dev/null \
            || { echo "D11: top-level required fields wrong in $job"; bad=1; }
        printf '%s' "$schema" | jq -e '(.properties.findings.items.required | sort) == ["detail", "location", "severity", "title"]' > /dev/null \
            || { echo "D11: finding required fields wrong in $job"; bad=1; }
        printf '%s' "$schema" | jq -e '.properties.findings.items.properties.severity.enum == ["blocking", "important", "note", "suggestion"]' > /dev/null \
            || { echo "D11: severity enum wrong in $job"; bad=1; }
        local run
        run="$(wf_step_run "$wf" "$job" "Post review" 2> /dev/null)" \
            || { echo "D11: no Post review step in $job"; bad=1; continue; }
        for sev in blocking important note suggestion; do
            printf '%s' "$run" | grep -qF "\"$sev\"" \
                || { echo "D11: Post review of $job never names severity $sev"; bad=1; }
        done
    done
    return "$bad"
}

d10_d11_main() {
    local out
    if out="$(d10_check "$WF" 2>&1)"; then
        pass "D10 no \${{ }} in Post review and Gate, none reads structured_output, env wiring is right"
    else
        fail "D10 expression use in run blocks" "$out"
    fi
    if out="$(d11_check "$WF" 2>&1)"; then
        pass "D11 both --json-schema values match and have the right shape"
    else
        fail "D11 schema check" "$out"
    fi
}

# D11 behavior: each enum value of the schema renders under a heading.
d11_render_each_severity() {
    local job="$1" sev schema headings=0
    schema="$(wf_json_schema "$WF" "$job" 2> /dev/null)" || { fail "D11 [$job] cannot read the schema"; return; }
    for sev in $(printf '%s' "$schema" | jq -r '.properties.findings.items.properties.severity.enum[]'); do
        post_case "$job" "$(mk_output 'sum' "[$(mk_finding "$sev" l "title-$sev" d)]")"
        step_ok || continue
        if [ -f "$(BODY)" ] && awk -v t="- **title-$sev**" '/^### /{h=1} index($0,t)==1 && h {f=1} END{exit !f}' "$(BODY)"; then
            headings=$((headings + 1))
        else
            problem "severity $sev has no heading with its finding"
        fi
    done
    [ "$headings" -eq 4 ] || problem "expected 4 severities under a heading, got $headings"
    finish "D11 [$job] each enum value renders under a heading"
}

# ---------------------------------------------------------------------------
# D12 removals
# ---------------------------------------------------------------------------
d12_removals() {
    local pat job args
    for pat in 'name: Fallback' 'fallback-review-body' 'name: Record run start time' \
        'REVIEW_FILTER' 'HEADING_PATTERN' 'EXCLUSION_PATTERN' 'Bash(gh pr comment'; do
        grep -nF -- "$pat" "$WF" > "$TMPROOT/d12.out" 2> /dev/null \
            && problem "workflow still has '$pat' ($(head -n 1 "$TMPROOT/d12.out"))"
    done
    # A Strengths anchor is a jq or regex test. Prose that names a section
    # in the review prompt is not a detection.
    grep -nE '(test|select|--jq|jq).*Strengths' "$WF" > "$TMPROOT/d12.out" 2> /dev/null \
        && problem "workflow still detects a Strengths section ($(head -n 1 "$TMPROOT/d12.out"))"
    for pat in REVIEW_FILTER HEADING_PATTERN EXCLUSION_PATTERN Strengths; do
        grep -nF -- "$pat" "$SCRIPTS_DIR/check-review-posted.sh" "$SCRIPTS_DIR/check-blocking-findings.sh" \
            "$SCRIPTS_DIR/verify-ci-review.sh" > "$TMPROOT/d12.out" 2> /dev/null \
            && problem "a script still has '$pat' ($(head -n 1 "$TMPROOT/d12.out"))"
    done
    for job in $JOBS; do
        args="$(wf_claude_args "$WF" "$job" 2> /dev/null)" || { problem "no claude_args in $job"; continue; }
        printf '%s' "$args" | grep -qF 'mcp__github_inline_comment__create_inline_comment' \
            || problem "$job no longer allows the inline-comment tool"
        printf '%s' "$args" | grep -qF 'gh pr comment' \
            && problem "$job still allows gh pr comment"
    done
    finish "D12 fallback step, patterns and Strengths anchor are gone"
}

# ---------------------------------------------------------------------------
# D13 mutations of a workflow copy
# ---------------------------------------------------------------------------
# mutate <mode> <in> <out>: writes a changed copy. Exits 1 when the change
# did not apply, so a mutation cannot silently do nothing.
mutate() {
    python3 -I - "$@" <<'PY'
import re
import sys

mode, src, dst = sys.argv[1:4]
text = open(src, encoding="utf-8").read()
expr = "${{ steps.claude-review.outputs.structured_output }}"

if mode == "rename-severity":
    old = '"enum":["blocking","important","note","suggestion"]'
    new = '"enum":["blocking","important","note","suggestions"]'
    if old not in text:
        sys.exit(1)
    text = text.replace(old, new, 1)
elif mode in ("expr-gate", "expr-gate-tight", "expr-record", "tojson-steps", "tojson-outputs"):
    marker = "- name: Gate" if mode.startswith("expr-gate") else "- name: Record head SHA"
    start = text.index(marker)
    anchor = "          set -euo pipefail\n"
    pos = text.index(anchor, start) + len(anchor)
    value = {
        "expr-gate-tight": expr.replace("${{ ", "${{").replace(" }}", "}}"),
        "tojson-steps": "${{ toJSON(steps) }}",
        "tojson-outputs": "${{ toJSON(steps.claude-review.outputs) }}",
    }.get(mode, expr)
    text = text[:pos] + '          echo "' + value + '"\n' + text[pos:]
elif mode in ("blocking-important", "rename-post-id", "exec-session", "keep-delims"):
    old, new = {
        "blocking-important": ("BLOCKING: ${{ steps.post.outputs.blocking }}",
                               "BLOCKING: ${{ steps.post.outputs.important }}"),
        "rename-post-id": ("        id: post\n", "        id: posted\n"),
        "exec-session": ("EXECUTION_FILE: ${{ steps.claude-review.outputs.execution_file }}",
                         "EXECUTION_FILE: ${{ steps.claude-review.outputs.session_id }}"),
        "keep-delims": (' | gsub("<!--"; "") | gsub("-->"; "")', ""),
    }[mode]
    if old not in text:
        sys.exit(1)
    # keep-delims changes both jobs. The others change the first job only.
    text = text.replace(old, new) if mode == "keep-delims" else text.replace(old, new, 1)
elif mode == "expr-post":
    start = text.index("- name: Post review")
    anchor = "          set -euo pipefail\n"
    pos = text.index(anchor, start) + len(anchor)
    text = text[:pos] + '          echo "${{ github.run_id }}"\n' + text[pos:]
elif mode == "drop-schema":
    lines = text.split("\n")
    # The first match is a header comment. Only the claude_args line counts.
    idx = [i for i, line in enumerate(lines) if line.lstrip().startswith("--json-schema '")]
    if not idx:
        sys.exit(1)
    del lines[idx[0]]
    text = "\n".join(lines)
else:
    sys.exit(2)

open(dst, "w", encoding="utf-8").write(text)
PY
}

d13_mutations() {
    local mode check want copy out rc dir="$TMPROOT/mutants"
    mkdir -p "$dir"

    # The unchanged copy must pass both checks, or a mutation result proves
    # nothing.
    cp "$WF" "$dir/clean.yml"
    d10_check "$dir/clean.yml" > "$dir/clean.out" 2>&1 || problem "D10 fails on the unchanged copy: $(head -n 1 "$dir/clean.out")"
    d11_check "$dir/clean.yml" > "$dir/clean.out" 2>&1 || problem "D11 fails on the unchanged copy: $(head -n 1 "$dir/clean.out")"
    finish "D13 the checks pass on an unchanged copy"

    # Rows are "mode|check|text the failure message must contain".
    for row in \
        "rename-severity|d11_check|differ" \
        "drop-schema|d11_check|no --json-schema" \
        "expr-gate|d10_check|reads structured_output in run block of pr-to-milestone-review / Gate" \
        "expr-gate-tight|d10_check|reads structured_output in run block of pr-to-milestone-review / Gate" \
        "expr-record|d10_check|reads structured_output in run block of pr-to-milestone-review / Record head SHA" \
        "expr-post|d10_check|in run block of pr-to-milestone-review / Post review" \
        "tojson-steps|d10_check|whole steps object or outputs map in run block of pr-to-milestone-review / Record head SHA" \
        "tojson-outputs|d10_check|whole steps object or outputs map in run block of pr-to-milestone-review / Record head SHA" \
        "blocking-important|d10_check|wiring: pr-to-milestone-review / Gate env BLOCKING" \
        "rename-post-id|d10_check|wiring: pr-to-milestone-review / Gate env VERDICT" \
        "exec-session|d10_check|wiring: pr-to-milestone-review / Post review env EXECUTION_FILE"; do
        IFS='|' read -r mode check want <<< "$row"
        copy="$dir/$mode.yml"
        if ! mutate "$mode" "$WF" "$copy"; then
            fail "D13 mutation $mode" "the mutation did not apply to the workflow"
            continue
        fi
        out="$("$check" "$copy" 2>&1)"
        rc=$?
        if [ "$rc" -eq 0 ]; then
            fail "D13 mutation $mode is caught by $check" "the check passed on the mutated copy"
        elif ! printf '%s' "$out" | grep -qF -- "$want"; then
            fail "D13 mutation $mode is caught by $check" "failed for the wrong reason: $out"
        else
            pass "D13 mutation $mode is caught by $check"
        fi
    done
}

# D18 on the real workflow, then on a mutant that keeps the delimiters. The
# mutant must fail, or the check proves nothing.
d18_main() {
    local job mutant="$TMPROOT/mutants/keep-delims.yml"
    for job in $JOBS; do
        d18_delims "$WF" "$job"
        finish "D18 [$job] no <!-- or --> from model text above the verdict line"
    done
    mkdir -p "$TMPROOT/mutants"
    if ! mutate keep-delims "$WF" "$mutant"; then
        fail "D18 mutation keep-delims" "the mutation did not apply to the workflow"
        return
    fi
    d18_delims "$mutant" pr-to-milestone-review
    if [ "${#PROBS[@]}" -gt 0 ] && printf '%s\n' "${PROBS[@]}" | grep -q 'delimiter is above the verdict line'; then
        pass "D18 mutation keep-delims is caught"
    else
        fail "D18 mutation keep-delims is caught" "problems: ${PROBS[*]:-none}"
    fi
    PROBS=()
}

# ---------------------------------------------------------------------------
# Run
# ---------------------------------------------------------------------------
# A missing PyYAML must fail the test, not skip it.
if ! wf_json_schema "$WF" pr-to-milestone-review > "$TMPROOT/probe.out" 2> "$TMPROOT/probe.err"; then
    if grep -q 'PyYAML' "$TMPROOT/probe.err"; then
        fail "PyYAML is available" "$(cat "$TMPROOT/probe.err")"
        harness_report
    fi
fi

for job in $JOBS; do
    d1_render "$job"
    d2_verdict "$job"
    d3_forged "$job"
    d4_verbatim "$job"
    d5_post_failure "$job"
    d6_invalid "$job"
    d7_failure_note "$job"
    d8_gate "$job"
    d9_guard "$job"
    d11_render_each_severity "$job"
    d14_blank_summary "$job"
    d15_post_failed_gate "$job"
    d16_diagnostic "$job"
    d17_line_breaks "$job"
done

d12_removals

d10_d11_main
d13_mutations
d18_main

harness_report
