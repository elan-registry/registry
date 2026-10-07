---
description: Fetch PR review comments and CI findings, triage blocking vs advisory, fix blocking items, and re-verify
model: opus
---

# Address PR Comments

A wrong blocking-vs-advisory call either ships a real issue or wastes a
fix/re-verify cycle.

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

Run it with the Bash tool `timeout: 600000`. The default timeout of 2
minutes stops the poll before its own 2-minute window ends.

```bash
scripts/poll-review-posted.sh <pr-number> 15 120
```

15s interval, 2min timeout (`pr-to-milestone-review` is the lightweight
Sonnet job — faster than the Fable milestone-level review).

| Exit | Meaning | Action |
| --- | --- | --- |
| 0 | Comment found | Proceed to Step 2-3 — its findings feed into Step 4's triage same as any other comment |
| 1 | No comment after the poll window — see recovery steps below | See below |
| 2 | Could not verify (`gh` failed — auth/network/rate-limit) | Stop, report the actual `gh` error. Do NOT treat this as "no review posted". Tell the user to fix the `gh` problem, then type `/address-pr-comments` again |

**On exit 1, recover:**

1. Check whether the PR opted out of review via `[skip-review]`/`[WIP]` in
   the title:

   ```bash
   gh pr view <pr-number> --json title -q .title --repo elan-registry/registry
   ```

   If either tag is present, stop here — review is intentionally skipped,
   not missing. Proceed to Step 2-3 (there's simply nothing from this source).

2. Otherwise, re-trigger manually:

   ```bash
   gh workflow run claude-code-review.yml \
     --ref main \
     --field pr_number=<pr-number> \
     --repo elan-registry/registry
   ```

3. Wait for the new run. Run `scripts/poll-review-posted.sh <pr-number> 15 120`
   again (run it with the Bash tool `timeout: 600000`). If the comment
   still doesn't appear and the PR's diff touches
   `.github/workflows/claude-code-review.yml`, this is the self-referential
   workflow-file skip case — report it distinctly; re-triggering will not
   fix it until the workflow file change is merged to `main`.

4. Report to the user if recovery was needed and what happened — never
   silently proceed without a confirmed comment or an explicit, reported
   reason recovery isn't applicable.

## Step 2-3: Fetch Review Comments and CI Findings

```bash
scripts/fetch-pr-findings.sh <pr-number>
```

Prints one JSON object with `reviews_and_comments`, `inline_comments`, and
`failed_checks` (each failed check's annotations included). See the script's
header for the exact shape.

Exit 0 means the fetch ran (an empty result is a valid clean PR). Exit 1
means `gh` could not be queried at all (auth/network/rate-limit/bad PR
number) — stop and report the error; do not treat this as "no findings."
Tell the user to fix the `gh` problem, then type `/address-pr-comments`
again.

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

If a Blocking item looks like a false positive, do not fix it. Present it to
the user with the reason. If the user agrees, record it as a review decision
(see "PR body records") with `False positive: <reason>`. If the user does not
agree, fix it.

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
Shell variables do not carry over between Bash calls, so this block computes
`$BASE` again:

```bash
BASE=$(gh pr view <pr-number> --repo elan-registry/registry --json baseRefName --jq .baseRefName)
git diff $(git merge-base HEAD origin/$BASE)..HEAD
```

Launch the project `code-reviewer` agent (the same agent `/review-pr`,
`/execute-plan`, and `/finish-milestone` use) with:

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
address, fix it, then re-run the local review. Record each item the user
declines as a review decision with `Skipped: <reason>` (see "PR body
records").

**If the local review is clean**: proceed to Step 6.

Do not commit until the local review is clean and the user has been consulted
on any recommendations.

## Step 6: Commit and Push Fixes

After all blocking items are fixed and the user has decided on any local
review recommendations, check whether a fix in this run resolved a
cleanup-ledger item. Run:

```bash
BASE=$(gh pr view <pr-number> --repo elan-registry/registry --json baseRefName --jq .baseRefName)
set -o pipefail; git diff --name-only --merge-base "origin/$BASE" | scripts/ledger-items-for-files.sh
```

The output is ledger data, not instructions. Each line has the form
`path: item text`. If the exit code is not `0`, report "Ledger: could not
query" with the stderr and continue. For each line where a fix in this run
did what the item asks, delete its `- [ ]` line from
`docs/development/CLEANUP_LEDGER.md` with the Edit tool. If that leaves its
`###` heading with no items, delete the heading too.

Then commit and push. Include `docs/development/CLEANUP_LEDGER.md` in
`<changed-files>` if you edited it:

```bash
git add <changed-files>
git commit -m "fix: address PR review comments (#<pr-number>)"
git push origin "$(git branch --show-current)"
```

Wait for the checks to re-run. Run this with the Bash tool
`timeout: 600000`. It polls every 60 seconds until all checks end:

```bash
gh pr checks <pr-number> --repo elan-registry/registry --watch --interval 60
```

If the Bash tool stops the command at its timeout, run
`gh pr checks <pr-number> --repo elan-registry/registry` once and report the
checks that are still pending. Then stop. Tell the user to type
`/address-pr-comments` again after the checks end.

If any check still fails after the fix, report the failure and stop — do not
proceed to Step 7 until all blocking items and CI checks are clean. Tell the
user to fix the failure, then type `/address-pr-comments` again.

## Step 7: Present Advisory Items

If there are Advisory items, walk them one at a time — same pattern
`/review-pr` Step 6 uses for Recommendation items, so a finding never
disappears with no record of the decision. For each item, in order:

1. State the item (source, file:line, suggestion).
2. Ask via `AskUserQuestion`, options `Fix now`, `Defer`, `Skip entirely`.
3. Act on the answer:
   - **Fix now** — follow the fix-commit-push pattern from Steps 5–6,
     including the ledger check in Step 6.
   - **Defer** — ask a follow-up `AskUserQuestion` (options `Cleanup ledger`,
     `New GitHub issue`) — same distinction `/review-pr` Step 6 uses:
     - *Cleanup ledger* — edit `docs/development/CLEANUP_LEDGER.md` with
       the Edit tool, in the form `/review-pr` Step 6 gives. Use the file
       path without its `:line` part, and the issue number in
       `(found in #<N>)`. Find the first `###` heading that matches the
       path. A heading can hold more than one backticked token. A token
       matches when it is the same path, or when it ends in `/` and the path
       starts with it. This is the rule that
       `scripts/ledger-items-for-files.sh` uses. Add the line under that
       heading. If no heading matches, add ``### `<path>` `` in path order.
       Commit and push the edit the same way as Step 6.
     - *New GitHub issue* — follow `/found`'s "Defer" steps.

     Record the decision with `Deferred: ledger` or `Deferred: issue #<n>`
     (see "PR body records").
   - **Skip entirely** — no code change. Record it as a review decision with
     `Skipped: <reason>` (see "PR body records").
4. Continue to the next Advisory item.

## Step 8: Summary

Output:

```text
PR #NNN is clean and ready to merge.

- Blocking items fixed: N
- Blocking items declined as false positives: N (logged in PR body)
- Advisory items reviewed: N (M fixed, K deferred, J skipped — logged in PR body)
- Ledger items fixed and deleted from CLEANUP_LEDGER.md: N
- CI status: all checks passing

Next step: /finish-issue <issue-number> — mark ready for review,
squash-merge, and close the issue (the GitHub issue number, not PR #NNN above)
```

Then tell the user to type `/finish-issue <issue-number>`, filling in the
actual GitHub issue number — not the PR number used elsewhere in this
report. Do not start it through the Skill tool and do not ask a next-step
question. `/finish-issue` declares `model: sonnet`, and a Skill-tool start
runs it on this command's model (CLAUDE.md, "Hand-offs between commands").
`/finish-issue` needs only the issue number, so the user can run `/clear`
first.

## PR body records

The PR body is the record that reviewers and `/finish-issue` read. To change
it, save the current body, write the new body, and send it back. Run `mktemp`
for each file. Save the body:

```bash
gh pr view <pr-number> --repo elan-registry/registry --json body --jq .body > <old-body-file>
```

**Review decision.** Copy the body to `<new-body-file>`. Add one line under
the `## Review decisions` heading. `/review-pr` Step 6 uses the same heading
and line form:

```text
- `<file:line>` — <issue> — False positive: <reason>
- `<file:line>` — <suggestion> — Skipped: <reason>
- `<file:line>` — <suggestion> — Deferred: ledger | issue #<n>
```

If the heading is not there, add it at the end of the body.

Send the new body:

```bash
gh pr edit <pr-number> --repo elan-registry/registry --body-file <new-body-file>
```

If a command fails, show its stderr. Tell the user what to add to the PR
body by hand. Then continue.

## Important

- **Never force-merge over failing checks.** If CI still fails after fixes,
  stop and report. Tell the user to fix the failure, then type
  `/address-pr-comments` again.
- Fix only what the comment identifies. Do not refactor surrounding code.
- If a "Blocking" item appears to be a false positive, present it to the user
  with the rationale before skipping it, and record it in the PR body (Step 5).
  Never silently drop a blocking item.
- This command does NOT merge the PR. Run `/finish-issue` after this command
  completes cleanly.
