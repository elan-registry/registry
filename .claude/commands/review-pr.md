---
description: Full-branch PR review that matches CI scope — diff + complete file content, with user confirmation on recommendations
model: claude-opus-5
argument-hint: "[aspects: code|errors|comments|tests|simplify|all]"
---

# PR Review (Full Branch)

Think hard when verifying findings and judging false positives — a wrong
triage call either ships a bug or burns a CI round-trip.

Keep output brief — terse status lines, no preamble, no restating of steps.

Run a comprehensive review against the **full accumulated branch diff** — the
same view the CI `pr-to-milestone-review` check uses. This catches cross-commit
issues (dead code, broken call interactions, unreachable paths) that per-file or
working-tree-only reviews miss.

Use this instead of `/pr-review-toolkit:review-pr` before pushing or creating a PR.

**Review aspects (optional):** `$ARGUMENTS`  
Available: `code` | `errors` | `comments` | `tests` | `simplify` | `all` (default)

---

## Step 1: Run the verification suite

Run this **first**, before launching any agent — a failing suite short-circuits
the review before spending agent tokens on a branch that is already broken.

```bash
composer test:full          # unit + ALL integration (~70s)
composer check:docs         # under a second
vendor/bin/phpstan analyse --no-progress --memory-limit=512M   # ~1s cached
```

**A clean `phpstan analyse` run here does NOT mean no baseline debt on
touched files.** `phpstan.neon` includes `phpstan-baseline.neon`, so this
run silently suppresses every pre-existing baseline entry — it only ever
reports *new* errors. Any file this branch modified that still carries old
baseline entries needs the same explicit check `/finish-issue` Step 4.5 and
`/execute-plan` Step 6.5 run:

```bash
for f in $(git diff --name-only $MERGE_BASE..HEAD); do
  case "$f" in
    *.php)
      grep -qF "path: $f" phpstan-baseline.neon 2>/dev/null && echo "BASELINE OVERRIDE: $f"
      ;;
  esac
done
```

If this branch went through `/execute-plan`, its Step 6.5 should have
already caught and resolved this — treat any hit here as that step being
skipped or a change made outside the plan-file workflow, and handle it the
same way: fix if the flagged lines were touched, or confirm with the user
before carrying pre-existing debt forward.

`test:full` runs unconditionally. There is no path-based escalation and no
opt-in: `tests/integration/` — real-database behavior (triggers, audit-trail
writes, migrations, backups, geocoding, admin endpoints) — is run by no other
automated step, not the pre-commit hook and not CI. If this command does not
run it, nothing does.

### Do not trust the exit code — parse the summary line

Two separate mechanisms make a green exit code meaningless here, both verified
against this repo:

1. **An unreachable database exits 0 with no tests run at all.** UserSpice's
   connection failure calls an uncatchable `die()` in `users/classes/DB.php`
   (gitignored upstream, so grep for it rather than trusting a line number)
   during bootstrap, so the process ends before PHPUnit prints any summary.
   Observed output is two lines — `NOTE: Loaded test environment from .env.test.local`
   and `Could not connect to database.  Please check your configuration.` —
   and `$?` is **0**. Not one test executed, and the exit code says success.
2. **Individually skipped tests also exit 0.**
   `IntegrationTestCase::requireDatabase()` calls `markTestSkipped()`, and
   neither `phpunit-unit.xml` nor `phpunit-integration.xml` sets
   `failOnSkipped`, `failOnWarning`, or `failOnRisky`.

A green exit code therefore does not mean the suite ran. Counting summary
lines is the only reliable check.

Judge the run on its parsed summary line instead. The line is ANSI-colored, so
strip escapes before matching:

```bash
composer test:full 2>&1 | sed 's/\x1b\[[0-9;]*m//g' | grep -E '^(OK|OK, but|FAILURES|ERRORS|WARNINGS|Tests:)'
```

`test:full` is two PHPUnit invocations, so it prints **two** summary lines —
one per suite. There is no combined total; expect output like:

```text
OK (N tests, M assertions)     <- unit
OK (N tests, M assertions)     <- integration
```

Both suites contain test files, so a healthy run reports two `OK` lines each
with a **non-zero** test count. Treat that as a property to re-check, not an
axiom — nothing in the tooling enforces it. Anything else is Blocking:

| Summary | Verdict |
| --- | --- |
| Two `OK (N tests, M assertions)` lines, both `N > 0` | Pass — record both counts |
| Either `OK` line with `N` of 0 | **Blocking** for the whole run, not just that suite — it reported success having run nothing |
| `No tests executed!` | **Blocking** — exits 0 if the suite is empty, 1 if a filter matched nothing; either way nothing ran |
| Only one `OK` line | **Blocking** — a suite died before reporting |
| No summary line at all | **Blocking** — bootstrap `die()`d before PHPUnit reported |
| `OK, but there were issues!` | **Blocking** |
| Any `Skipped:`, `Incomplete:`, `Risky:`, or `Warnings:` count | **Blocking** |
| `FAILURES!` / `ERRORS!` / non-zero exit | **Blocking** |

Unexpected skips and warnings are treated exactly as errors are. A skipped
suite reported as a pass is the specific failure this step exists to prevent.

Counting the `OK` lines is what catches an integration suite that never ran:
if the DB is unreachable the unit line still prints `OK`, and reading only the
first line would look identical to a clean run. Checking that each `N` is
non-zero is what catches the other shape of the same problem — a suite that
reported success having executed nothing.

### A suite that cannot start is a Blocking finding, not an excuse

`tests/bootstrap-integration.php` has a number of preconditions that abort with
`exit(1)` and an actionable message. Some examples, not a complete list — a
missing framework, a missing or unparseable `.env.test.local`, a connection
that turns out to be the dev database, missing reference data (via
`abortBootstrap()` / `abortMissingSeed()`). All are self-announcing: treat any
such abort as Blocking, whether or not it appears above.

The dangerous case is the one that is not: an unreachable or nonexistent
schema produces `Could not connect to database.` and **exits 0**. Only the
missing summary line reveals it.

Report whichever message appeared **verbatim** as Blocking. Do not reinterpret
it as an environment gap and do not proceed. "The DB wasn't up" is a reason
the review could not be completed, not a reason to call it clean.

---

## Step 2: Build the full branch diff

Find the milestone base branch (or fall back to `main`):

```bash
# If a PR exists, use its base branch
BASE=$(gh pr list --head "$(git branch --show-current)" --state open \
  --json baseRefName --jq '.[0].baseRefName // empty' \
  --repo elan-registry/registry 2>/dev/null)

# Fall back to the single milestone/* branch if no PR yet
if [ -z "$BASE" ]; then
  BASE=$(git branch --list 'milestone/*' | head -1 | tr -d ' *')
fi

# Last resort
BASE=${BASE:-main}

MERGE_BASE=$(git merge-base HEAD origin/$BASE 2>/dev/null || git merge-base HEAD $BASE)
git diff $MERGE_BASE..HEAD
```

Also get the list of changed files:

```bash
git diff --name-only $MERGE_BASE..HEAD
```

Read the **full content** of every changed file (not just the diff lines). Both
inputs together give the same view as the CI reviewer: what changed and what the
file looks like now in its entirety.

---

## Step 3: Determine applicable review agents

Based on `$ARGUMENTS` (default: all applicable):

| Aspect     | Agent                                                                    | When to run                                        |
|------------|--------------------------------------------------------------------------|----------------------------------------------------|
| `code`     | `pr-review-toolkit:code-reviewer`                                        | Always                                             |
| `errors`   | `pr-review-toolkit:silent-failure-hunter`                                | If catch blocks, fallbacks, or error paths changed |
| `comments` | `pr-review-toolkit:comment-analyzer` + independent fact-check (Step 4.5) | If PHPDoc, inline comments, or docstrings changed  |
| `tests`    | `pr-review-toolkit:pr-test-analyzer`                                     | If test files changed or new features added        |
| `simplify` | `pr-review-toolkit:code-simplifier`                                      | After all other agents pass; final polish only     |

If `$ARGUMENTS` is empty or `all`, run all applicable agents based on the changed
file types (skip test analyzer if no test files changed; skip comment analyzer if
no comments/docs added).

The `tests` agent *reads* test files and reasons about coverage; Step 1 is what
*executes* them. Neither substitutes for the other — a clean test-analyzer
report says nothing about whether the suite passes. Step 1 runs regardless of
which aspects `$ARGUMENTS` selects.

---

## Step 4: Launch review agents

Provide **each agent** with:

1. **The full branch diff** (from Step 2)
2. **The full content of every changed file** (read each file in full)
3. **This instruction appended to the agent prompt**:

> "Review this as the complete accumulated set of changes on this branch — not
> just the latest commit. Look specifically for cross-commit issues: functions
> that are defined but no longer called, fallback values that can never be
> reached, CSRF or token interactions that break when multiple edits are combined,
> and anything that looks correct in a per-file diff but is broken when viewed as
> a whole. The project is Elan Registry (PHP 8.2 / UserSpice 6). Apply
> CLAUDE.md standards and docs/development/CODING_STANDARDS.md.
>
> For any query, regex, or logic whose correctness depends on a specific
> engine/library behavior — a SQL function's exact semantics, a regex
> character class's exact coverage, a framework default — do not reason
> about it from memory or from how the surrounding code describes it.
> Verify it directly: run the query against the actual project database
> (connection details are in `.env`; this is a local dev DB, safe to query),
> or write a small isolated test of the specific claim. A query that
> 'looks correct' because its logic reads sensibly is not the same as one
> that has been shown to match its intended character/value set — the two
> diverge exactly when a function's real behavior differs from its common-sense
> reading (e.g. a locale- or engine-specific character class matching more
> or less than expected). If you cannot verify a claim this way, say so
> explicitly rather than passing the code as correct on inspection alone."

Run all applicable agents **in parallel** for speed. `simplify` always runs last,
after other agents complete.

---

## Step 4.5: Independent fact-check of comments (if `comments` applies)

`comment-analyzer` reviews comment *quality* (clarity, redundancy, rot risk) —
it does not independently verify that a comment's factual claims are true.
Its context is the same conversation and diff everyone else is looking at, so
an inaccurate claim that sounds right — because it echoes something decided
mid-implementation, not because it matches the actual running code — can
read as correct to every reviewer who already believes it.

When any comment changed or was added in this branch's diff makes a factual
claim about the codebase — endpoint contracts, response shapes, "the only
path that does X," field names, framework behavior (e.g. "this element is
hidden by default"), required values — verify it with a **fresh agent that
has no prior context on this branch, this conversation, or this PR**. Launch
via the `Agent` tool with `subagent_type: "general-purpose"` (not `fork` —
a fork inherits this conversation, which is exactly what must be avoided
here) and a prompt that:

- Names the file(s) and the specific comments to audit, quoted verbatim
- Instructs it to treat every factual claim in those comments as **unverified
  and to be falsified**, not as documentation to trust
- Requires it to re-derive each claim from source: grep the repo for
  competing/alternative code paths the comment claims don't exist, read the
  actual endpoint/function referenced, query a live DB directly if the claim
  is about data (e.g. "this value exists in table X"), and check framework
  defaults (e.g. CSS class behavior) against the actual markup/library, not
  the comment's description of it
- Asks for an explicit verdict per claim: VERIFIED (with the file:line or
  query result that proves it) or CONTRADICTED/UNVERIFIABLE (with what was
  found instead)

This step exists because the same tool call that produces a plausible-sounding
comment can also produce a plausible-sounding review of it — both draw on the
same (possibly wrong) belief formed during implementation. A fresh agent with
no memory of *how* the code came to look this way has no such belief to
confirm; it only has the repo as it exists right now.

Fold any CONTRADICTED/UNVERIFIABLE finding into Step 5's Blocking table.
VERIFIED findings need no further action — do not report them as if they were
new information, since they simply confirm what the diff already claimed.

---

## Step 5: Aggregate and triage findings

Collect all agent findings and categorize them:

| Tier               | Label                | Definition                                                        |
|--------------------|----------------------|-------------------------------------------------------------------|
| **Blocking**       | Must fix before push | Security issue, definite bug, broken logic, standards violation   |
| **Recommendation** | Decide before push   | Style suggestion, dead code, minor improvement, optional refactor |
| **Informational**  | No action needed     | Confirmed-good patterns, context notes                            |

Output a triage table:

```text
## Local Review — Branch: <branch-name>
## Diff scope: <merge-base>..<HEAD> (<N> commits, <M> files)

### Suites executed
| Suite | Command | Result |
|-------|---------|--------|
| Unit | composer test:full | OK (N tests, M assertions) |
| Integration | composer test:full | OK (N tests, M assertions) |
| Docs | composer check:docs | Documentation checks passed. |
| Static analysis | vendor/bin/phpstan analyse | No errors |
| Baseline hygiene | grep touched files vs phpstan-baseline.neon | Clean / N pre-existing entries found (see Blocking) |

State actual counts, never "passed" alone. If a suite did not run, say so
here and why — this table is how the reviewer tells what was and was not
executed.

### Blocking (must fix)
| Agent | File:Line | Issue |
|-------|-----------|-------|

### Recommendations (your call)
| Agent | File:Line | Suggestion |
|-------|-----------|------------|

### Informational
- ...
```

---

## Step 6: Handle findings

**If there are Blocking items:**

- Fix each one (launch `software-developer` agent per file for non-trivial fixes,
  or edit directly for simple ones)
- After fixing, re-run the `pr-review-toolkit:code-reviewer` agent on the full
  branch diff + changed files to confirm clean
- Do NOT proceed until blocking items are resolved

**If there are Recommendation items:**

- Present them to the user with a one-line summary each
- Ask: "Here are recommendations from the review. Which (if any) would you like
  to address before pushing?"
- Wait for the user's response before continuing
- For each item the user wants to address: fix it, then re-run the code-reviewer
  to confirm

**If the review is clean:**

This branch is only reachable when Step 1 produced a clean summary line for
every suite — no failures, and no unexpected skips, warnings, incomplete, or
risky tests. Never report "clean" over a suite that did not run, could not
start, or skipped.

- Report: "Local review clean — no blocking issues, no open recommendations."
  Include the Suites executed table so the claim is backed by real counts.
- Proceed to `/commit-push-pr` or `/commit`. Compacting context first is also
  reasonable before that step — the review is already recorded in this
  report, so nothing is lost. `/compact` is a client-level operation the user
  runs themselves, not something this command can trigger via a tool.

---

## Notes

- This command reviews **committed local changes** vs the milestone branch.
  Run it after committing your work but before pushing (`/commit` then `/review-pr`).
- The `simplify` aspect runs only after all other aspects pass — don't use it
  to mask unfixed issues.
- To review only specific aspects: `/review-pr code errors`
- **The `comments` aspect's fact-check (Step 4.5) must run as a genuinely
  fresh agent, not a fork.** A fork inherits this conversation's context —
  including whatever belief produced the comment in the first place — which
  defeats the point. Only an agent with no memory of this session can
  meaningfully falsify a claim instead of recognizing and confirming it.
- **A green PHPUnit exit code does not mean the suite ran.** An unreachable
  database exits 0 having run zero tests (UserSpice `die()`s in bootstrap
  before PHPUnit reports), and skips, warnings, incomplete, and risky tests
  all exit 0 under the current configs. Step 1 counts summary lines for this
  reason; keep it that way if the step is ever refactored.
- Step 1 is the only automated step anywhere that runs `tests/integration/`.
  The pre-commit hook (`.githooks/pre-commit`) runs
  `vendor/bin/phpunit --testsuite=Unit --exclude-group known-broken` and a
  full-project PHPStan, but each only when the commit stages matching files —
  a docs-only commit runs neither. `.githooks/pre-push` runs no tests at all.
  CI's `tests.yml` runs `test:quick:ci` + `test:regression:ci` (unit only, no
  MySQL service). Whether CI should also run integration tests is a separate
  open question — it does not today.
- `$ARGUMENTS` selects which review *agents* run. It never skips Step 1.
