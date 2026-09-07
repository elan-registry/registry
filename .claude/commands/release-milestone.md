---
description: Merge a milestone PR into main, tag the release, and publish a GitHub release
model: claude-fable-5-1
---

# Release Milestone

Keep output brief — terse status lines, no preamble, no restating of steps.

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
6. Stage release notes content, then delete the file and push to the
   milestone branch (updates the open PR)
7. Switch to main, pull, verify clean state
8. Merge the PR
9. Pull the merge commit
10. Delete local milestone branch
11. Create annotated tag on the merge commit
12. Push tag to origin
13. Create GitHub release (draft) on pushed tag
14. Close GitHub milestone
15. Output summary

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
- **No unresolved Blocking/Important review findings** (see below) — this
  command does not fix problems, only merges/tags/publishes what's already
  been fully vetted by `/finish-milestone`.

```bash
gh pr view <number> --json mergeable,mergeStateStatus,statusCheckRollup
```

If checks are failing or the PR is not mergeable, stop and report the issue.

**This command assumes `/finish-milestone` already resolved every review
finding before handing off — it does not fix things itself.** Confirm that
assumption instead of trusting it blindly: fetch every posted review comment
on the PR and check for a `Blocking` or `Important` heading with actual
content.

```bash
gh api "repos/elan-registry/registry/issues/<number>/comments" --jq '.[].body'
```

**If any comment shows an unresolved Blocking/Important finding** (no later
comment or commit demonstrably addresses it): **stop immediately.** Do not
proceed with the confirmation in Step 5, and do not fix the finding as part
of this run. Report the finding to the user and tell them to go back to
`/finish-milestone` (or a manual fix-and-push cycle followed by a fresh
review) to resolve it there, on the still-open, still-reviewable PR — not
here, where the next steps are irreversible merge/tag/publish actions.

This is the second, independent check on the same requirement
`/finish-milestone` Step 11.5 exists to satisfy — it exists so that a PR
which sat open for a while, or one that reached this command by some other
path, still gets caught rather than silently assumed clean.

### Step 3: Check version consistency

- Extract the version from the milestone branch name (e.g., `v2.17.0`)
- Get the last release tag: `git describe --tags --abbrev=0`
- Verify the milestone version is newer than the last tag
- If there's a version conflict or ambiguity, stop and ask the user

### Step 4: Parse release notes for pre/post deployment steps

- Read `docs/releases/RELEASE_NOTES_<version>.md`
- Check the "Required Actions After Deployment" section:
  - If it contains actual steps (not "None"), these are **post-deployment
    steps** to remind the user about
  - Check for any database migrations, configuration changes, or manual steps
- Parse these for the summary in step 5
- Also collect the inputs the deploy sheet (Step 15) needs, from the diff
  `git diff --name-only <last-tag>...milestone/<version>`:
  new files under `database/migrations/` (and whether any contains
  `CREATE TRIGGER`), `scripts/server-hooks/post-receive` changed, new files
  calling `securePage(`, new files under `app/admin/scripts/fix/` or
  `maintenance/`, `.env.example` changed. Read `.claude.local.md` § "Deployment
  hosts" for the ssh alias and docroots; if the section is missing, stop and
  ask the user to add it (copy the block from `.claude.local.md.example`).

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

### Step 6: Stage release notes content, delete the file, push to the milestone branch

The release notes file must be deleted **before** the PR merges, as a commit
inside the PR itself — never as a direct push to `main` afterward. Otherwise
the tag would end up pointing at an unreviewed commit that was never part of
the milestone PR, and `main`'s protected-branch history would show a bare
push alongside the merge.

The GitHub release (Step 13) still needs the notes content after the file is
gone, so copy it to a temp location first:

```bash
git checkout milestone/<version>
git pull origin milestone/<version>
cp docs/releases/RELEASE_NOTES_<version>.md /tmp/release-notes-v<version>.md
```

Then delete the file and push the deletion to the milestone branch — this
updates the still-open PR with one more reviewable commit, it does not merge
anything yet:

```bash
git rm docs/releases/RELEASE_NOTES_<version>.md
git commit -m "chore: remove v<version> release notes — published to GitHub Releases"
git push origin milestone/<version>
```

If CI re-runs on this push, wait for it to go green before proceeding to
Step 7 — do not merge a PR mid-check. This new commit can also change the
PR's mergeable state (e.g. a conflict introduced by something else merged to
`main` since Step 2's check), so re-verify before moving on:

```bash
gh pr view <number> --json mergeable,mergeStateStatus
```

If it's no longer cleanly mergeable, stop and resolve the conflict before
proceeding to Step 7.

### Step 7: Switch to main, pull, and verify a clean local state

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

### Step 8: Merge the PR

```bash
gh pr merge <number> --merge --delete-branch
```

This uses a regular merge (not squash) to preserve the milestone branch's
squash-merged commit history. The `--delete-branch` flag removes the remote
milestone branch. Step 6's release-notes-deletion commit merges in along
with everything else — it is part of this PR, not a separate action.

### Step 9: Pull the merge commit

```bash
git pull origin main
```

### Step 10: Delete the local milestone branch (if it still exists)

```bash
git branch -d milestone/<version> 2>/dev/null
```

### Step 11: Create annotated tag on the merge commit

The merge commit (just pulled in Step 9) already includes Step 6's
release-notes deletion — nothing is added to `main` after this point, so the
tag lands exactly on the PR's merge commit with no extra housekeeping commit
trailing it.

```bash
git tag -a v<version> -m "Release v<version>: <milestone title>

<Key highlights from release notes>

Full release notes: https://github.com/elan-registry/registry/releases/tag/v<version>"
```

Verify:

```bash
git describe HEAD            # expect: v<version> (no suffix)
git rev-parse v<version>^{commit}   # should match HEAD
```

### Step 12: Push the tag to origin

```bash
git push origin v<version>
```

### Step 13: Create the GitHub release (draft) on the pushed tag

Always create the release as a **draft** — it is published later, at prod
deploy time (see Step 16), not here.

```bash
gh release create v<version> \
  --title "Elan Registry v<version> — <milestone title>" \
  --notes-file /tmp/release-notes-v<version>.md \
  --verify-tag \
  --draft
```

`--verify-tag` makes `gh` use the already-pushed tag rather than auto-creating
a new one. Since the release-notes deletion is now a commit inside the PR
(Step 6) rather than a post-merge push, the merge commit pulled in Step 9 is
already the final commit — the tag (Step 11) and this release both point at
it with nothing added afterward. Always use `--verify-tag` regardless, so any
drift from this invariant fails loudly instead of silently auto-tagging the
wrong commit.

Then clean up the temp file:

```bash
rm /tmp/release-notes-v<version>.md
```

### Step 14: Close the GitHub milestone

```bash
gh api repos/elan-registry/registry/milestones/<milestone_number> \
  -X PATCH -f state=closed
```

Find the milestone number from the PR's milestone field or by listing
milestones.

### Step 15: Output summary and the deploy sheet

First the release facts:

```text
Release v<version> created
- GitHub Release (draft): <URL>
- Tag: v<version> → <merge-commit>
- Milestone: closed
```

Then render `docs/development/RELEASE_INSTRUCTIONS_TEMPLATE.md` for this
release and print the rendered block in full — this is the document the user
deploys from. Follow the template's "Rendering rules" exactly:

- Fill `<version>`, `<repo-path>`, and the host placeholders from
  `.claude.local.md` § "Deployment hosts".
- Include each `<!-- IF -->` block only when its condition holds (inputs
  gathered in Step 4); drop the markers. Fill `<migration-version>`,
  `<tables>`, script names, and the per-release lines from the release notes'
  Required Actions.
- Keep the step numbering continuous after dropping unused blocks.
- The sheet deploys the tag (`'<version>^{commit}:main'`), never the current
  `main`. If `main` has moved past the tag, note it above step 1 as
  information only — the commands do not change.

**Do not commit or save the rendered sheet anywhere in the repo** — it names
the ssh alias and docroots. Print it to the terminal only.

### Step 16: Publish the release at prod deploy time (manual, later)

The release stays a **draft** (not publicly visible) until the prod push
happens. When the user actually runs `git push prod v<version>` /
`git push prod 'v<version>^{commit}:main'`, that's the trigger to publish it:

```bash
gh release edit v<version> --draft=false --repo elan-registry/registry
```

This command does not run this step itself — deployment is a separate manual
action the user performs later, per Step 15's reminder. Surface this as part
of the deploy instructions, not as something executed now.

## Important

- **Confirmation is required** before merging (step 5). Do not proceed
  without explicit user approval.
- If any step fails, stop immediately and report the error. Do not continue
  with partial state.
- This command assumes `/finish-milestone` has already been run (PR exists,
  release notes finalized, issues closed).
- The `--delete-branch` flag on `gh pr merge` handles remote-branch cleanup.
  Step 10 handles local cleanup.
- **Do NOT push to `test` or `prod` remotes** — deployment is a separate
  manual step. Only push to `origin`.
- The VERSION file is auto-generated by server-side post-receive hooks — do
  not create or edit it locally.
- **The release-notes-file deletion MUST be a commit inside the milestone
  PR, never a direct push to `main` after merging.** Step 6 pushes that
  deletion to the still-open milestone branch — updating the PR with one
  more reviewable commit — before Step 8 ever merges it. The old approach
  (delete-and-push-to-main after merge) put an unreviewed, un-PR'd commit
  directly on `main`, and left the tag pointing at a commit the milestone PR
  never actually contained. With Step 6 done first, the PR's merge commit
  (pulled in Step 9) already includes everything — nothing is added to
  `main` after the merge, so the tag (Step 11) and the release genuinely
  represent the complete, reviewed content of the PR.
- **Required ordering: PR-branch cleanup commit → merge → tag → GitHub
  release.** Do NOT call `gh release create` before the tag has been pushed.
  `gh release create` auto-tags at the current HEAD if no matching tag
  exists — since nothing is added to `main` after the merge commit anymore,
  this failure mode would land the tag on the same commit either way, but
  `--verify-tag` on Step 13's `gh release create` still guards against any
  drift from this sequence failing loudly instead of silently.
- **Tag must point to the PR's merge commit** — no trailing housekeeping
  commit — so `git describe` returns a clean `v<version>` with no
  `-N-g<hash>` suffix on test/prod, and so the tagged commit is provably
  identical to what the milestone PR contained when it merged.
- **Delete the release notes file inside the PR** (Step 6), not after
  merging. `docs/releases/` holds only the current milestone's working
  draft; GitHub Releases is the canonical archive. Step 6 stages a copy to
  `/tmp/` so the release in Step 13 still has the notes content after the
  file is gone from the repo.
- **Local `main` must equal `origin/main` before merging** (Step 7 check).
  Stray local commits — even legitimate fixes — must not ride along with the
  milestone merge. Park them on a side branch and open a separate PR.
- **The GitHub release is always created as a draft** (Step 13) and stays
  private until the user actually deploys to production. Publish it via
  `gh release edit v<version> --draft=false --repo elan-registry/registry`
  at that time (Step 16) — do not publish it as part of this command's own
  run, since deployment (including prod) is a separate manual step this
  command never performs.
