---
description: Merge a milestone PR into main, tag the release, and publish a GitHub release
model: sonnet
---

# Release Milestone

Keep output brief — terse status lines, no preamble, no restating of steps.

Merge a milestone PR into main, create an annotated tag, push to remotes, and
publish a GitHub release. This command picks up where `/review-milestone` left
off — after the milestone PR has been created, CI-reviewed, and confirmed green.

## Arguments

- `$ARGUMENTS` — (optional) the milestone version number (e.g., `v2.17.0`).
  If omitted, auto-detect from the open `milestone/*` → `main` PR.

## Workflow

### Step 0: Initialize TaskList

Create one tracking task per step using TaskCreate: find PR, verify
preconditions, check version, locate deploy sheet, confirm, run
`scripts/release-milestone.sh`, output summary. Set each to `in_progress`
then `completed`. On failure, leave it `in_progress` and surface the error.

### Step 1: Find the milestone PR

```bash
gh pr list --base main --state open \
  --json number,title,headRefName,url
```

- Filter for PRs where `headRefName` starts with `milestone/`
- If `$ARGUMENTS` is given, match against `milestone/$ARGUMENTS`
- Exactly one match → use it. Zero or multiple → stop and ask the user.
- Extract the version from the branch name (e.g., `milestone/v2.17.0` →
  `v2.17.0`) and the milestone number from the PR's milestone field.
- The milestone PR often has no milestone set (`milestone: null`). If so,
  find the number from the open milestone whose title starts with the
  version:

  ```bash
  gh api "repos/elan-registry/registry/milestones?state=open&per_page=100" \
    --jq '.[] | select(.title | test("^<version>([: ]|$)")) | .number'
  ```

  Exactly one number → use it. Zero or more than one → stop and ask the
  user. Steps 2 and 6 use this `<milestone-number>`.

### Step 2: Verify preconditions

- The PR must be mergeable (no conflicts, checks passing).
- The working tree must be clean (`git status --porcelain`).
- Must be on `main` or the milestone branch. The scope-drift check below
  switches to the milestone branch.
- **No unresolved Blocking/Important review findings.** This command does
  not fix problems — it only merges/tags/publishes what `/finish-milestone`
  and `/review-milestone` already fully vetted.

```bash
gh pr view <number> --json mergeable,mergeStateStatus,statusCheckRollup
scripts/check-blocking-findings.sh <number> --include-important
```

- **Exit 0** — clean, proceed.
- **Exit 1** — an unresolved Blocking or Important finding exists. **Stop.**
  Do not proceed to Step 5 and do not fix it here. This command's next steps
  are irreversible merge/tag/publish actions. The PR is still open. Tell the
  user to fix each finding as a commit on the milestone branch, push, wait
  for the milestone CI review to post again, and then type
  `/release-milestone <version>`. Do not send the user to `/finish-milestone`:
  it ends before a PR exists.
- **Exit 2** — can't verify: no posted review comment was found, the `gh`
  call failed, or a grep failed while it scanned the review. Treat as "can't
  verify," not "clean." Stop and investigate.

This is a second, independent check on the same requirement
`/review-milestone` Step 5 already enforces — it exists so a PR that sat open
a while, or reached this command by another path, still gets caught.

**The milestone scope must still match the release notes.** The script
reads the release notes from the working tree, and only the milestone
branch has that file (`main` does not). Check out the milestone branch and
bring it up to date first, so the check reads the notes that will merge:

```bash
git checkout milestone/<version>
git pull --ff-only origin milestone/<version>
scripts/check-milestone-scope-drift.sh <version> <milestone-number>
```

If the checkout or the pull fails, stop and report the error. Do not run
the check against a stale or wrong checkout.

- **Exit 0** — scope matches, proceed.
- **Exit 1** — the script printed one or more mismatched issues. Do not go
  to Step 3 yet. Show each line to the user. Some mismatches are intended:
  - An issue carried from a different milestone ("Carried from …" in its
    release-notes entry) shows as "moved out of milestone".
  - An issue closed as consolidated into or superseded by another issue
    (see `/finish-milestone` Step 5.5) shows as "added to milestone,
    missing from release notes".

  For each line, ask the user whether the mismatch is intended. If the user
  confirms every line, go to Step 3. Otherwise stop. Tell the user to fix
  the "Issues Resolved" section of `docs/releases/RELEASE_NOTES_<version>.md`
  on the milestone branch, commit, push, and type `/release-milestone
  <version>` again. That push makes the deploy sheet stale (Step 4 reports
  it) and does not re-run the milestone CI review.
- **Exit 1 with no issue lines printed** — treat as exit 2.
- **Exit 2** — can't verify: bad arguments, no release-notes file, no
  "Issues Resolved" entries in it, a milestone with no issues (usually a
  wrong milestone number), the `gh` call failed, or a tool failed
  mid-check. Treat as "can't verify," not "clean." Stop and investigate.

`/finish-milestone` Step 5.5 compares the same two sets by hand earlier,
with its own inline grep. The two can disagree: Step 5.5 reads every
`issues/N` link in the file, and this script reads only the leading link
of each "Issues Resolved" bullet. This check runs again here because the
milestone PR can stay open for a while after `/finish-milestone`, and an
issue's milestone assignment can change in that window.

### Step 3: Check version consistency

```bash
scripts/check-version-newer.sh <version>
```

- **Exit 0** — newer than the last tag. Proceed.
- **Exit 1** — not newer. Stop and ask the user.
- **Exit 2** — couldn't parse semver, or no prior tag. Stop and ask.

### Step 4: Locate the deploy sheet rendered by `/finish-milestone`

`/finish-milestone` Step 6.6 renders a standalone deploy sheet at
`docs/plans/releases/<version>-deploy.md` before this command runs.

```bash
ls docs/plans/releases/<version>-deploy.md
```

- **Exists** — this is the deploy sheet Step 7's summary points to. Read it
  now so Step 5 can name what it covers (migrations, new env vars, admin-script
  registration, any manual verification runbook). Do not re-render it.
- **Missing** — stop and tell the user: "No deploy sheet found at
  `docs/plans/releases/<version>-deploy.md`. `/finish-milestone` Step 6.6
  renders it. A command cannot start at one step: render the sheet by hand
  from that step's instructions, or type `/finish-milestone <version>` to
  run the whole command again." Do not render it yourself here.
- **Check staleness mechanically**, not by eyeballing `git log`:

  ```bash
  scripts/check-deploy-sheet-fresh.sh <version>
  ```

  - **Exit 0** — fresh, proceed.
  - **Exit 1** — stale. Warn the user; ask whether to proceed anyway or
    stop and refresh the sheet first (by hand from `/finish-milestone`
    Step 6.6's instructions, or by typing `/finish-milestone <version>`).
  - **Exit 2** — no stamp file — can't verify. Warn rather than assume fresh.

Confirm `.claude.local.md` § "Deployment hosts" is present — the sheet
already has it baked in, but Step 7's reminder and any ad hoc host lookup
later still need it:

```bash
grep -A3 "Deployment hosts" .claude.local.md
```

If missing, stop and ask the user to add it (copy the block from
`.claude.local.md.example`).

### Step 5: Show summary and ask for confirmation

Display:

- PR number, title, and URL
- Number of commits that will be merged
- Version that will be tagged
- Release notes file path
- Deploy sheet path and a one-line summary of what it covers (from Step 4)
- Remind: "This will merge the PR, create a tag, push to origin, and publish
  a GitHub release (draft). Deployment to test/prod is a separate manual
  step."

**Ask the user to confirm before proceeding. This is the point of no
return** — everything after this step is `scripts/release-milestone.sh`
running the merge, which cannot be undone by re-running the command.

### Step 6: Run the release script

```bash
scripts/release-milestone.sh <version> <pr-number> <milestone-number>
```

This runs, in order: remove the release-notes file as a commit on the
milestone branch and push it (so it lands inside the PR, never as a bare
push to `main` after merge); sync local `main` to `origin/main` and refuse to
proceed if local `main` carries commits `origin/main` doesn't have; merge the
PR (regular merge, `--delete-branch`); pull the merge commit; delete the
local milestone branch; tag the merge commit `<version>` and verify `git
describe HEAD` returns it with no suffix; push the tag; create the GitHub
release as a **draft** with `--verify-tag`; close the GitHub milestone.

The script refuses any remote argument named `prod` or `test` — it only ever
pushes to `origin`. Exit 0 means every step above completed. Exit 1 means a
precondition failed before anything mutated (bad args, dirty state, stray
local commits — the script's own output says which). Exit 2 means a step
failed mid-run after mutation began (merge conflict, push rejected, tag
verification failed) — **stop and investigate by hand; do not re-run the
script blindly**, since some earlier steps may have already applied.

If the script exits non-zero, report its exact output and stop — do not
attempt the remaining steps manually.

### Step 7: Output summary and point to the deploy sheet

```text
Release <version> created
- GitHub Release (draft): <URL>
- Tag: <version> → <merge-commit>
- Milestone: closed
```

The deploy sheet was already rendered by `/finish-milestone` (Step 4 read
it) — **do not re-render it here.** Tell the user it's ready at
`docs/plans/releases/<version>-deploy.md` and remind them the sheet deploys
the tag (`'<version>^{commit}:main'`), never the current `main`.

**Do not print the sheet's full contents into the conversation** — it names
the ssh alias and docroots. The user reads the file directly.

If Step 4 found the sheet stale and the user chose to proceed anyway, flag
that again here as a reminder to double-check the sheet's migration/env-var/
script list still matches what merged.

**Publishing the release** (making it public) happens later, at prod deploy
time, not by this command — see the deploy sheet's own last section. When
the user runs `git push prod <version>` / `git push prod
'<version>^{commit}:main'`, they then run:

```bash
gh release edit <version> --draft=false --repo elan-registry/registry
```

End with plain text, not a question: this milestone is done. Tell the user
to run `/clear` before the next `/plan-milestone`, because this is a
milestone boundary (CLAUDE.md, "Hand-offs between commands").

## Important

- **Confirmation is required before Step 6.** Do not run the script without
  explicit user approval — it is the point of no return.
- This command assumes `/finish-milestone` and `/review-milestone` already
  ran. It reuses the rendered deploy sheet rather than generating its own.
- **Never push to `test` or `prod` remotes.** `scripts/release-milestone.sh`
  refuses those remote names outright; this command never passes them.
- The VERSION file is auto-generated by server-side post-receive hooks — do
  not create or edit it locally.
- The release stays a **draft** until the user deploys to production —
  publishing it is a separate manual step (see Step 7).
