---
description: Monitor CI, squash-merge an issue PR into the milestone branch, and close the issue
model: sonnet
---

# Finish Issue

Keep output brief — terse status lines, no preamble, no restating of steps.

## Step 0: Initialize TaskList

Before any other action, create one tracking task per major step below using
TaskCreate. Set to `in_progress` when starting each, `completed` on success.
This is a CI-polling workflow that can take 10+ minutes — visible progress
matters.

Monitor a PR's CI checks, then squash-merge into the milestone branch, close
the issue, delete the branch, and return to the milestone branch.

## Arguments

- `$ARGUMENTS` — the GitHub issue number (e.g., `423`). If omitted, infer
  from the current branch name (e.g., `issue/423-car-data-export` → `423`,
  `bug/512-negative-price` → `512`, `feature/423-export` → `423`).

## Workflow

### Step 1: Determine the issue number and PR

If no argument is provided, extract the issue number from the current branch
name:

```bash
git branch --show-current
```

The branch must match `issue/<number>-*`, `bug/<number>-*`, or
`feature/<number>-*`. If it doesn't, stop and ask the user for the issue
number.

Find the open PR for this issue branch:

```bash
gh pr list --head "$(git branch --show-current)" --state open \
  --json number,title,url,baseRefName,statusCheckRollup
```

If no PR exists, stop and tell the user to run `/commit-push-pr` first.

### Step 2: Identify the target (base) branch

The PR's `baseRefName` should be a `milestone/*` branch. Record it — this is
where we'll return after merging.

If the PR targets `main` instead of a milestone branch, **warn the user** —
issue PRs should always target the milestone branch per the git workflow. Ask
if they want to proceed or retarget the PR.

### Step 2.5: Handle draft PRs — trigger review, then mark ready

Check if the PR is a draft:

```bash
gh pr view <pr-number> --json isDraft --repo elan-registry/registry -q .isDraft
```

**Whether draft or already ready** (an already-ready PR skipped this step
once before — run it anyway; do not assume a prior pass happened), trigger
and verify the review, then act on the result:

```bash
scripts/verify-ci-review.sh <pr-number> 30 300 --trigger=workflow
```

- **Exit 0** — comment confirmed, no unresolved Blocking finding. Mark the PR
  ready: `gh pr ready <pr-number> --repo elan-registry/registry`. This is the
  moment watchers are notified — proceed straight to Step 3.
- **Exit 1** — could not verify (auth/network/rate-limit). Report the error;
  do not mark ready and do not treat this as "no review."
- **Exit 2** — comment confirmed but an unresolved **Blocking** finding
  exists. Report it; stop here and tell the user to fix it before proceeding.
- **Exit 4** — no comment posted, even after the script's one recovery
  attempt (or recovery couldn't apply — see its stderr, e.g. the
  self-referential-workflow-file case). Report to the user and do not mark
  the PR ready.

See `scripts/verify-ci-review.sh`'s header for the full exit-code contract
and why job success alone is never proof of a posted review (#1724).

### Step 3: Monitor CI checks

Poll the PR's check status until all checks complete (pass or fail):

```bash
gh pr checks <pr-number> --watch --fail-fast
```

If `--watch` is not available, poll manually:

```bash
gh pr checks <pr-number>
```

Wait 30 seconds between polls. Maximum 20 attempts (10 minutes). If checks
are still pending after 10 minutes, report status and ask the user whether to
keep waiting.

**Expected CI checks** (see DEPLOYMENT.md for details):

- CodeQL Analysis — security scanning
- GitGuardian Security — secret detection
- Claude Code Review — coding standards
- PHPUnit Unit + Regression — behavioral test suite (`tests.yml`, added in #1437; not yet a
  GitHub-required check, so `gh pr checks` is what actually surfaces its status here — this
  step's polling already does that regardless of this list)

### Step 4: Handle check results

**If all checks pass** → run the PHPStan baseline hygiene check (Step 4.5)
first, then report results to the user and **ask for explicit confirmation
before merging**: "All CI checks passed. Ready to squash-merge PR #NNN into
`MILESTONE_BRANCH` and close issue #NNN. Shall I proceed?"
Do NOT merge until the user confirms.

**If any check fails:**

- List which checks failed
- For each failed check, fetch the logs:

  ```bash
  gh run view <run-id> --log-failed
  ```

- Analyze the failure logs and report:
  - Which check failed and why
  - The relevant error messages
  - A suggested fix or next step
- **Stop here.** Do not merge. Tell the user to fix the issue, push the fix,
  and re-run `/finish-issue` when ready.

### Step 4.5: Verify PHPStan baseline hygiene

Per the fix-when-you-touch-it policy
(`docs/development/CODING_STANDARDS.md` — PHPStan Baseline Hygiene):

```bash
gh pr view <pr-number> --repo elan-registry/registry --json files --jq '.files[].path' \
  | scripts/check-baseline-hygiene.sh
```

- **Exit 0, no output** — clean. Proceed to Step 4.6.
- **Exit 0, `BASELINE OVERRIDE: <file>` lines** — stop before merging. Report
  the file(s); either fix the errors and re-run `composer phpstan:baseline`,
  or get the user's explicit confirmation the pre-existing entry may carry
  over untouched. Do not merge until resolved.
- **Exit 2** — could not run the check at all (not "clean"). Fix the working
  directory and re-run.

### Step 4.6: Documentation drift check

Run before merging, once CI is green.

```bash
composer check:docs
```

That catches structural rot — dead links, stale indexes, ADR drift, dropped
tables, removed symbols. It does **not** catch a doc that describes behaviour
the code never had, so also check what this diff could have falsified:

```bash
gh pr diff <pr-number> --name-only
```

| If the diff touched | Check |
| --- | --- |
| `usersc/classes/**` | `docs/development/CLASSES.md` — do the documented classes, paths and signatures still match? |
| `database/migrations/**` | `docs/development/DATABASE.md` — tables, columns, triggers |
| `composer.json` / `package.json` scripts | `CLAUDE.md` Quick Start Commands, `docs/development/QUICK_REFERENCE.md` |
| `app/api/**` | Endpoint references in `ERROR_HANDLING.md`, `DATATABLES.md`, `SYSTEM_OVERVIEW.md` |
| `app/admin/**` or permission guards | `SYSTEM_OVERVIEW.md` §3, `Page-Security-and-Access-Control` on the wiki |
| Anything user-visible | `docs/guides/`, `docs/reference/` — these are read by car owners |
| A capability added, removed, or newly gated | `SYSTEM_OVERVIEW.md` §6 (deliberately not built) and §7 (built but broken) |

**The trigger is the diff, not a judgment call about significance.** Every
serious documentation defect found in the August 2026 audit was a doc
contradicting code that a merged PR had just changed — a dropped table, a
deleted function, a removed endpoint. Each was mechanically detectable from the
diff; none was caught, because nothing looked.

If a doc needs updating, update it in this PR rather than filing a follow-up.
A doc fix that lands separately from the change it describes is a doc fix that
usually does not land.

**Wiki pages are a separate repository** and cannot be updated from this branch.
If the diff invalidates a wiki page, note it in the merge report so it can be
published with `/publish-wiki`.

### Step 5: Squash-merge the PR

```bash
gh pr merge <pr-number> --squash --delete-branch
```

This squash-merges into the milestone branch and deletes the issue branch
(both local and remote).

### Step 6: Close the GitHub issue

```bash
gh issue close $ARGUMENTS --comment "Resolved via PR #<pr-number>."
```

Remove the "in progress" label if present:

```bash
gh issue edit $ARGUMENTS --remove-label "in progress"
```

### Step 6.5: Tick cleanup ledger items and report open ones

The cleanup ledger is the open issue with the `cleanup-ledger` label (see
`/found`, "Ledger"). Do this step before Step 8, because Step 8 deletes the
plan file.

1. Find the ledger issue and the files that the merged PR changed:

   ```bash
   LEDGER=$(gh issue list --repo elan-registry/registry --label cleanup-ledger \
     --state open --json number --jq '.[0].number')
   gh pr view <pr-number> --json files --jq '.files[].path'
   ```

   If `LEDGER` is empty, skip this step and write "Ledger: none open" in the
   report.

2. Get the ledger comments that have a heading for one of those files:

   ```bash
   gh api "repos/elan-registry/registry/issues/$LEDGER/comments" --paginate \
     --jq '.[] | {id, body}'
   ```

   A file heading has the form ``### `path/to/file` ``. The ledger issue body
   also has file groups. Treat the body as one more source, with the same
   heading form.

3. Find the plan file with `scripts/check-plan-state.sh $ARGUMENTS`. Read its
   **Ledger items** section. For each item there that the PR did, change
   `- [ ]` to `- [x]` on the matching line. Change only those lines. Keep
   all other text the same. Write the changed body back:

   ```bash
   gh api -X PATCH "repos/elan-registry/registry/issues/comments/<comment-id>" \
     -f body="$NEW_BODY"
   # For the issue body:
   gh issue edit "$LEDGER" --repo elan-registry/registry --body-file <file>
   ```

   If the plan has no **Ledger items** section, or no plan file exists, tick
   nothing.

4. Count the items that are still `- [ ]` under a heading for a file that the
   PR changed. Do not block on them. Put them in the report.

### Step 7: Return to the milestone branch

Do this **before** any local commit below (Step 8) — `gh pr merge` in Step 5
operates via the GitHub API and does not change what's checked out locally,
so without this step first, Step 8's commit would land on the deleted issue
branch instead of the milestone branch.

```bash
git checkout <milestone-branch>
git pull origin <milestone-branch>
```

Clean up the local issue branch if it still exists:

```bash
git branch -d <issue-branch> 2>/dev/null
```

### Step 8: Update draft release notes and delete the plan file

The squash-merge in Step 5 already carried the plan file (if this issue went
through `/start-issue` → `/execute-plan`) onto the milestone branch, now
checked out per Step 7.

**Release notes:** read the draft release notes at
`docs/releases/RELEASE_NOTES_<version>.md` (where `<version>` is extracted
from the milestone branch name, e.g., `milestone/v2.17.0` → `v2.17.0`). In the
"Issues Resolved" section, find this issue's entry and strip its `WIP:`
prefix — `/start-milestone` wrote every entry with that prefix at milestone
creation, since none were resolved yet; this issue's entry is the one that's
actually done now. If the entry has no `WIP:` prefix (e.g. an ad-hoc issue
added to the milestone after `/start-milestone` ran, or a plan that predates
this convention), add the entry now instead — don't skip it.

**Plan file:** check for one on the milestone branch:

```bash
scripts/check-plan-state.sh $ARGUMENTS
```

Read the `path:` line. `(none)` means no matching file exists — skip
silently, not every issue goes through the plan-file workflow (e.g. trivial
fixes done ad hoc). Any other path means the plan file exists — delete it.
Its job (a verifiable, resumable record other agents/sessions could check
against) is done once the code is merged and the issue is closed; the merged
diff and closed issue are now the source of truth, same lifecycle as sprint
plans.

`docs/plans/` is gitignored, so this is a plain delete with no git operation
and nothing to mention in the PR:

```bash
rm -f docs/plans/issues/issue-$ARGUMENTS-*.md docs/plans/issue-$ARGUMENTS-*.md
```

Commit the release notes update:

```bash
git add docs/releases/
git commit -m "docs: mark issue #$ARGUMENTS as resolved in release notes"
git push origin <milestone-branch>
```

### Step 8.5: Mark the issue complete in the sprint plan

Mark this issue done in the sprint plan under `docs/plans/sprints/`
(gitignored local working documents — see `.claude/rules/planning-docs.md`):

```bash
scripts/mark-sprint-issue-done.sh <version> $ARGUMENTS
```

(where `<version>` is the same one used in Step 8, e.g. `v2.29.3`.)

- **Exit 0** — marked done (or was already marked done). Nothing further to do.
- **Exit 1** — no sprint file for this version. Normal — skip this step
  silently; not every milestone has one.
- **Exit 2** — sprint file exists, but issue `#$ARGUMENTS` doesn't appear in
  its sequence line (e.g. an unplanned bugfix not part of the tracked
  sprint). Make no edit. Note in the Step 9 summary that this issue wasn't
  part of the tracked sequence.

`docs/plans/` is gitignored, so this edit is a plain local file write —
there is nothing to stage or commit (same convention as the
`/start-milestone` sprint-plan update).

### Step 9: Report results

Output a summary:

- Issue #`<number>` — closed
- PR #`<pr-number>` — squash-merged into `<milestone-branch>`
- CI review status (from Step 2.5): "posted normally" / "no run was
  triggered — re-triggered, now posted" / "ran but posted nothing —
  self-referential workflow-file change" / etc. — never omit this line
- Documentation — `composer check:docs` result, and any doc updated in this PR
  (or "no doc impact"). Note any **wiki** page needing a separate
  `/publish-wiki` run.
- Ledger (from Step 6.5) — "ticked N items on #LEDGER" and, when some
  remain, "N open items in files this PR edited:" followed by one line for
  each file. Otherwise "no ledger items for these files".
- Branch `<issue-branch>` — deleted
- Release notes updated at `docs/releases/RELEASE_NOTES_<version>.md`
- Now on `<milestone-branch>`

List remaining open issues in the milestone. Use the direct API, not
`gh issue list --milestone` (see CLAUDE.md's `gh` CLI gotchas):

```bash
# Get milestone number from the milestone branch name, then query API directly
MILESTONE_TITLE=$(git branch --show-current | sed 's|.*milestone/||' || echo "<milestone title>")
MILESTONE_NUM=$(gh api "repos/elan-registry/registry/milestones" \
  --jq ".[] | select(.title | startswith(\"${MILESTONE_TITLE}\")) | .number")
gh api "repos/elan-registry/registry/issues?milestone=${MILESTONE_NUM}&state=open&per_page=20" \
  --jq '.[] | {number, title}'
```

Determine the recommended next issue:

- **If a sprint plan was found and used in Step 8.5:** walk its sequence line
  left-to-right and find the first issue number not marked with ✅. Cross-check
  it's still in the open-issues list from above (it may have been
  closed/consolidated outside this flow); if not, fall back to the next
  unmarked entry that is. If every issue in the sequence is now ✅ but other
  open issues remain (untracked by the plan), note those separately.
- **If no sprint plan was found/used, or the finished issue wasn't in its
  sequence:** the recommended next issue is just the next open one from the
  API list above, if any.

Ask via AskUserQuestion, not a plain-text menu — "Issue #$ARGUMENTS closed.
What next?" Options: `Run /start-issue <next-issue>` (only if one was
identified; label it "next in sprint plan sequence" when that's why),
`Run /finish-milestone $ARGUMENTS` (only if no open issues remain),
`Compact context first` (state is already saved, safe to compact),
`Ask more questions / discuss first`. Invoke a chosen command immediately
via the Skill tool. If the user picks compacting, tell them to run
`/compact` themselves — this command can't trigger it. For the discuss
option, drop into normal conversation and don't re-offer until asked.

## Important

- **Never trust a completed CI review run without confirming its comment
  posted** — Step 2.5's `verify-ci-review.sh` checks the comment itself, not
  job status (see its header, and #1724).
- **Never force-merge if checks are failing.** Always investigate and report
  first.
- **Never merge with new PHPStan baseline entries on touched files** — Step
  4.5 checks for this explicitly.
- The squash merge keeps the milestone branch history clean — one commit per
  issue.
- If the PR targets `main` instead of a milestone branch, warn the user.
  Issue PRs should always target the milestone branch.
- If the local branch can't be deleted (e.g., you're still on it), switch to
  the milestone branch first.
- This command closes the issue directly. The `Closes #NNN` keyword in the
  milestone PR body (created by `/review-milestone`) serves as a backup for
  any issues that weren't closed here.
- `docs/plans/` is gitignored local scratch space, never committed (see
  `.claude/rules/planning-docs.md`). Sprint plan files are deleted once a
  milestone is released — a missing file is normal, not an error.
