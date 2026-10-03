---
description: Begin work on a milestone by creating a milestone branch and drafting release notes
model: opus
---

# Start Milestone

Keep output brief — terse status lines, no preamble, no restating of steps.

## Step 0: Initialize TaskList

Create one tracking task per major step below using TaskCreate (sprint plan
check, branch creation, fix-script cleanup, issue quality review,
release-notes draft, issue ordering, output). Set to
`in_progress`/`completed` as you progress.

Begin work on a milestone by creating a milestone branch from main, drafting
release notes, and recommending an issue order.

## Arguments

- `$ARGUMENTS` — the milestone version number (e.g., `v2.17.0`)

## Workflow

### Step 1: Validate the milestone exists on GitHub

```bash
gh api repos/elan-registry/registry/milestones \
  --jq '.[] | select(.title | startswith("'"$ARGUMENTS"'"))'
```

If not found, stop and report the error. Show available open milestones:

```bash
gh api repos/elan-registry/registry/milestones --jq '.[].title'
```

Record the full milestone title and milestone number for later steps.

### Step 1.5: Check for a proposed sprint plan

Look for a sprint plan matching this milestone under `docs/plans/sprints/`:

```bash
ls docs/plans/sprints/$ARGUMENTS.md
```

- **If found**: read it. This becomes the starting point for the issue order
  in Step 5 — treat its sequence as a proposed ordering to validate, not to
  regenerate from scratch. Carry forward any rationale/context notes it
  contains (dependencies, split candidates, sequencing constraints) into
  Step 5's synthesis and into the release notes summary in Step 6.
- **If not found**: skip silently, continue to Step 2. Sprint plans are
  optional — fall back to a fully agent-generated order in Step 5.

### Step 2: Ensure clean working tree

```bash
git status --porcelain
```

If there are uncommitted changes, stop and ask the user to commit or stash
first.

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

- **Exit 2** — usage error. Check `$ARGUMENTS` was given.

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

Skip the commit if nothing changed.

### Step 4: List the milestone's open issues

```bash
gh api "repos/elan-registry/registry/issues?milestone=<NUMBER>&state=open&per_page=50" \
  --jq '.[] | {number, title, labels: [.labels[].name], body}'
```

Use the direct API call, not `gh issue list --milestone` (see CLAUDE.md's
`gh` gotchas). Use the API result as the authoritative issue list.

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

Ask the user:

> "State this milestone's theme in one sentence — who it's for, and what they
> can do afterwards that they can't do now. I'll test every issue against it."

Record the answer. It becomes the yardstick for Step 4.5 and the release
criterion: **the milestone ships when the theme sentence is true, not when
the issue list is empty.**

Then make it the milestone description on GitHub, unless `/plan-milestone`
already did (the description from Step 1 is already the theme sentence — if
it is a category name, an issue list, or empty, replace it):

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
default is out, not in). Cap: **3–6 theme issues, plus at most one
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

Launch the **senior-product-manager** agent to analyze all issues and
determine the best sequence. Consider:

- **Sprint plan proposal** — if Step 1.5 found a sprint plan, pass its
  proposed sequence and rationale to the agent as a starting point. The agent
  should validate it against current issue state (closures/consolidations
  from Step 4.5 may have changed the picture) and flag any deviation it
  recommends, rather than ignore it.
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
for each position. If this order differs from the sprint plan's proposed
sequence, call out what changed and why. Flag any issues that will likely
require wiki/architecture document updates.

Ask the user to approve the order:

> "Approve this issue order? Reply yes to continue, or list changes."

If a sprint plan file exists (Step 1.5), once the user approves the final
order, update `docs/plans/sprints/$ARGUMENTS.md` in place so its sequence line
matches the approved order (same format the file already uses, e.g.
`**#NNN → #NNN → ...**`). There is nothing to commit — `docs/plans/` is
gitignored local scratch space. Do not touch
`docs/plans/sprints/README.md` — it is only removed/updated when the milestone is
released, not here.

### Step 6: Create draft release notes

Create a draft release notes file at
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

### Step 7: Output summary

Display:

- The milestone branch name (`milestone/$ARGUMENTS`)
- Whether a sprint plan was found at `docs/plans/sprints/$ARGUMENTS.md` and used to
  seed the order
- How many issues were closed in the quality review (if any)
- Any consolidation opportunities flagged (if not already addressed by the user)
- The approved issue order (from step 5)
- Whether `docs/plans/sprints/$ARGUMENTS.md` was updated to match (if applicable)
- Which issues are expected to require wiki/architecture updates
- Note that draft release notes were created at
  `docs/releases/RELEASE_NOTES_$ARGUMENTS.md`
- Instructions: "Use `/start-issue <number>` to plan the first issue, then
  `/execute-plan` to implement it once the plan is approved"

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
  `.claude/rules/planning-docs.md`). Sprint plan files are deleted once a
  milestone is released — do not treat a missing file as an error.
