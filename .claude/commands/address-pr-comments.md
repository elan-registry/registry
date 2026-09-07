---
description: Fetch PR review comments and CI findings, triage blocking vs advisory, fix blocking items, and re-verify
model: claude-opus-5
---

# Address PR Comments

Think hard when triaging blocking vs. advisory findings — a wrong call
either ships a real issue or wastes a fix/re-verify cycle.

Keep output brief — terse status lines, no preamble, no restating of steps.

After a PR is created and CI runs, this command fetches all review comments
and check annotations, triages them, fixes blocking items, and prepares the
PR for `/finish-issue`.

## Arguments

- `$ARGUMENTS` — (optional) PR number. If omitted, auto-detect from the
  current branch.

## Step 0: Initialize TaskList

Create tasks: find PR, fetch comments + CI findings, triage, fix blocking
items, re-verify CI, present advisory items. Set each `in_progress`/
`completed` as you go.

## Step 1: Identify the PR

If no argument provided, find the open PR for the current branch:

```bash
gh pr list --head "$(git branch --show-current)" --state open \
  --json number,title,url --repo elan-registry/registry
```

If no PR found, stop and tell the user to run `/commit-push-pr` first.

## Step 1.5: Ensure the automated review for the latest push has posted

PRs are opened as draft (`/commit-push-pr`) and `pr-to-milestone-review`
(`claude-code-review.yml`) runs automatically on every push regardless of
draft state — so a review should already be in flight for the current HEAD.
**Do not assume it landed** — the same event can be silently suppressed by
GitHub's abuse/rate throttle, and even a "successful" job run does not
guarantee a comment was posted (workflow-file-match guard, turn exhaustion).

Check for a review comment matching the current HEAD SHA:

```bash
HEAD_SHA=$(gh pr view <pr-number> --repo elan-registry/registry --json headRefOid --jq .headRefOid)
gh api "repos/elan-registry/registry/issues/<pr-number>/comments" \
  --jq '[.[] | select(.body | test("#{1,6}\\s+Strengths|\\*\\*Strengths\\*\\*"))] | length'
```

Poll every ~15s for up to ~2 minutes (`pr-to-milestone-review` is the
lightweight Sonnet job).

**If a matching comment is found:** proceed to Step 2 — its findings feed
into Step 4's triage same as any other comment.

**If none appears after the poll window:**

1. Check whether the PR opted out of review via `[skip-review]`/`[WIP]` in
   the title:

   ```bash
   gh pr view <pr-number> --json title -q .title --repo elan-registry/registry
   ```

   If either tag is present, stop here — review is intentionally skipped,
   not missing. Proceed to Step 2 (there's simply nothing from this source).

2. Otherwise, re-trigger manually:

   ```bash
   gh workflow run claude-code-review.yml \
     --ref main \
     --field pr_number=<pr-number> \
     --repo elan-registry/registry
   ```

3. Wait for the new run and re-check for the comment the same way. If it
   still doesn't appear and the PR's diff touches
   `.github/workflows/claude-code-review.yml`, this is the self-referential
   workflow-file skip case — report it distinctly; re-triggering will not
   fix it until the workflow file change is merged to `main`.

4. Report to the user if recovery was needed and what happened — never
   silently proceed without a confirmed comment or an explicit, reported
   reason recovery isn't applicable.

## Step 2: Fetch Review Comments

```bash
gh pr view <pr-number> --repo elan-registry/registry \
  --json reviews,comments
```

Also fetch inline code review comments:

```bash
gh api "repos/elan-registry/registry/pulls/<pr-number>/comments" \
  --jq '.[] | {path, line, body, user: .user.login}'
```

## Step 3: Fetch CI Check Annotations

Get the PR's head SHA and all check runs:

```bash
HEAD_SHA=$(gh pr view <pr-number> --repo elan-registry/registry \
  --json headRefOid --jq .headRefOid)
gh api "repos/elan-registry/registry/commits/${HEAD_SHA}/check-runs" \
  --jq '.check_runs[] | {name, conclusion, id, output: .output.summary}'
```

For any failed check runs, fetch their annotations:

```bash
gh api "repos/elan-registry/registry/check-runs/<run-id>/annotations" \
  --jq '.[] | {path, start_line, message, annotation_level}'
```

## Step 4: Triage All Findings

Categorize every comment and annotation into one of three tiers:

| Tier | Definition | Action |
| --- | --- | --- |
| **Blocking** | Must fix before merge: security issue, bug, standards violation, failing CI check with actionable error | Fix immediately |
| **Advisory** | Should consider but not blocking: style suggestion, minor improvement, optional refactor | Present to user for decision |
| **Informational** | No action needed: passing check summary, automated LGTM, context notes | Log and skip |

Output a triage table:

```text
## PR Comment Triage — PR #NNN

### Blocking (must fix)
| Source | File:Line | Issue |
|--------|-----------|-------|
| Claude Code Review | app/foo.php:42 | Missing CSRF token |

### Advisory (consider)
| Source | File:Line | Suggestion |

### Informational (no action)
- CodeQL: No new findings
- GitGuardian: Clean
```

If there are **no Blocking items**, skip to Step 7.

## Step 5: Fix Blocking Items

For each Blocking item, launch a `software-developer` agent (Sonnet) to fix it.
Provide the agent with:

- The specific file and line number
- The comment or annotation text
- The current file content (read the file first)
- The instruction: "Fix only this specific issue. Do not refactor surrounding code."

Run agents for independent files in parallel. For items in the same file,
run sequentially.

After each fix, verify the change looks correct before moving on.

## Step 5.5: Local review on full branch diff (before committing) — gated

This step is expensive (full-file reads of every changed file) and only
worth it once fixes have accumulated enough to plausibly interact. Run it
only if **at least one** threshold is met:

- 2 or more Blocking items were fixed in Step 5, or
- 3 or more commits have accumulated on this branch since it diverged from
  the base branch:

  ```bash
  BASE=$(gh pr view <pr-number> --repo elan-registry/registry --json baseRefName --jq .baseRefName)
  git rev-list --count $(git merge-base HEAD origin/$BASE)..HEAD
  ```

**If neither threshold is met** (e.g. a single one-line fix from Step 5),
skip straight to Step 6. Step 5's per-fix agent review plus CI's
`pr-to-milestone-review` backstop already cover a change this small — a full
branch re-review here would be a third read of the same tiny diff.

**If a threshold is met**, run the full review: get the full accumulated
branch diff — the same view CI uses — since this catches cross-commit issues
(dead code, broken call interactions, unreachable paths) that per-fix diffs miss.

```bash
git diff $(git merge-base HEAD origin/$BASE)..HEAD
```

Launch `pr-review-toolkit:code-reviewer` with:

- The full branch diff (output of the command above)
- The **full file content** of every changed file (read each file in full, not
  just the diff — this is how CI spots orphaned functions and unreachable fallbacks)
- Instruction: "Review this as the complete accumulated set of changes on this
  branch. Look for cross-commit issues: dead code, functions that are no longer
  called, interaction bugs between changes made in separate commits, unreachable
  fallbacks, and anything that looks correct in isolation but is broken in context."

**If the local review finds additional Blocking items**: fix them before
proceeding (same pattern as Step 5). Then re-run the local review to confirm
clean.

**If the local review finds Advisory/Recommendation items**: present them to
the user with a one-line summary each and ask which (if any) to address before
committing. Wait for the user's response. For each item the user wants to
address, fix it, then re-run the local review.

**If the local review is clean**: proceed to Step 6.

Do not commit until the local review is clean and the user has been consulted
on any recommendations.

## Step 6: Commit and Push Fixes

After all blocking items are fixed and the user has decided on any local review
recommendations, commit and push:

```bash
git add <changed-files>
git commit -m "fix: address PR review comments (#<pr-number>)"
git push origin "$(git branch --show-current)"
```

Wait up to 5 minutes for checks to re-run. Poll every 60 seconds:

```bash
gh pr checks <pr-number> --repo elan-registry/registry
```

If any check still fails after the fix, report the failure and stop — do not
proceed to Step 7 until all blocking items and CI checks are clean.

## Step 7: Present Advisory Items

If there are Advisory items, list them and ask:

> "Blocking items are resolved and CI is clean. Here are advisory suggestions
> from the review. Would you like to address any of these before merging?"

Present each advisory item with a one-line summary. Wait for the user's
response. For each item the user wants to address, follow the same
fix-commit-push pattern from Steps 5–6.

## Step 8: Summary

Output:

```text
PR #NNN is clean and ready to merge.

- Blocking items fixed: N
- Advisory items reviewed: N (M addressed, K deferred)
- CI status: all checks passing

Next step: /finish-issue [NNN] — mark ready for review, squash-merge, and
close the issue
```

Then use AskUserQuestion:

- Question: "PR is clean. What next?"
- Options: `Run /finish-issue` (recommended), `Compact context first`
  (recommended before a long next step — blocking/advisory items are
  resolved and pushed, so compacting here is safe and won't lose that
  state), `Ask more questions / discuss first`
- If the user picks `/finish-issue`, invoke it immediately via the Skill
  tool rather than telling them to type it.
- If the user picks `Compact context first`, tell them to run `/compact`
  themselves — it's a client-level operation, not something this command can
  trigger via a tool.

## Important

- **Never force-merge over failing checks.** If CI still fails after fixes,
  stop and report.
- Fix only what the comment identifies. Do not refactor surrounding code.
- If a "Blocking" item appears to be a false positive, present it to the user
  with the rationale before skipping it — never silently drop a blocking item.
- This command does NOT merge the PR. Run `/finish-issue` after this command
  completes cleanly.
