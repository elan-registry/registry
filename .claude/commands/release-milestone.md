---
description: Merge a milestone PR into main, tag the release, and publish a GitHub release
---

# Release Milestone

Merge a milestone PR into main, create an annotated tag, push to remotes, and
publish a GitHub release. This command picks up where `/finish-milestone` left
off — after the milestone PR has been created and reviewed.

## Arguments

- `$ARGUMENTS` — (optional) the milestone version number (e.g., `v2.17.0`).
  If omitted, auto-detect from the open `milestone/*` → `main` PR.

## Workflow

### Step 1: Find the milestone PR

```bash
gh pr list --base main --state open \
  --json number,title,headRefName,url
```

- Filter for PRs where `headRefName` starts with `milestone/`
- If `$ARGUMENTS` is provided, match against `milestone/$ARGUMENTS`
- If exactly one match, use it. If zero or multiple, stop and ask the user to
  specify.
- Extract the version from the branch name (e.g., `milestone/v2.17.0` →
  `v2.17.0`)

### Step 2: Verify preconditions

- The PR must be in a mergeable state (no conflicts, checks passing)
- The working tree must be clean (`git status --porcelain`)
- Must be on `main` or the milestone branch

```bash
gh pr view <number> --json mergeable,mergeStateStatus,statusCheckRollup
```

If checks are failing or the PR is not mergeable, stop and report the issue.

### Step 3: Check version consistency

- Extract the version from the milestone branch name (e.g., `v2.17.0`)
- Get the last release tag: `git describe --tags --abbrev=0`
- Verify the milestone version is newer than the last tag
- If there's a version conflict or ambiguity, stop and ask the user

### Step 4: Parse release notes for pre/post deployment steps

- Read `docs/releases/RELEASE_NOTES_v<version>.md`
- Check the "Required Actions After Deployment" section:
  - If it contains actual steps (not "None"), these are **post-deployment
    steps** to remind the user about
  - Check for any database migrations, configuration changes, or manual steps
- Parse these for the summary in step 5

### Step 5: Show summary and ask for confirmation

Display:

- PR number, title, and URL
- Number of commits that will be merged
- Version that will be tagged
- Release notes file path
- **If post-deployment steps exist**: Display them prominently with a reminder
  to complete them after deploying
- Remind: "This will merge the PR, create a tag, push to origin, and publish
  a GitHub release. Deployment to test/prod is a separate manual step."

**Ask the user to confirm before proceeding.** This is the point of no return.

### Step 6: Switch to main and pull latest

```bash
git checkout main
git pull origin main
```

### Step 7: Merge the PR

```bash
gh pr merge <number> --merge --delete-branch
```

This uses a regular merge (not squash) to preserve the milestone branch's
squash-merged commit history. The `--delete-branch` flag removes the remote
milestone branch.

### Step 8: Pull the merge commit

```bash
git pull origin main
```

### Step 9: Create annotated tag

```bash
git tag -a v<version> -m "Release v<version>: <milestone title>

<Key highlights from release notes>

Full release notes: docs/releases/RELEASE_NOTES_v<version>.md"
```

### Step 10: Push to origin with tags

```bash
git push origin main
git push origin v<version>
```

### Step 11: Delete the local milestone branch (if it still exists)

```bash
git branch -d milestone/<version> 2>/dev/null
```

### Step 12: Create a GitHub release

```bash
gh release create v<version> \
  --title "Elan Registry v<version> — <milestone title>" \
  --notes-file docs/releases/RELEASE_NOTES_v<version>.md
```

### Step 13: Delete the release notes file from the repo

The release notes are now published to GitHub Releases — the file in `docs/releases/`
is no longer needed and should be removed so it doesn't become stale.

```bash
git rm docs/releases/RELEASE_NOTES_v<version>.md
git commit -m "chore: remove v<version> release notes — published to GitHub Releases"
git push origin main
```

### Step 15: Close the GitHub milestone

```bash
gh api repos/unibrain1/elanregistry/milestones/<milestone_number> \
  -X PATCH -f state=closed
```

Find the milestone number from the PR's milestone field or by listing
milestones.

### Step 16: Output summary

```text
═══════════════════════════════════════════════════════
Release v<version> Created Successfully!
═══════════════════════════════════════════════════════

Release:
- GitHub Release: <URL>
- Tag: v<version>
- Milestone: Closed

Next Steps — Deploy:

  1. Deploy to TEST server (recommended first):
     git push test main
     git push test v<version>

  2. Validate on test server

  3. Deploy to PRODUCTION when ready:
     git push prod main
     git push prod v<version>

Links:
- GitHub Release: <release URL>
```

**If post-deployment steps exist** (from step 4), list them prominently:

```text
Post-deployment steps (from release notes):
  1. <step from release notes>
  2. <step from release notes>
```

Remind: "See DEPLOYMENT.md for the full deployment verification checklist."

## Important

- **Confirmation is required** before merging (step 5). Do not proceed
  without explicit user approval.
- If any step fails, stop immediately and report the error. Do not continue
  with partial state.
- This command assumes `/finish-milestone` has already been run (PR exists,
  release notes finalized, issues closed).
- The `--delete-branch` flag on `gh pr merge` handles remote branch cleanup.
  Step 11 handles local cleanup.
- **Do NOT push to `test` or `prod` remotes** — deployment is a separate
  manual step. Only push to `origin`.
- The VERSION file is auto-generated by server-side post-receive hooks — do
  not create or edit it locally.
- **Delete the release notes file** (step 13) after publishing to GitHub
  Releases. `docs/releases/` holds only the current milestone's working draft;
  GitHub Releases is the canonical archive.
