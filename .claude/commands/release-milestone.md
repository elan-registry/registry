---
description: Merge a milestone PR into main, tag the release, and publish a GitHub release
model: claude-sonnet-4-6
---

# Release Milestone

Merge a milestone PR into main, create an annotated tag, push to remotes, and
publish a GitHub release. This command picks up where `/finish-milestone` left
off — after the milestone PR has been created and reviewed.

## Arguments

- `$ARGUMENTS` — (optional) the milestone version number (e.g., `v2.17.0`).
  If omitted, auto-detect from the open `milestone/*` → `main` PR.

## Workflow

### Step 0: Initialize TaskList

Before any other action, create one tracking task per workflow step using
TaskCreate so the user can see live progress. Suggested task subjects (one
per TaskCreate call):

1. Find the milestone PR
2. Verify preconditions
3. Check version consistency
4. Parse release notes for deployment steps
5. Show summary and get confirmation
6. Switch to main, pull, verify clean state
7. Merge the PR
8. Pull the merge commit
9. Delete local milestone branch
10. Stage release notes content
11. Delete release notes file from repo
12. Create annotated tag on final commit
13. Push tag to origin
14. Create GitHub release on pushed tag
15. Close GitHub milestone
16. Output summary

Set each task to `in_progress` as you begin it and `completed` immediately
on success. If a step fails, leave the task `in_progress` and surface the
error — do not mark completed.

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

### Step 6: Switch to main, pull, and verify a clean local state

```bash
git checkout main
git fetch origin --prune
git pull origin main
```

Then verify the local `main` is **exactly** at `origin/main` — no local-only
commits hanging around from earlier sessions:

```bash
git rev-list --left-right --count origin/main...main
# expect: 0 0
```

If the right-side count is non-zero, **stop**. Local `main` has unpushed
commits that aren't part of any merged PR. Investigate before proceeding:
park them on a side branch, hard-reset local `main` to `origin/main`, then
re-run the step. Do NOT carry stray commits into a release merge.

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

### Step 9: Delete the local milestone branch (if it still exists)

```bash
git branch -d milestone/<version> 2>/dev/null
```

### Step 10: Stage release notes content for the GitHub release

The GitHub release in Step 14 needs the notes content, but the next step
deletes the notes file from the repo. Copy it to a temp location now so it
survives the deletion:

```bash
cp docs/releases/RELEASE_NOTES_v<version>.md /tmp/release-notes-v<version>.md
```

### Step 11: Delete the release notes file from the repo

The release notes will be published to GitHub Releases (Step 14) — the file in
`docs/releases/` is no longer needed and should be removed so it doesn't
become stale.

```bash
git rm docs/releases/RELEASE_NOTES_v<version>.md
git commit -m "chore: remove v<version> release notes — published to GitHub Releases"
git push origin main
```

### Step 12: Create annotated tag on the final commit

The tag is created after all housekeeping commits so that `git describe`
returns a clean `v<version>` with no `-N-g<hash>` suffix on the deployed
codebase.

```bash
git tag -a v<version> -m "Release v<version>: <milestone title>

<Key highlights from release notes>

Full release notes: https://github.com/unibrain1/elanregistry/releases/tag/v<version>"
```

Verify:

```bash
git describe HEAD            # expect: v<version> (no suffix)
git rev-parse v<version>^{commit}   # should match HEAD
```

### Step 13: Push the tag to origin

```bash
git push origin v<version>
```

### Step 14: Create the GitHub release on the pushed tag

```bash
gh release create v<version> \
  --title "Elan Registry v<version> — <milestone title>" \
  --notes-file /tmp/release-notes-v<version>.md \
  --verify-tag
```

`--verify-tag` makes `gh` use the already-pushed tag rather than auto-creating
a new one. This is the critical ordering invariant: **tag first, release
second** — never `gh release create` before the cleanup commit and tag,
because that would auto-tag at the merge commit (before cleanup) and you'd
have to delete/re-push the tag, which orphans the release into a draft.

Then clean up the temp file:

```bash
rm /tmp/release-notes-v<version>.md
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
- The `--delete-branch` flag on `gh pr merge` handles remote-branch cleanup.
  Step 9 handles local cleanup.
- **Do NOT push to `test` or `prod` remotes** — deployment is a separate
  manual step. Only push to `origin`.
- The VERSION file is auto-generated by server-side post-receive hooks — do
  not create or edit it locally.
- **Required ordering: cleanup commit → tag → GitHub release.** Do NOT call
  `gh release create` before the tag has been pushed. `gh release create`
  auto-tags at the current HEAD if no matching tag exists, which would land
  the tag on the merge commit (one commit before the cleanup commit). Fixing
  that after the fact requires deleting the tag from both local and remote,
  re-creating an annotated tag at the cleanup commit, re-pushing, and
  recreating the release (the original is orphaned to a draft when its tag
  is deleted). The Step 10–14 sequence (stage notes → cleanup → tag → push
  → release) avoids all of this. Always use `--verify-tag` on Step 14's
  `gh release create` to make the failure mode loud if anything is out of
  order.
- **Tag must point to the final housekeeping commit** so `git describe`
  returns a clean `v<version>` with no `-N-g<hash>` suffix on test/prod.
- **Delete the release notes file** (Step 11) before tagging.
  `docs/releases/` holds only the current milestone's working draft; GitHub
  Releases is the canonical archive. Step 10 stages a copy to `/tmp/` so the
  release in Step 14 still has the notes content.
- **Local `main` must equal `origin/main` before merging** (Step 6 check).
  Stray local commits — even legitimate fixes — must not ride along with the
  milestone merge. Park them on a side branch and open a separate PR.
