---
description: Begin work on a milestone by creating a milestone branch and drafting release notes
model: opus
---

# Start Milestone

Keep output brief — terse status lines, no preamble, no restating of steps.

## Step 0: Initialize TaskList

Create one tracking task per major step below using TaskCreate (branch
creation, fix-script cleanup, issue quality review, issue ordering,
release-notes draft, commit and push, output). Set to
`in_progress`/`completed` as you progress.

Begin work on a milestone by creating a milestone branch from main, drafting
release notes, and recommending an issue order.

## Arguments

- `$ARGUMENTS` — the milestone version number (e.g., `v2.17.0`)

## Workflow

### Step 1: Validate the milestone exists on GitHub

```bash
gh api "repos/elan-registry/registry/milestones?state=open&per_page=100" --paginate \
  --jq '.[] | select(.title | test("^$ARGUMENTS([: ]|$)")) | {number, title, description}'
```

A milestone title can have a suffix after the version, for example
`v2.31.0: Reachable Owners and Findable Cars`.

- **One result** → record the number as `<NUMBER>`, the full title as
  `<MILESTONE_TITLE>`, and the description. Later steps use them.
- **No result** → stop. Show the open milestone titles. Tell the user: "Type
  `/plan-milestone $ARGUMENTS`. It offers to create the milestone and seals
  its issue list."
- **More than one result** → stop. Show the titles. Tell the user: "Rename
  or close the extra milestone on GitHub, then type
  `/start-milestone $ARGUMENTS`."

```bash
gh api "repos/elan-registry/registry/milestones?state=open&per_page=100" --paginate --jq '.[].title'
```

### Step 2: Ensure clean working tree

```bash
git status --porcelain
```

If there are uncommitted changes, stop. Tell the user: "Commit or stash the
changes, then type `/start-milestone $ARGUMENTS`."

### Step 3: Create the milestone branch from main

This repo may be checked out in more than one local clone sharing the same
`origin` (e.g. `Registry/` and `Registry2/`, used to work two milestones in
parallel without UserSpice's gitignored `users/` framework breaking git
worktrees). A local `git branch --list 'milestone/*'` only sees branches in
*this* clone, so it can silently miss a milestone branch already active in a
sibling clone. Check for THIS milestone first, locally and on `origin`:

```bash
scripts/find-milestone-branch.sh $ARGUMENTS
```

- **Exit 0** — a `milestone/$ARGUMENTS` branch already exists (locally, on
  `origin`, or both — the script prints the branch name either way). Don't
  create a duplicate — check it out locally instead (do not check out a
  `milestone/*` branch as a `git worktree` of another local clone; get it
  from `origin` directly):

  ```bash
  git fetch origin milestone/$ARGUMENTS
  git checkout -b milestone/$ARGUMENTS origin/milestone/$ARGUMENTS
  ```

  Skip the `checkout -b` line if the branch already exists locally too —
  just `git checkout milestone/$ARGUMENTS` and pull.

- **Exit 1** — no branch for this exact milestone exists yet. Check whether
  a DIFFERENT milestone branch is active elsewhere (possibly in a sibling
  clone):

  ```bash
  git ls-remote --heads origin 'milestone/*'
  ```

  If this returns any `milestone/*` branch, that's another milestone
  already active elsewhere. Working two milestones in parallel across
  separate clones is supported — confirm with the user that a second
  parallel milestone is intended before proceeding; don't create it
  silently and don't treat the existing branch as an automatic block.

  Otherwise (no `milestone/*` branch at all on `origin`), create it fresh
  from `main`:

  ```bash
  git checkout main
  git pull origin main
  git checkout -b milestone/$ARGUMENTS
  git push -u origin milestone/$ARGUMENTS
  ```

- **Exit 2** — usage error. Stop. Tell the user: "Type
  `/start-milestone <version>`, for example `/start-milestone v2.17.0`."

### Step 3.5: Clean up fix scripts from the previous release

List remaining fix scripts (excludes `_TEMPLATE_Fix-Script.php`), each with
its first-commit date and message:

```bash
scripts/list-fix-scripts.sh
```

No output → skip silently, continue to Step 4.

Found scripts → ask the developer to classify each one (this call is
per-script human judgment, not something a script can decide — "confirmed
ran on production?" needs a real answer, not an inference):

- **Confirmed ran on production** → delete it; git history is the permanent
  record (see `docs/development/FIX_SCRIPTS.md`)
- **Promote to maintenance** (safe to re-run after future releases) →
  move to `app/admin/scripts/maintenance/`
- **Not yet confirmed / hold** → leave in place; note why

Apply the decided action with the same script — it runs `git rm` or
`git mv` so the change is staged automatically:

```bash
scripts/list-fix-scripts.sh --apply app/admin/scripts/fix/NN-Script.php delete
scripts/list-fix-scripts.sh --apply app/admin/scripts/fix/NN-Script.php promote
```

Exit 0 means the action completed. Exit 2 is a usage error (bad action,
file not found, or file outside `app/admin/scripts/fix/`). Exit 3 means the
underlying `git rm`/`git mv` itself failed — check the error and retry.

If any files were removed or moved, commit them as the first commit on the new
milestone branch:

```bash
git commit -m "chore: remove completed fix scripts from vX.Y.Z"
```

Skip the commit if nothing changed. Step 6.5 pushes this commit.

### Step 4: List the milestone's open issues

```bash
gh api "repos/elan-registry/registry/issues?milestone=<NUMBER>&state=open&per_page=100" --paginate \
  --jq '.[] | select(.pull_request == null) | {number, title, labels: [.labels[].name], body}'
```

Use the direct API call, not `gh issue list --milestone` (see CLAUDE.md's
`gh` gotchas). Use the API result as the authoritative issue list.

Then check whether `/plan-milestone` sealed this milestone. Its Step 4
leaves two marks: the milestone description is the theme sentence, and every
open issue in the milestone has `status:ready`. Count the open issues
without that label:

```bash
gh api "repos/elan-registry/registry/issues?milestone=<NUMBER>&state=open&per_page=100" --paginate \
  --jq '.[] | select(.pull_request == null) | select([.labels[].name] | index("status:ready") | not) | .number' \
  | wc -l
```

The milestone is sealed when all of these are true:

- The milestone has at least one open issue.
- The count is `0`.
- The description from Step 1 is one sentence that names an audience and
  an outcome. A category name, an issue list, or an empty description does
  not count.

**Sealed** → print "Sealed by /plan-milestone. Skipping gate." Skip Steps
4.4 and 4.5. The description is the theme sentence. Continue to Step 4.6.

**Not sealed** → continue to Step 4.4.

### Step 4.4: State the milestone theme

Before reviewing the issues, state what this release is *for*, in one sentence
naming an audience and an outcome:

> "An owner can manage their own car photos without emailing an admin."
>
> "A visitor arriving from a search engine lands on something that makes
> sense."

Not a category. "Photo improvements" has no audience, no outcome and no finish
line — and a milestone without a finish line drifts until the list is empty
rather than until the work is done.

`/plan-milestone` did not run, so it did not read the last retrospective.
Read the newest one here, if one exists:

```bash
find docs/plans/releases -name '*-retro.md' 2>/dev/null | sort -V | tail -1
```

`/finish-milestone` Step 6.5 writes this file. It holds three answers, in
this order: what we shipped that nobody needed, what we learned about the
theme's audience, and which signal we ignored. Show all three answers before
you ask for the theme. Step 4.5 uses the first answer. No file → say "No
retrospective found." and continue.

Ask the user:

> "State this milestone's theme in one sentence — who it's for, and what they
> can do afterwards that they can't do now. I'll test every issue against it."

Record the answer. It becomes the yardstick for Step 4.5 and the release
criterion: **the milestone ships when the theme sentence is true, not when
the issue list is empty.**

Then make it the milestone description on GitHub:

```bash
gh api repos/elan-registry/registry/milestones/<NUMBER> -X PATCH \
  -f description="<theme sentence>"
```

### Step 4.5: Issue quality review

This is the lighter-weight fallback of `/plan-milestone` Step 3's gate, for a
milestone that reached `/start-milestone` without `/plan-milestone` sealing
it first. Run the same gate here: the three questions (who noticed? / what
do they do today instead? / what breaks if this never ships?), the edge-case
test, and the inclusion question ("which of these serve the theme?" —
default is out, not in). If the retrospective from Step 4.4 names work that
nobody needed, an issue of the same kind must show a stronger signal to
stay. A `signal:defect` issue that a user can see (an owner, a visitor, or
an admin or editor in the site UI) stays in without the theme test, and
counts toward the theme issues. Show it to the user with the signal
`signal:defect (user-visible)`. Cap: **3–6 theme issues, plus at most one
housekeeping (`signal:forced`) issue, plus every open `gate-critical` issue**
(uncapped, bypasses the theme test).

Deltas from `/plan-milestone` Step 3, since this gate runs after issues
already have implementation detail to judge:

- Also flag: **make-work** (no real value, cosmetic-only), **trivial tests**
  (delegation/passthrough only, no realistic failure mode), **already
  superseded** (resolved by other recent work), **duplicate scope** (two
  issues, same root problem).
- Also produce a **consolidation candidates** list: issue groups touching the
  same 1–2 files, small enough that separate PRs add overhead without
  benefit. Recommendation only.

**If either gate's criteria change, update both** — a milestone that skipped
`/plan-milestone` should not get a meaningfully different bar.

Output format:

```text
## Issue Quality Review

### Flag for potential closure
| # | Title | Reason |
|---|-------|--------|
| #NNN | ... | one sentence |

### Consolidation candidates
- #NNN + #NNN: both touch [file], small scope, natural pair
- (none)
```

Ask inclusion first:

> "Which of these serve the theme? List the numbers. Anything you don't list
> comes out of the milestone — deferred, not closed."

Defer (leaves the issue open, out of the milestone):

```bash
gh issue edit NNN --repo elan-registry/registry --remove-milestone
```

Close (fails all three planning questions outright):

```bash
gh issue close NNN --repo elan-registry/registry \
  --comment "Closing as low-value / make-work during milestone planning. Can be reopened if prioritized."
```

Remove closed/deferred issues from the working list before proceeding.

If more than six theme issues survive, ask the user which six take priority;
the remainder return to the backlog.

If consolidation candidates exist, ask second:

> "Which consolidation groups (if any) should I merge into a single issue? List
> the group numbers (e.g., '1, 3') or press Enter to keep all as separate issues."

For each accepted group: pick the primary issue (more complete scope, or
lower number if unclear — ask the user if not obvious), fold the secondary
issue's scope into its body, then close the secondary with a linking
comment:

```bash
gh issue close NNN --repo elan-registry/registry \
  --comment "Consolidated into #PRIMARY — scope merged there."
```

Remove secondary issues from the working list. The primary carries the full
combined scope into Step 5.

Then scope each issue that stays. `/start-issue` needs acceptance criteria
and `status:ready` on each issue. Apply `/plan-milestone` Step 4, items 1 to
4, to each issue in the working list:

1. Write the acceptance criteria.
2. Add them to the issue body as an `## Acceptance criteria` section.
3. Give the title its scoped type, apply `status:ready`, and remove
   `triage`. The issue is already in the milestone, so leave out
   `--milestone`:

   ```bash
   gh issue edit NNN --repo elan-registry/registry --title "<type>: <description>" \
     --add-label "status:ready" --remove-label "triage"
   ```

4. For an issue scoped down to the guard only, post the scope comment.

Skip an issue that already has an `## Acceptance criteria` section that
matches the theme and `status:ready`.

### Step 4.6: Offer a production data refresh

Decide from the milestone's **content**, not the calendar, whether development
on these issues would be more reliable against fresh production data. Weigh the
issue list gathered in Step 4:

**Suggest a refresh when the milestone involves:**

- Schema or migration work — labels `component: database`, or any issue adding
  a Phinx migration
- Bulk data manipulation, merges, dedup, or repair scripts
- Image handling — label `component: images` (needs real `userimages/` files
  and the JSON `cars.image` shapes production actually contains)
- Admin tooling over real records — label `component: admin`
- Large refactors of data-access code — `refactor` or `tech-debt` touching
  `usersc/classes/`, where stale or thin local data hides breakage
- Anything whose acceptance criteria depend on realistic row counts,
  distributions, or edge-case records

**Skip silently when** the milestone is documentation, CI/workflow, styling, or
copy work — fresh data changes nothing there. Do not prompt; continue to Step 5.

When it is warranted, state the reason and ask:

> "This milestone touches <reason — e.g. schema changes in #NNN and image
> handling in #NNN>. Development will be more reliable against current
> production data. Refresh the local database now? (~N minutes)"

If the user declines, continue to Step 5 — do not re-ask.

If the user agrees, run it with this checkout's Docker stack up
(`docker compose up -d --wait`):

```bash
./scripts/refresh-local-db.sh --fetch
```

The script backs up the local database to `db-backups/` first, imports the
registry tables, masks every email address to `dev.owner.{id}@elanregistry.local`,
and verifies the masking before reporting success. Add `--skip-images` if only
the database is needed; see `scripts/README.md` for the full option list.

If the refresh fails, report the error and ask whether to continue the
milestone with the existing local data rather than blocking — starting the
milestone does not depend on it.

### Step 5: Recommend an issue order

This order is advice for this session. No file stores it. After each
merge, `/finish-issue` names the next open issue: the lowest-numbered open
issue in the milestone without `status:blocked`.

Launch the **senior-product-manager** agent to analyze all issues and
determine the best sequence. Consider:

- **Dependencies** — issues that other issues depend on should come first
  (e.g., a schema change before a feature that uses it)
- **Severity** — CRITICAL before HIGH before MEDIUM before LOW
- **Shared code paths** — group issues that touch the same files to minimize
  merge conflicts
- **Foundation first** — infrastructure/config changes before
  application-level changes
- **Architecture impact** — issues that change architecture docs should note
  which wiki pages will need updating
- **Consolidations already resolved** — secondary issues were closed in Step
  4.5; the primary issue carries the full merged scope and appears as a normal
  single entry in the sequence

Synthesize agent recommendations into a numbered list with a brief rationale
for each position. Flag any issues that will likely require
wiki/architecture document updates. Also list:

- **Order conflicts** — an issue that must wait for a higher-numbered open
  issue. The lowest-numbered rule would pick it too early.
- **Combine groups** — issues that touch the same code and must land as one
  PR. `/plan-milestone` Step 4 may already have posted these. Read the
  issue comments for the phrase `Combine into one PR with`.

Ask the user to approve the order:

> "Approve this issue order? Reply yes to continue, or list changes."

After approval, record on GitHub what the order needs, so that later
sessions see it:

- For each order conflict, add `status:blocked` and a comment that names
  the blocker. Remove the label when the blocker closes.

  ```bash
  gh issue edit NNN --repo elan-registry/registry --add-label "status:blocked"
  gh issue comment NNN --repo elan-registry/registry --body "Blocked by #BLOCKER (planned in $ARGUMENTS)."
  ```

- For each new combine group, post one comment on each issue in the group.
  Use exactly this phrase, because `/start-issue` reads it. Skip an issue
  that already has the comment.

  ```bash
  gh issue comment NNN --repo elan-registry/registry --body "Combine into one PR with #A, #B (planned in $ARGUMENTS)."
  ```

  On each issue, list the other issues of the group, not the issue itself.

### Step 6: Create draft release notes

If `docs/releases/RELEASE_NOTES_$ARGUMENTS.md` already exists, do not
change it. Print "Release notes already exist at
`docs/releases/RELEASE_NOTES_$ARGUMENTS.md`. Left unchanged." Continue to
Step 6.5.

Otherwise, create a draft release notes file at
`docs/releases/RELEASE_NOTES_$ARGUMENTS.md` using the template at
`docs/development/RELEASE_NOTES_TEMPLATE.md`:

- Fill in the version and today's date
- Write a brief summary based on the milestone description
- Populate the "Issues Resolved" section with all open issues from the
  milestone (linked to GitHub using
  `https://github.com/elan-registry/registry/issues/NNN`), each entry
  prefixed with `WIP:` since none are actually resolved yet at milestone
  creation — e.g. `WIP: [#423](https://github.com/elan-registry/registry/issues/423) — Issue title`.
  `/execute-plan` fills in each issue's real Technical/User-Facing Changes
  bullet as that issue is implemented, and `/finish-issue` strips this
  issue's own `WIP:` prefix once its PR is merged and the issue closed —
  the prefix is what lets `/finish-milestone` later verify every planned
  issue actually finished, not just that the right issues are listed.
- Leave deployment instructions and verification sections as template
  placeholders — these will be filled in as issues are completed
- Remove the "Template Instructions" section below the `---` divider

Use the **technical-documentation-writer** agent if the milestone has many
issues or complex scope.

### Step 6.5: Commit and push the milestone branch

`/start-issue` stops when `git status --porcelain` prints anything. The
release notes file is tracked, not ignored, so commit it now:

```bash
git add docs/releases/RELEASE_NOTES_$ARGUMENTS.md
git commit -m "docs: draft release notes for $ARGUMENTS"
git push origin milestone/$ARGUMENTS
```

If the file did not change (the branch already had it), skip the commit and
still push. The push also sends the Step 3.5 commit, if there is one. Then
check the result:

```bash
git status --porcelain
git rev-list --count origin/milestone/$ARGUMENTS..HEAD
```

The first command must print nothing and the second must print `0`. If not,
stop and show the output. Tell the user: "Commit or push the changes shown,
then type `/start-milestone $ARGUMENTS`. Step 3 finds the existing branch
and Step 6 keeps the existing release notes."

### Step 7: Output summary

Display:

- The milestone branch name (`milestone/$ARGUMENTS`)
- How many issues were closed in the quality review (if any), or that Step 4
  found the milestone sealed and skipped the gate
- Any consolidation opportunities flagged (if not already addressed by the user)
- The approved issue order (from Step 5)
- The issues that got `status:blocked` and the combine groups commented on
  (Step 5)
- Which issues are expected to require wiki/architecture updates
- Whether Step 6 created the draft release notes at
  `docs/releases/RELEASE_NOTES_$ARGUMENTS.md` or left an existing file
  unchanged, and that Step 6.5 committed and pushed the branch

End with the next command as plain text, not a question. GitHub and the
release notes hold the state, so tell the user to run `/clear`
first and then type `/start-issue <first-issue>`. Do not start it through
the Skill tool. This is a context boundary (CLAUDE.md, "Hand-offs between
commands").

## Important

- The milestone branch is the integration point for all issue work. Individual
  issue PRs target this branch, not `main`.
- Working two milestones in parallel across separate local clones of this
  repo (e.g. `Registry/` and `Registry2/`, sharing one `origin`) is a
  supported workflow — see Step 3's `git ls-remote --heads origin` check.
  If another `milestone/*` branch already exists on `origin`, confirm with
  the user that a second parallel milestone is intended before creating one;
  don't create it silently, and don't treat the existing branch as a block.
- Do not push to `test` or `prod` remotes — this command only sets up the
  branch on GitHub (`origin`).
- Release notes are cumulative — each `/execute-plan` run adds to them as
  work progresses (`/start-issue` only plans; it doesn't touch release
  notes).
- `docs/plans/` is gitignored local scratch space, never committed (see
  `.claude/rules/planning-docs.md`). This command writes nothing there.
