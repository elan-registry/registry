---
description: Fetch PR review comments and CI findings, triage blocking vs advisory, fix blocking items, and re-verify
model: claude-sonnet-4-6
---

# Address PR Comments

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

## Step 6: Commit and Push Fixes

After all blocking items are fixed, commit and push:

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

Next step: /finish-issue [NNN] — squash-merge and close the issue
```

## Important

- **Never force-merge over failing checks.** If CI still fails after fixes,
  stop and report.
- Fix only what the comment identifies. Do not refactor surrounding code.
- If a "Blocking" item appears to be a false positive, present it to the user
  with the rationale before skipping it — never silently drop a blocking item.
- This command does NOT merge the PR. Run `/finish-issue` after this command
  completes cleanly.
