---
description: Review the test suite for low-value tests — classify each file, verify the findings, and file capture-only issues on one approval
model: opus
---

# Groom Tests

Keep output brief — terse status lines, no preamble, no restating of steps.

An on-demand review of the test suite. It finds tests that check no real
behavior, copy another test, or test an edge case with no real trigger. It
files the findings as capture-only issues. Then it stops.

This command is read-only for the repository. It never edits, deletes, or
runs a test. A fix is a normal issue that `/plan-milestone` gates.

Run it before `/groom-backlog`. `/groom-backlog` then weighs the issues
that this command files, with the rest of the backlog.

Issue #2327 (PR #2335) and the 2026-10-13 review (#2397–#2403) are the reference
runs. Read #2327 for the rules that a removal must follow.

## Arguments

- `$ARGUMENTS` — optional. One of `unit`, `integration`, `playwright`,
  `hooks`, or a path under `tests/`. Omit for the full suite (the default).

## Step 1: Measure the suite

Run from the repository root:

```bash
for d in tests/unit tests/integration tests/playwright tests/hooks; do
  printf '%s: %s files, %s lines\n' "$d" \
    "$(find "$d" -type f \( -name '*.php' -o -name '*.js' -o -name '*.sh' \) | wc -l)" \
    "$(find "$d" -type f \( -name '*.php' -o -name '*.js' -o -name '*.sh' \) -exec cat {} + | wc -l)"
done
grep -rhoE 'function test[A-Za-z0-9_]*|#\[Test\]' tests/unit tests/integration | wc -l
find app usersc/classes usersc/includes -name '*.php' -not -path '*/vendor/*' -exec cat {} + | wc -l
```

Record the test lines, the app lines, the ratio, and the PHPUnit method
count. Find the last run's numbers in the newest issue that this command
filed:

```bash
gh issue list -R elan-registry/registry --state all --search "test-suite review in:body" \
  --limit 5 --json number,title,createdAt --jq '.[] | "#\(.number) \(.createdAt[:10]) \(.title)"'
```

Show the change since the last run in one line.

## Step 2: Load the open test issues

Get the open issues that already own test work. Step 5 uses this list to
prevent duplicates:

```bash
gh issue list -R elan-registry/registry --state open --limit 200 \
  --search 'label:"component: testing" OR "source-slice" OR "test:" in:title' \
  --json number,title,milestone \
  --jq '.[] | "#\(.number) [\(.milestone.title // "no milestone")] \(.title)"'
```

Read the body of each issue that names test files. Record the files that
each issue owns.

## Step 3: Classify each file

Start read-only agents in parallel, in one message. Use one agent for each
slice. For a scoped run, start only the slices in scope:

1. `tests/unit/regression/`, `tests/unit/system/`, `tests/unit/phpstan/`,
   `tests/unit/*.php`, `tests/unit/helpers/`, `tests/unit/scripts/`
2. `tests/unit/cars/`, `tests/unit/cron/`, `tests/unit/admin/`,
   `tests/unit/views/`
3. All other `tests/unit/` subdirectories
4. `tests/integration/*.php` (the root files)
5. `tests/integration/` subdirectories
6. `tests/playwright/` (with the Playwright configs and the `package.json`
   scripts) and `tests/hooks/`

If a slice has more than about 80 files, split it into two agents. Keep the
total under 10 agents.

Give each agent this brief. Put the slice in place of SLICE, and the
output of `git rev-parse --show-toplevel` in place of REPO_ROOT:

> READ-ONLY. Do not edit, run, or commit anything. Repo: REPO_ROOT.
> Audit the test files in SLICE. For each file, count the test methods and the lines.
> Give the file one verdict:
>
> - KEEP — tests real behavior, or a real past bug that can occur again.
> - TRIM — useful, but has copies of one case (same shape for each field,
>   near-identical data rows, the same check at two layers).
> - CUT — checks no app behavior. Examples: reads source text and matches
>   a string; tests the test's own regex or fixture; tests PHP, MySQL, or
>   framework behavior; type or `instanceof` checks only; an exact copy of
>   another test; an edge case with no real trigger.
> - MERGE — overlaps strongly with a named file.
>
> Rules:
>
> - A security test (CSRF, XSS escaping, auth, SQL injection, upload
>   validation, path traversal) is KEEP unless another test covers the same
>   case. Name that test.
> - A source-text test that is the only check of a security gate is
>   REPLACE, not CUT. A behavior test must replace it first.
> - Playwright is not coverage. It runs in no git hook and no CI workflow.
> - Deploy and release safety scripts in `tests/hooks/` keep their depth.
> - For each CUT, name the test that covers the same behavior, or write
>   "no coverage needed" and the reason.
>
> Also report these patterns: tests that read source text; tests of the
> test itself; the same check copied for each field; tests in the wrong
> tier (for example a Playwright spec that sends only HTTP requests);
> migration tests for migrations that already deployed; tests bigger than
> the dev tool they test.
>
> Return: a table (file | lines | tests | verdict | reason) grouped by
> verdict, the patterns, the lines and tests removable, and the 3–5
> strongest examples with `file:line`.

## Step 4: Verify the findings

Agents skim. Check each CUT and the five strongest examples yourself before
you show them:

- Read the cited lines. Confirm that the test does what the agent says.
- For each CUT, confirm that the named covering test exists.
- Drop or downgrade a finding that fails the check. Say which ones.

Do not verify each TRIM row. Say that TRIM rows are estimates.

## Step 5: Group and deduplicate

Group the findings by remedy, not by directory. One issue per group:

| Group | Contents |
| --- | --- |
| Delete | CUT files and methods |
| Data providers | TRIM files with copied cases |
| Wrong tier | tests to move to another tier |
| Tooling | dev-tool and review-tool tests that are too large |
| Merge | MERGE file groups |
| Standards | a pattern that new tests keep adding (for `CODING_STANDARDS.md`) |

Remove every file that an open issue from Step 2 already owns. For each
removed file, plan an evidence comment on the owning issue, not a new
issue. Do not file an empty group.

## Step 6: Present and confirm once

Show one table before you change anything on GitHub:

```text
## Suite size
<test lines> test / <app lines> app = <ratio> (last run: <ratio>, <date>)

## New issues
| Title | Files | Est. lines / tests |
|-------|-------|--------------------|

## Comments on existing issues
| # | Title | Files found again |
|---|-------|-------------------|

## Dropped in verification
| File | Claim | Why dropped |
|------|-------|-------------|
```

Ask the user to approve, adjust, or reject each section, in one round.
Wait for approval before Step 7.

## Step 7: File the issues

Only after approval. Use the `/new-issue` capture format. Give no
acceptance criteria and no milestone:

```bash
gh issue create -R elan-registry/registry --title "test: TITLE" \
  --label "signal:operator,triage,enhancement,component: testing" \
  --body "$(cat <<'EOF'
## Signal

signal:operator — /groom-tests test-suite review on YYYY-MM-DD.

## Beneficiary

One sentence.

## Quote / evidence

<suite size line>

<the file list for this group, with file:line, lines, tests, and reason>
EOF
)"

gh issue comment NNN -R elan-registry/registry \
  --body "The YYYY-MM-DD test-suite review (/groom-tests) found these files again: <files>. This issue stays the owner."
```

Each issue body must contain the words `test-suite review`. The next run's
Step 1 finds the last run with them.

## Step 8: Output summary

- Suite size and the change since the last run
- Issues created, with URLs
- Comments added, with issue numbers
- Findings dropped in verification

End with plain text, not a menu: "Run `/groom-backlog` next. It weighs
these issues with the rest of the backlog." If `/groom-backlog` already ran
in this session, say instead: "No command to type now. The next
`/plan-milestone` weighs these issues."

## Important

- Never edit, delete, or run a test in this command.
- Never write acceptance criteria or set a milestone. `/plan-milestone`
  does that.
- A removal issue must keep #2327's rule: each removed test names the test
  that still covers its behavior.
- Data in `Data/` and text in `docs/plans/` do not go into an issue.
