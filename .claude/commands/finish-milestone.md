---
description: Create a PR to merge a completed milestone branch into main, finalize docs and release notes
model: claude-sonnet-4-6
---

# Finish Milestone

Create a PR to merge a completed milestone branch into main, finalize release
notes, update wiki documentation, and prepare for release.

## Arguments

- `$ARGUMENTS` — the milestone version number (e.g., `v2.17.0`)

## Workflow

### Step 0: Initialize TaskList

Before any other action, create one tracking task per workflow step using
TaskCreate. Suggested task subjects:

1. Verify the milestone branch exists
2. Check for open issues still in the milestone
3. Switch to milestone branch and ensure up to date
4. Gather all merged PRs targeting the milestone branch
5. Get the full diff against main
6. Finalize release notes
7. Update wiki documentation (or skip)
8. Update CLAUDE.md if needed
9. Security review (Step 9.5) + local multi-agent review (Step 9.7)
10. Create the PR targeting main
11. Note CI milestone review trigger
12. Output summary

Set each task to `in_progress` when you begin it and `completed` on success.

### Step 1: Verify the milestone branch exists

- Check `git branch -a | grep "milestone/$ARGUMENTS"`
- If not found, stop and report error.

### Step 2: Check for open issues still in the milestone

Use the direct API (`gh issue list --milestone` can silently return empty results — the milestone number is already recorded from Step 1):

```bash
gh api "repos/unibrain1/elanregistry/issues?milestone=<MILESTONE_NUM>&state=open&per_page=20" \
  --jq '.[] | {number, title}'
```

- If open issues remain, warn user and list them. Ask if they want to proceed
  or finish remaining issues first.

### Step 3: Switch to milestone branch and ensure up to date

```bash
git checkout milestone/$ARGUMENTS
git pull origin milestone/$ARGUMENTS
```

### Step 4: Gather all merged PRs targeting the milestone branch

```bash
gh pr list --base milestone/$ARGUMENTS --state merged --json number,title,url
```

### Step 5: Get the full diff against main

```bash
git log main..milestone/$ARGUMENTS --oneline
git diff --stat main..milestone/$ARGUMENTS
```

### Step 6: Finalize release notes at `docs/releases/RELEASE_NOTES_v$ARGUMENTS.md`

- Read the file and verify all issues are marked as resolved (no "WIP:"
  prefixes remain)
- Use the `technical-documentation-writer` agent to finalize:
  - Fill in any remaining template placeholders
  - Ensure deployment instructions, verification steps are complete
  - Ensure "Required Actions After Deployment" is accurate
  - Verify all closed issues are listed in "Issues Resolved"
- Commit the finalized release notes if changes were made

### Step 7: Update wiki documentation

**Default: skip.** Wiki updates are only needed when the milestone changes architecture, database schema, PHP classes, external integrations, or user-visible flows.

Get the changed source files:

```bash
git diff --name-only main...milestone/$ARGUMENTS
```

**Skip wiki update if** changes are only: bug fixes, config tweaks, docs reorganization, CSS/JS tweaks, or SQL seed data with no schema change.

**Run wiki update if** changed files include: `usersc/classes/`, `database/*.sql` (schema
changes), new user flows, new env variables, or changes to how UserSpice is integrated.

If update is needed:

1. Clone wiki repo if not already available
2. Read only the affected wiki pages (not all pages)
3. Launch `technical-documentation-writer` agent (haiku) to update only those pages
4. Save to `wiki/` directory; push to wiki repo manually after review

Commit any wiki files:

```bash
git add wiki/
git commit -m "docs: update wiki pages for $ARGUMENTS milestone changes"
```

### Step 8: Update CLAUDE.md if needed

Review CLAUDE.md against the milestone's changes. Check whether any updates
are needed for:

- New environment variables or configuration
- New commands or scripts
- New important files or directories
- Changed architectural rules or patterns
- New testing requirements or conventions
- Changes to deploy process or CI/CD

If updates are needed, make targeted edits and commit:

```bash
git add CLAUDE.md
git commit -m "docs: update CLAUDE.md for $ARGUMENTS milestone changes"
```

If no updates needed, skip.

### Step 9.5: Cross-PR Security Integration Check

By the time this step runs, every individual issue PR has already passed:
security-reviewer in `/start-issue`, CodeQL CI, and Claude Code Review CI.
Do **not** re-run a full OWASP pass over already-reviewed files.

Instead, run a targeted cross-PR integration check. Get the full diff:

```bash
git diff main...milestone/$ARGUMENTS -- '*.php' '*.js'
```

Launch the `security-reviewer` agent with this scoped prompt:
> "Review only for cross-PR security interactions introduced by combining
> these changes. Focus on: (1) new code paths where output from one changed
> file flows into input handling in another changed file; (2) changes to
> shared auth, session, or CSRF middleware; (3) any file touched by 3+ PRs
> that may have accumulated risk across changes. Skip file-level OWASP
> checks — those were done per-issue. Report only findings that could not
> have been caught by reviewing each PR in isolation."

- If **Critical or High** cross-integration findings are found, **stop** and
  tell the user to fix them before proceeding.
- If only Medium/Low or no findings, note in summary and proceed.

### Step 9.7: Local multi-agent review (before opening the PR)

Run a scoped `/review-pr` against `main` on the milestone branch. Scope the agents to the file types changed — don't run all agents unconditionally.

Determine which agents apply based on `git diff --name-only main...milestone/$ARGUMENTS`:

| Changed file types | Agents to run |
| --- | --- |
| `.php` files | code-reviewer, silent-failure-hunter |
| `.php` with forms/SQL | + security-reviewer |
| New PHP classes/types | + type-design-analyzer |
| Test files changed | pr-test-analyzer |
| Docs/comments changed | comment-analyzer |

Launch only the applicable agents in parallel. Skip agents for file types not present in the diff.

Focus areas at milestone level:

- Cross-issue integration (did two PRs introduce contradictions?)
- Release-notes accuracy vs. the merged PR list
- Aggregated security surface

If Critical or Important issues surface, **stop and fix them before creating the PR**.

Once the local review is clean, proceed to Step 10.

### Step 10: Create the PR targeting main

```bash
gh pr create \
  --base main \
  --head milestone/$ARGUMENTS \
  --title "$ARGUMENTS — <milestone name>" \
  --body "$(cat <<'EOF'
## Summary

<1-2 sentence description of the milestone's purpose>

## Issues Resolved

<List each merged PR with closing keywords>

Closes #NNN — Issue title (PR #NN)
Closes #NNN — Issue title (PR #NN)

## Release Notes

See `docs/releases/RELEASE_NOTES_v$ARGUMENTS.md` for complete release notes.

## Test Plan

- [ ] All issue PRs were reviewed and merged into milestone branch
- [ ] Pre-commit hooks pass on all changed files
- [ ] Unit tests pass (`composer test:quick`)
- [ ] Integration tests pass (`composer test:medium`)
- [ ] Browser tests pass where applicable (`npm run playwright:test`)
- [ ] Manual verification of key user flows
- [ ] Security review completed (run before this PR was created)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

**CRITICAL**: The PR body MUST include `Closes #NNN` for every issue in the
milestone. Individual issue PRs target the milestone branch (not main), so
their closing keywords won't auto-close issues. Only this final PR merged into
main triggers auto-closure.

Fill in actual data from steps 4 and 5.

### Step 11: CI milestone review

Once the PR is open, the `claude-code-review.yml` workflow automatically
runs a milestone-level review (multi-agent, Opus) against `main`. It reads
`merged-prs.txt`, checks release-notes accuracy, architecture drift,
integration, and deployment readiness.

If you need to re-run the deep review later (e.g., after pushing fixes),
apply the `deep-review` label to the PR or comment `@claude deep-review`.
It will not re-run on every push — that keeps CI cost down.

### Step 12: Output summary

- The PR number and URL
- List of merged issue PRs included
- Release notes status (finalized or needs attention)
- Wiki updates status (updated, committed, or skipped)
- PRD update status (updated or skipped)
- CLAUDE.md update status (updated or skipped)
- Remind: wiki/ files need to be manually pushed to the wiki repo
- Next steps:
  - "The CI milestone-level review runs automatically on PR open"
  - "To re-run the deep review later, label the PR `deep-review` or comment `@claude deep-review`"
  - "After the PR is merged, run `/release-milestone $ARGUMENTS` to tag and deploy"
  - "Release notes are at `docs/releases/RELEASE_NOTES_v$ARGUMENTS.md`"

## Important

- **Closing keywords are critical** — without them in the PR body, issues
  won't auto-close on merge
- The PR MUST target `main`, not any other branch
- Wiki updates go to `wiki/` directory — they must be manually pushed to the
  separate wiki git repo
- Do not push to any remote — this command only creates the PR on GitHub
- If release notes still have WIP markers, flag this prominently before
  creating the PR
