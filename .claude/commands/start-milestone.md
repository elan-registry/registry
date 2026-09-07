---
description: Begin work on a milestone by creating a milestone branch and drafting release notes
model: claude-opus-5
---

# Start Milestone

Keep output brief — terse status lines, no preamble, no restating of steps.

## Step 0: Initialize TaskList

Before any other action, create one tracking task per major step below using
TaskCreate (sprint plan check, branch creation, fix-script cleanup, issue quality review, release-notes draft, issue
ordering, output). Set to `in_progress`/`completed` as you progress.

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

```bash
git checkout main
git pull origin main
git checkout -b milestone/$ARGUMENTS
git push -u origin milestone/$ARGUMENTS
```

### Step 3.5: Clean up fix scripts from the previous release

List all remaining fix scripts (excludes `_TEMPLATE_Fix-Script.php`; non-PHP
files are naturally excluded by the pattern):

```bash
find app/admin/scripts/fix/ -maxdepth 1 -name "*.php" \
  ! -name "_TEMPLATE_Fix-Script.php"
```

If the command returns no output, skip this step silently and continue to
Step 4.

If scripts are found, prompt the developer to classify each one:

- **Confirmed ran on production** → delete it; git history is the permanent
  record (see `docs/development/FIX_SCRIPTS.md`)
- **Promote to maintenance** (safe to re-run after future releases) →
  move to `app/admin/scripts/maintenance/`
- **Not yet confirmed / hold** → leave in place; note why

Use `git rm` to delete and `git mv` to promote, so both are staged
automatically:

```bash
git rm app/admin/scripts/fix/NN-Script.php
git mv app/admin/scripts/fix/NN-Script.php app/admin/scripts/maintenance/
```

If any files were removed or moved, commit them as the first commit on the new
milestone branch:

```bash
git commit -m "chore: remove completed fix scripts from vX.Y.Z"
```

Skip the commit if nothing changed.

### Step 4: List the milestone's open issues

```bash
gh issue list --milestone "<full milestone title>" --state open \
  --json number,title,labels,body
```

**Important:** `gh issue list --milestone` can silently return empty results even
when issues exist. Always verify with the direct API call:

```bash
gh api "repos/elan-registry/registry/issues?milestone=<NUMBER>&state=open&per_page=50" \
  --jq '.[] | {number, title, labels: [.labels[].name], body}'
```

Use the API result as the authoritative issue list.

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

Before ordering, analyze the full issue list inline and produce two outputs:

**A. Issues that have not earned a place** — flag any issue that meets one or
more of these criteria:

- **Off-theme**: the issue does not serve the Step 4.4 theme sentence. This is
  the primary filter, and it is not a judgement about the issue's worth — good
  work belonging to a different theme is *deferred*, not closed. Testing
  against a theme is a comparison, which is easy; testing worth in the
  abstract is not, and almost always returns "well, it's not worthless"
- **Edge case that fails gracefully**: few real owners take this path in a
  year, and not handling it degrades quietly rather than badly. Build the
  guard, not the feature — a clear error beats a code path maintained forever
  for three people
- **Make-work / no real value**: the change produces no meaningful improvement
  — purely stylistic, cosmetic renaming with no functional impact, or
  "cleaning up" something that isn't actually causing a problem
- **Trivial tests**: adds tests only for delegation, passthrough, or obvious
  behavior that has never caused a bug and has no realistic failure mode
- **Extreme edge cases**: tests or guards for scenarios that have never occurred
  in production and are not a realistic risk given the app's usage patterns
- **Already superseded**: the issue's stated problem was resolved by other
  recent work (check against recently closed issues in this milestone)
- **Duplicate scope**: two issues that address the same root problem with only
  cosmetic differences

For each flagged issue, provide: issue number, title, and a one-sentence reason.

**B. Consolidation candidates** — identify pairs or groups of issues that:

- Touch the same 1–2 files
- Are small enough that splitting them into separate PRs adds overhead without
  benefit
- Share a logical theme that makes a combined PR easier to review

For each group, list the issue numbers and explain what makes them a natural
fit together. This is a recommendation only — no action is taken.

**Output format:**

```text
## Issue Quality Review

### Flag for potential closure
| # | Title | Reason |
|---|-------|--------|
| #NNN | ... | one sentence |

(none — all issues look worthwhile)

### Consolidation candidates
- #NNN + #NNN: both touch [file], small scope, natural pair
- (none)
```

After displaying the review, ask two questions in sequence:

**Question 1 — Inclusion (the default is out, not in):**

Do not ask which issues to remove. Ask which ones earn their place:

> "Which of these serve the theme? List the numbers. Anything you don't list
> comes out of the milestone — deferred, not closed."

Making inclusion the answer rather than the default is the point of this step:
declining to make a commitment is far easier than reversing one already made,
and by the time an issue is sitting in a milestone the commitment has been
made.

**Cap the result.** A sealed milestone holds **3–6 theme issues, plus at most
one housekeeping issue, plus every open `gate-critical` issue**. The
housekeeping issue is `signal:forced` work (security advisory, dependency EOL,
platform change) that fits no theme but has to happen. `gate-critical` issues
are uncapped and do not consume the housekeeping slot — a broken CI gate makes
every other check decorative, so its repairs never wait. Both kinds bypass the
theme test entirely. If more than six theme issues survive, ask which are the
first six; the remainder return to the backlog.

**Deferring** — the normal outcome for off-theme work — clears the milestone
and leaves the issue open:

```bash
gh issue edit NNN --repo elan-registry/registry --remove-milestone
```

**Closing** is for issues that fail the three planning questions outright:
*who noticed?* / *what do they do today instead?* / *what breaks if this never
ships?* If the honest answers are "nobody", "nothing — the workaround is
fine", and "nothing", close it:

```bash
gh issue close NNN --repo elan-registry/registry \
  --comment "Closing as low-value / make-work during milestone planning. Can be reopened if prioritized."
```

After closing, remove the closed issues from the working issue list before
proceeding.

**Question 2 — Consolidations** (only ask if consolidation candidates were identified):

> "Which consolidation groups (if any) should I merge into a single issue? List
> the group numbers (e.g., '1, 3') or press Enter to keep all as separate issues."

For each accepted consolidation group:

1. **Identify the primary issue** — pick the one with the more complete scope
   or the lower number; ask the user if it's not obvious.
2. **Update the primary issue** — edit its body to incorporate the full scope
   of the secondary issue(s) (acceptance criteria, affected files, etc.).
3. **Close the secondary issue(s)** with a linking comment:

```bash
gh issue close NNN --repo elan-registry/registry \
  --comment "Consolidated into #PRIMARY — scope merged there."
```

After closing, remove the secondary issues from the working issue list. The
primary issue carries the full combined scope into Step 5.

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

If the user agrees, run:

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
- Only one milestone should be in active development at a time. If another
  `milestone/*` branch exists, warn the user.
- Do not push to `test` or `prod` remotes — this command only sets up the
  branch on GitHub (`origin`).
- Release notes are cumulative — each `/execute-plan` run adds to them as
  work progresses (`/start-issue` only plans; it doesn't touch release
  notes).
- `docs/plans/` is gitignored local scratch space, never committed (see
  `CLAUDE.md`, Planning Work). Sprint plan files are deleted once a
  milestone is released — do not treat a missing file as an error.
