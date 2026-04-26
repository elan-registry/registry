---
description: Create a PR to merge a completed milestone branch into main, finalize docs and release notes
---

# Finish Milestone

Create a PR to merge a completed milestone branch into main, finalize release
notes, update wiki documentation, and prepare for release.

## Arguments

- `$ARGUMENTS` — the milestone version number (e.g., `v2.17.0`)

## Workflow

### Step 1: Verify the milestone branch exists

- Check `git branch -a | grep "milestone/$ARGUMENTS"`
- If not found, stop and report error.

### Step 2: Check for open issues still in the milestone

```bash
gh issue list --milestone "<full milestone title>" --state open --json number,title
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

Get the list of changed source files in this milestone:

```bash
git diff --name-only main...milestone/$ARGUMENTS
```

Determine if wiki updates are needed: check whether changed files affect architecture, database schema,
UserSpice integration, PHP classes, file storage, external integrations, user flows, or dev tooling.

If wiki updates are needed:

1. Clone the wiki repo into a temporary location if not already available
2. Read the relevant wiki pages
3. Launch `technical-documentation-writer` agent(s) in parallel to update
   affected wiki pages
4. Save updated pages to `wiki/` directory in the project root
5. Note: wiki pages must be manually pushed to the wiki repo after review

If no wiki updates are needed (e.g., purely bug fixes with no structural
changes), note this in the summary and skip.

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

### Step 9.5: Security Review

Before creating the PR, run a security audit of all changes in this milestone:

```bash
git diff main...milestone/$ARGUMENTS -- '*.php' '*.js'
```

Launch the `security-reviewer` agent via the Agent tool with
`subagent_type: "security-reviewer"`. Provide the full diff of `.php` and
`.js` files against `main`.

- If **Critical or High** severity issues are found, **stop** and tell the
  user to fix them before proceeding.
- If only Medium/Low or no issues, note findings in the summary and proceed.

### Step 9.7: Local multi-agent review (before opening the PR)

Run `/review-pr` locally against `main` on the milestone branch. This runs
on the user's Claude subscription, so CI doesn't have to pay for the first
deep pass on the milestone PR.

```text
/review-pr
```

Scope the review to `git diff main...milestone/$ARGUMENTS`. The multi-agent
suite runs in parallel (currently: code-reviewer, pr-test-analyzer,
silent-failure-hunter, comment-analyzer, type-design-analyzer,
senior-architect — agent list reflects `/review-pr` implementation).
Focus areas at milestone level:

- Cross-issue integration (did two PRs introduce contradictions?)
- Architecture drift vs. the wiki
- Release-notes accuracy vs. the merged PR list
- Aggregated security surface

If Critical or Important issues surface, **stop and fix them before
creating the PR**. These would otherwise land as blocking comments on the
milestone-level CI review and require another push cycle.

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
