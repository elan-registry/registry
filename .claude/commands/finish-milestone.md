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

Determine if wiki updates are needed by checking whether changed files affect:

- Application architecture or directory structure
- Database schema or relationships
- UserSpice integration or access control
- PHP classes or data flow patterns
- File storage or image handling
- External integrations
- Key user flows
- Development workflow or tooling

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

### Step 8: Update PRD if needed

Read `docs/PRD.md` and compare it against the milestone's closed issues and
changed source files. Check whether any changes in this milestone affect
product requirements:

- New features or behaviors that should be documented as requirements
- Changed acceptance criteria (e.g., new validation rules, new error states)
- New data model fields, entities, or relationships
- New user flows or changed screen descriptions
- New security requirements (auth flows, CSP changes, rate limiting)
- Changed technical architecture (new classes, new integrations)
- Updated roadmap status or version references

For each section that needs updating:

- Make targeted, minimal edits — do not rewrite unchanged sections
- Preserve the existing voice, formatting, and section numbering
- Add new requirements inline where they logically belong
- If the milestone introduced an entirely new feature area, add a new
  subsection

If no PRD changes are needed (e.g., the milestone was purely internal
refactoring, test coverage, or bug fixes that don't change product behavior),
note this in the summary and skip.

Commit the PRD updates to the milestone branch:

```bash
git add docs/PRD.md
git commit -m "docs: update PRD for $ARGUMENTS milestone changes"
```

### Step 9: Update CLAUDE.md if needed

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
- [ ] Security review completed

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

**CRITICAL**: The PR body MUST include `Closes #NNN` for every issue in the
milestone. Individual issue PRs target the milestone branch (not main), so
their closing keywords won't auto-close issues. Only this final PR merged into
main triggers auto-closure.

Fill in actual data from steps 4 and 5.

### Step 11: Run multi-agent review

Suggest the user run `/review-pr` for comprehensive review before merging.

### Step 12: Output summary

- The PR number and URL
- List of merged issue PRs included
- Release notes status (finalized or needs attention)
- Wiki updates status (updated, committed, or skipped)
- PRD update status (updated or skipped)
- CLAUDE.md update status (updated or skipped)
- Remind: wiki/ files need to be manually pushed to the wiki repo
- Next steps:
  - "Use `/review-pr` to review the milestone PR"
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
