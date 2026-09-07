---
description: Execute an approved plan file from /start-issue — implementation, tests, and reviews
model: claude-opus-5
---

# Execute Plan

Keep output brief — terse status lines, no preamble, no restating of steps.

## Hard Constraints (non-negotiable)

> **1. THE PLAN FILE MUST BE APPROVED before this command implements anything.**
> If the plan file's status line is not `Approved`, stop and tell the user to
> run `/start-issue` to finish planning and approval first.
>
> **2. NEVER commit, push, or create PRs.**
> After implementation is complete, stop. The user commits explicitly via
> `/commit` or `/commit-push-pr`. Do not run `git add`, `git commit`, or
> `git push` under any circumstances during this workflow.

---

This command implements an approved plan file written by `/start-issue`. It
re-verifies the plan's checklist against actual repo state before doing any
work, so it is safe to run on a fresh branch, resume a partially-completed
plan, or be run by a second agent/session picking up after an interruption —
in every case it establishes ground truth from the files themselves, not from
assumptions about what "should" have happened.

**Find issues at the earliest, least expensive stage.** A bug found here —
implementation time, one file open, full context loaded — costs one fix. The
same bug found at `/review-pr` costs a fix plus a re-verify round-trip; found
after merge it costs a follow-up issue. Step 6 below specifically calls out
two risk classes (new SQL, structured-input validation) that reading code
carefully does not reliably catch — only running the code does — so treat
"looks correct on inspection" as provisional for those two classes, and
execute before checking the item off.

## Arguments

- `$ARGUMENTS` — (optional) an issue number or a path to a plan file. If
  omitted, auto-detect from the current branch name (e.g.,
  `issue/423-car-data-export` → `docs/plans/issue-423-car-data-export.md`),
  the same inference `/finish-issue` uses.

## Step 0: Initialize TaskList

Create tasks: locate + validate plan file, re-verify checklist against repo
state, execute remaining items (fanned out per plan annotations), run
test/PHPStan-baseline-hygiene/security/architect review steps from the plan,
update checklist + release notes, final hand-off summary. Set each
`in_progress`/`completed` as
you progress.

## Workflow

### Step 1: Locate the Plan File

If `$ARGUMENTS` is a path ending in `.md`, use it directly. If it's an issue
number, look for `docs/plans/issue-<NUMBER>-*.md`. If omitted, extract the
issue number from the current branch (`git branch --show-current`) the same
way `/finish-issue` does, then locate the matching plan file.

If no matching file exists, stop and tell the user: "No plan file found for
this issue. Run `/start-issue <NUMBER>` first."

If multiple files match (e.g. a stale plan from an earlier, differently-named
attempt), list them and ask the user which to use.

### Step 2: Validate Approval Status

Read the plan file's `**Status:**` line.

- **`Approved — ready for /execute-plan`**: proceed to Step 3.
- **`Draft — pending approval`**: stop. Tell the user: "This plan hasn't been
  approved yet. Return to `/start-issue` to finish the approval step before
  running `/execute-plan`."
- **Anything else** (e.g. already marked complete, or an unrecognized value):
  stop and show the user the actual status line, ask how they want to
  proceed — do not guess.

### Step 3: Re-Verify Checklist Against Repo State

This is the step that makes the plan file trustworthy to any agent or session
that picks it up — never assume the checklist's `[ ]`/`[x]` marks reflect
reality; confirm each one against the actual repository.

For each checklist item:

- **File-creation/modification items** ("Add X to file.php"): check whether
  the described change is actually present in the file (grep for the
  function/method/class name, or read the relevant section).
- **Test items** ("Add PHPUnit test for X"): check whether the test file and
  the specific test method exist.
- **Review items** ("Run security-review", "Run senior-architect review"):
  these cannot be verified by file inspection alone — treat as done only if
  the plan file's checklist already shows `[x]` AND a later step in this same
  workflow run didn't just invalidate that review by changing the reviewed
  files. If uncertain, re-run the review rather than trust a stale checkmark.

Produce a status report: which items are genuinely done (matches both the
checkbox and the actual repo state), which are marked done but aren't
actually present (repo state contradicts the checkbox — flag this explicitly,
it means a previous run's edit was reverted, lost, or never actually
committed to disk), and which are genuinely not started.

**If any item is marked `[x]` but repo state contradicts it**, use
AskUserQuestion before proceeding — do not decide this yourself and do not
silently re-do or silently trust the checkbox:

- Question: "Plan says '`<item>`' is done, but I don't see it in `<file>`.
  How should I proceed?"
- Options: `Treat as not done, redo it` (recommended), `It's actually done —
  update the checkbox, don't redo`, `Let me look myself first`

This discrepancy is exactly the kind of drift the re-verification step
exists to catch, so it always goes to the user — never resolved
automatically, since only they can know whether the missing evidence means
lost work or a false-positive check in the earlier run.

Update the plan file's checkboxes to match verified reality before continuing.

### Step 4: Determine Fan-Out from Plan Annotations

Group the remaining (`[ ]`) checklist items by their annotations:

- Items marked `(parallel-safe)` with no unresolved `(depends on: ...)` can
  run concurrently — launch one `software-developer` agent per independent
  item or tightly-coupled group (same grouping logic as the old `/start-issue`
  Step 10: one agent per independent file or group of related files).
- Items marked `(depends on: <other item>)` wait until that item is verified
  complete (Step 3) or completed earlier in this same run.
- If the plan gives no annotation for an item (older plan file, or an
  oversight), treat it conservatively as **not** parallel-safe — run it
  sequentially rather than guess.

### Step 5: Implement

Launch `software-developer` agents per Step 4's grouping. Provide each agent:

- The specific checklist item(s) it owns, verbatim from the plan file
- The plan's Architecture & Design section for context
- Any Database & Security Considerations relevant to its files

**Model override by tier** (infer tier from the plan file's scope — number of
checklist items and files touched, same Small/Medium/Large bands
`/start-issue` used): pass `model: "sonnet"` for Small-tier plans. Omit
`model` for Medium/Large (agent default is Opus).

As each agent completes, mark its checklist item(s) `[x]` in the plan file
immediately — do not batch updates to the end. This is what lets a second
agent or a later session trust the file's state without re-running Step 3
from scratch.

### Step 6: Test, Security, Documentation

Launch in parallel, only the agents relevant to what changed (per the plan's
Test Plan / Documentation Plan sections):

- **senior-test-engineer**: write and run tests from the plan's Test Plan.
  Separate instances for PHPUnit vs Playwright if both apply.
- **technical-documentation-writer**: update docs per the plan's Documentation
  Plan.

Run quality checks: relevant test suites, and note that pre-commit hooks will
run PHPStan/phpcs on staged files at commit time regardless.

**Execute, don't just read, for two specific risk classes.** Two real bugs
reached `/review-pr` in past issues that a careful code-review pass missed
entirely, because nothing actually *ran* the risky code path — the plan and
the implementation both read as correct, and only execution surfaced the
defect. Both are cheap to catch here instead of two workflow stages later:

- **New or changed SQL**: if this plan added or modified a query, run it
  against a real local database (even one with zero matching rows is enough
  to prove the SQL itself is syntactically and semantically valid under the
  project's actual `sql_mode`/column types) before marking the item done.
  A query that "looks correct" on inspection can still be a guaranteed fatal
  error against the real schema — e.g. comparing a `DATE` column to `''`
  under `STRICT_TRANS_TABLES` throws, but no static read of the SQL reveals
  that; only executing it does. Include the exact command/output as evidence
  when checking off the item, not just "looks right."
- **Any new public method that accepts structured input** (an array, a DB
  row, JSON-decoded data) from a caller who might build that input
  dynamically rather than as a literal: instruct the senior-test-engineer
  to include, alongside the tests the plan's Test Plan already calls for,
  at least one test per structured parameter that passes a **malformed or
  wrong-typed** value (not just a missing key) and asserts the method's own
  documented failure mode — its own `@throws` type — not merely "no crash."
  PHPStan's array-shape checking only catches violations at call sites it
  can see statically; it cannot catch a shape violation in data assembled at
  runtime, which is exactly the gap a caller loop or DB row can fall into.
  Concretely: a method typed to accept `array{label: string, url: string}`
  needs a test passing `['label' => 123, 'url' => '...']`, not just a test
  omitting `label` entirely — a present-but-wrong-typed value can reach a
  strictly-typed private helper uncaught and throw a generic `\TypeError`
  instead of the method's documented exception, and only a test that
  supplies the wrong type (not just the absent key) will catch it.

Mark the corresponding checklist items `[x]` as each completes.

### Step 6.5: PHPStan Baseline Hygiene

Per CLAUDE.md's fix-when-you-touch-it policy (see CODING_STANDARDS.md —
PHPStan Baseline Hygiene): any project-owned PHP file this plan touched must
not carry `phpstan-baseline.neon` entries — reported errors on touched files
must be fixed, not grandfathered. This is the same check `/finish-issue`
Step 4.5 runs, moved earlier so it's caught right after implementation
instead of at merge time, while the context of what changed is still fresh.

**Why a plain `vendor/bin/phpstan analyse <file>` run does not catch this:**
`phpstan.neon` includes `phpstan-baseline.neon`, so a normal run — scoped to
one file or the whole project — silently suppresses every pre-existing
baseline entry for that file. It only ever reports *new* errors. Checking
the baseline file directly is the only way to see whether an already-touched
file still carries old debt:

```bash
CHANGED_FILES=$(git diff --name-only $(git merge-base HEAD origin/<milestone-branch>)..HEAD)

for f in $CHANGED_FILES; do
  case "$f" in
    *.php)
      if grep -qF "path: $f" phpstan-baseline.neon 2>/dev/null; then
        echo "BASELINE OVERRIDE: $f"
      fi
      ;;
  esac
done
```

(If the branch has no commits yet — e.g. this step runs before `/commit` —
use `git diff --name-only` with no ref, or `git status --short`, to get the
working-tree changed-file list instead.)

**If any file appears:** read the matching baseline entries
(`grep -B3 -A8 "path: <file>" phpstan-baseline.neon`) to see the exact
errors. Then:

- **If the flagged lines were touched by this plan's work:** fix them now —
  this is exactly the debt fix-when-you-touch-it exists to catch.
- **If the flagged lines are elsewhere in the file, untouched by this
  plan:** use AskUserQuestion rather than deciding unilaterally — do not
  silently carry the debt forward and do not silently fix unrelated code
  without confirming scope:
  - Question: "`<file>` has N pre-existing PHPStan baseline entries on lines
    this plan didn't touch. How should I proceed?"
  - Options: `Carry over, not touched by this plan` (recommended — avoids
    scope creep into unrelated debt), `Fix them now anyway` (if the file is
    already open and the fix is small)

After any fix, regenerate the baseline to drop resolved entries:

```bash
composer phpstan:baseline
```

Re-run the affected test suite and PHPStan on the file to confirm clean,
then re-check the `CHANGED_FILES` loop above returns nothing for it.

**If no changed file appears in the baseline:** proceed to Step 7 with
nothing to do here.

### Step 7: The single review round — all reviewers, in parallel, before the push

**Launch every applicable reviewer at once, against the same commit.** Not in
sequence, and not spread across the push:

- **senior-architect** — the complete diff: architecture fit, code quality,
  standards adherence, documentation completeness. Defaults to Opus; pass
  `model: "sonnet"` for Small/Medium-tier plans.
- **security-reviewer** — if the plan's Database & Security Considerations
  section is non-empty, or any changed file touches forms, SQL, or auth.
- **code-reviewer** — CLAUDE.md and CODING_STANDARDS.md conformance.
- **pr-test-analyzer** — coverage of the plan's acceptance criteria.
- **silent-failure-hunter** — if the diff adds or changes any catch block,
  fallback, or error path, **or** adds a new public method with
  structured-input parameters (array/DB row/JSON) or new/modified SQL. That
  second trigger is the class of issue architect review (design/security/
  coverage-focused) has missed here before: a wrong-typed value reaching a
  strictly-typed private helper as an uncaught `\TypeError` instead of the
  method's documented exception. Skip when neither condition applies — it is
  not a blanket addition to every plan.

These are the same agents `/review-pr` runs. They run **here**, before the
push — not after it.

**Why parallel and why before the push.** Serial reviewers each see a
different artifact, which guarantees that round N+1 finds something round N
never looked at. Sampled issue PRs #1838, #1841, #1845 and #1860 were each
implemented once and reviewed two to four times, every round producing its own
commit — and in two of the four, a round existed only to repair a defect the
*previous* round's fix introduced. The fix commit is always the least
scrutinised code in the PR.

**Triage every finding once, into three buckets:**

| Bucket | Test | Action |
| --- | --- | --- |
| **Blocking** | Verified, reproducible, and in this diff | Fix now, this PR |
| **Advisory** | Real, but not this issue's job | New issue via `/found` |
| **Note** | Wording, style, docs nuance | Fix only if already on that line |

Fix all Blocking findings in **one** commit, then re-check **only that
commit's diff**, with only the reviewers whose findings it addressed. That is
round two, it is cheap, and it is exactly where the two self-inflicted
regressions above would have been caught.

**The two-round ceiling.** If a third round is needed, stop. Three rounds of
fixes on one issue means the plan was wrong, not that the reviewers are
thorough — use AskUserQuestion to re-gate the plan rather than continuing to
patch:

- Question: "This is the third review round on #NNN. What's the call?"
- Options: `Re-scope the plan` (recommended — the remaining findings suggest
  the approach, not the code, is the problem), `Split the rest into a
  follow-up issue`, `Keep fixing in this PR` (say why)

Mark the corresponding checklist items `[x]` once the round is clean.

### Step 8: Confirm Plan Completeness

Before moving to hand-off, re-scan the plan file: every checklist item should
now be `[x]`. If any remain `[ ]`, use AskUserQuestion rather than deciding
yourself — even when it looks like the item turned out to be unnecessary:

- Question: "'`<item>`' is still unchecked. How should I handle it?"
- Options: `Mark N/A with a reason` (only offer this when you can state why
  it turned out unnecessary), `Do the work now`, `Let me look myself first`

Do not silently leave unchecked items with no explanation, and do not
silently mark something N/A on your own judgment — that defeats the purpose
of a plan a later step can trust.

Update the plan file's status line to `**Status:** Implemented — pending
commit/PR`.

### Step 9: Update Draft Release Notes

Same as the former `/start-issue` Step 10's release-notes update, adapted for
the fact that this command runs on the **issue branch**, which has no
version string in its own name — unlike `/start-issue`, which determines the
milestone branch directly (its own Step 3) before ever branching off it.

Get the milestone version from the plan file's `**Milestone:**` field first:

```bash
grep -oP '(?<=\*\*Milestone:\*\* `)[^`]+' docs/plans/issue-<NUMBER>-<slug>.md
```

**If that field is missing** (an older plan file predating this field, or one
edited by hand), fall back to resolving it live:

```bash
git branch --list 'milestone/*'
```

If exactly one exists, use it. If zero or multiple exist, stop and ask the
user which milestone branch this issue belongs to — do not guess.

Once resolved, update `docs/releases/RELEASE_NOTES_<version>.md` (create
from `docs/development/RELEASE_NOTES_TEMPLATE.md` if it doesn't exist yet).
Add this issue's changes to the appropriate section, keep it cumulative, use
the `technical-documentation-writer` agent for non-trivial entries.

### Step 10: Hand Off

**Do NOT commit, push, or create PRs.** State plainly that implementation is
complete and the plan file at `docs/plans/issue-<NUMBER>-<slug>.md` shows
every item verified complete. Then use AskUserQuestion instead of a
plain-text menu — and only ever offer the actual next runnable step, not the
full remaining sequence at once:

- Question: "Implementation complete. What next?"
- Options: `/simplify` (recommended — clean up the code before committing),
  `/commit` (skip straight to committing), `Compact context first`
  (recommended before a long next step — the plan file already has every
  item verified complete, so compacting here is safe and won't lose it),
  `Ask more questions / discuss first`
- If the user picks a command, invoke it immediately via the Skill tool
  rather than telling them to type it.
- If the user picks `Compact context first`, tell them to run `/compact`
  themselves — it's a client-level operation, not something this command can
  trigger via a tool.

The full remaining sequence, each step handed off the same way once the
prior one completes — do not present this whole list to the user at once,
re-offer one step at a time as each becomes the actual next action:

1. `/simplify` (optional)
2. `/commit`
3. `/review-pr` — **must run after `/commit`, not before.** It diffs
   committed history (`merge-base..HEAD`) against the milestone branch, per
   its own Step 2 — running it on uncommitted changes reviews nothing
   real. After `/commit` completes, offer `/review-pr` as the next step,
   not `/commit-push-pr` directly.
4. `/commit-push-pr` (only once `/review-pr` reports clean, or the user
   explicitly accepts its recommendations as-is)
5. `/address-pr-comments` (after CI runs on the pushed PR)
6. `/finish-issue` (once `/address-pr-comments` reports clean)

**For bug-fix plans** (plan file has a Bug Escape Analysis section), remind
the user to include the escape analysis in the PR description.

**Delete the plan file** once the user confirms the PR is merged and the
issue is closed (typically during/after `/finish-issue`) — its job (a
verifiable record other agents/sessions could check against) is done once
the code is merged; the merged diff and closed issue are then the source of
truth, same lifecycle as milestone sprint plans. `docs/plans/` is gitignored,
so that deletion is a plain `rm` with no git operation. Do not delete it from
within this command — that happens later, at merge time, not here.

## Available Agents

| Agent | `subagent_type` | Model | Use When |
| --- | --- | --- | --- |
| Software Developer | `software-developer` | `sonnet` (Small), `opus` (Medium/Large) | **Primary coding agent** |
| Senior Architect | `senior-architect` | `opus` | Post-implementation review |
| Senior Test Engineer | `senior-test-engineer` | `sonnet` | Writing/running tests from the plan |
| Technical Documentation Writer | `technical-documentation-writer` | `haiku` | Docs updates from the plan |
| Security Reviewer | `security-reviewer` | (per agent default) | `/security-review` |
| Silent Failure Hunter | `silent-failure-hunter` | (per agent default) | Step 7, only when a new structured-input method or new/modified SQL was added |

## Critical Rules

- **NEVER implement against an unapproved plan** — Step 2 is a hard gate.
- **NEVER commit, push, or create PRs** — hand off at Step 10, always.
- **Re-verify before trusting the checklist** — Step 3 runs every time this
  command starts, even on a plan that looks fully checked-off already. A
  stale or falsely-checked item is worse than a slow verification pass.
- **Update the plan file incrementally, not in one batch at the end** — mark
  each item `[x]` as its agent completes, so the file is always an accurate
  snapshot if this command is interrupted or resumed by another session.
- **Respect the plan's parallel-safety annotations** — don't parallelize an
  item the plan marked as dependent, and don't invent parallelism for
  unannotated items.
- **Surface discrepancies, never silently resolve them** — a checked item
  that isn't actually in the repo, or unchecked items with no remaining work,
  both get raised to the user via AskUserQuestion (Steps 3 and 8), never
  decided unilaterally.
- **Never assume — verify via code or ask.** Objective facts about the repo
  (does the file exist, does the test pass, is this function already
  implemented) get checked directly — grep/read/run it, don't guess and
  don't ask the user something you can verify yourself. Judgment calls
  (resolve this discrepancy which way, is this item really unnecessary) go
  to AskUserQuestion. Never present a checklist item as done, or a plan as
  complete, without having done one of the two.
- **Use AskUserQuestion for every discrepancy, completeness gap, and
  hand-off choice** (Steps 3, 8, 10) — not free-form chat questions.
- **Follow project conventions** from CLAUDE.md and CODING_STANDARDS.md.
- **Execute risky code paths, don't just read them** — new/changed SQL against
  a real local DB, and wrong-typed (not just missing) structured-input tests
  for new public methods. Both classes have shipped real, review-missed bugs
  from careful-looking code that only broke at execution time. Catch them in
  Step 6, not two workflow stages later at `/review-pr`.
