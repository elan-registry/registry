---
description: Begin work on a milestone by creating a milestone branch and drafting release notes
model: claude-sonnet-4-6
---

# Start Milestone

## Step 0: Initialize TaskList

Before any other action, create one tracking task per major step below using
TaskCreate (branch creation, fix-script cleanup, issue quality review, release-notes draft, issue
ordering, output). Set to `in_progress`/`completed` as you progress.

Begin work on a milestone by creating a milestone branch from main, drafting
release notes, and recommending an issue order.

## Arguments

- `$ARGUMENTS` — the milestone version number (e.g., `v2.17.0`)

## Workflow

### Step 1: Validate the milestone exists on GitHub

```bash
gh api repos/unibrain1/elanregistry/milestones \
  --jq '.[] | select(.title | startswith("'"$ARGUMENTS"'"))'
```

If not found, stop and report the error. Show available open milestones:

```bash
gh api repos/unibrain1/elanregistry/milestones --jq '.[].title'
```

Record the full milestone title and milestone number for later steps.

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

List all unarchived fix scripts (excludes `_TEMPLATE_Fix-Script.php`; the
`_ARCHIVE/` subdirectory and non-PHP files are naturally excluded by the
pattern):

```bash
find app/admin/scripts/fix/ -maxdepth 1 -name "*.php" \
  ! -name "_TEMPLATE_Fix-Script.php"
```

If the command returns no output, skip this step silently and continue to
Step 4.

If scripts are found, prompt the developer to classify each one:

- **Confirmed ran on production** → move to `app/admin/scripts/fix/_ARCHIVE/`
- **Promote to maintenance** (safe to re-run after future releases) →
  move to `app/admin/scripts/maintenance/`
- **Not yet confirmed / hold** → leave in place; note why

Use `git mv` to move files so the renames are staged automatically:

```bash
git mv app/admin/scripts/fix/NN-Script.php app/admin/scripts/fix/_ARCHIVE/
```

If any files were moved, commit them as the first commit on the new milestone
branch:

```bash
git commit -m "chore: archive fix scripts from vX.Y.Z"
```

Skip the commit if no files were moved.

### Step 4: List the milestone's open issues

```bash
gh issue list --milestone "<full milestone title>" --state open \
  --json number,title,labels,body
```

**Important:** `gh issue list --milestone` can silently return empty results even
when issues exist. Always verify with the direct API call:

```bash
gh api "repos/unibrain1/elanregistry/issues?milestone=<NUMBER>&state=open&per_page=50" \
  --jq '.[] | {number, title, labels: [.labels[].name], body}'
```

Use the API result as the authoritative issue list.

### Step 4.5: Issue quality review

Before ordering, analyze the full issue list inline and produce two outputs:

**A. Issues to consider closing** — flag any issue that meets one or more of
these criteria:

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

**Question 1 — Closures:**

> "Which of the flagged issues (if any) should I close? List numbers separated
> by commas, or press Enter to keep all and continue."

If the user provides issue numbers to close, close each one on GitHub:

```bash
gh issue close NNN --repo unibrain1/elanregistry \
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
gh issue close NNN --repo unibrain1/elanregistry \
  --comment "Consolidated into #PRIMARY — scope merged there."
```

After closing, remove the secondary issues from the working issue list. The
primary issue carries the full combined scope into Step 5.

### Step 5: Recommend an issue order

Launch the **senior-product-manager** and **senior-architect** agents in
parallel to analyze all issues and determine the best sequence. Consider:

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
for each position. Flag any issues that will likely require wiki/architecture
document updates.

### Step 6: Create draft release notes

Create a draft release notes file at
`docs/releases/RELEASE_NOTES_v$ARGUMENTS.md` using the template at
`docs/development/RELEASE_NOTES_TEMPLATE.md`:

- Fill in the version and today's date
- Write a brief summary based on the milestone description
- Populate the "Issues Resolved" section with all open issues from the
  milestone (linked to GitHub using
  `https://github.com/unibrain1/elanregistry/issues/NNN`)
- Leave deployment instructions and verification sections as template
  placeholders — these will be filled in as issues are completed
- Remove the "Template Instructions" section below the `---` divider

Use the **technical-documentation-writer** agent if the milestone has many
issues or complex scope.

### Step 7: Output summary

Display:

- The milestone branch name (`milestone/$ARGUMENTS`)
- How many issues were closed in the quality review (if any)
- Any consolidation opportunities flagged (if not already addressed by the user)
- The recommended issue order (from step 5)
- Which issues are expected to require wiki/architecture updates
- Note that draft release notes were created at
  `docs/releases/RELEASE_NOTES_v$ARGUMENTS.md`
- Instructions: "Use `/start-issue <number>` to begin work on the first issue"

## Important

- The milestone branch is the integration point for all issue work. Individual
  issue PRs target this branch, not `main`.
- Only one milestone should be in active development at a time. If another
  `milestone/*` branch exists, warn the user.
- Do not push to `test` or `prod` remotes — this command only sets up the
  branch on GitHub (`origin`).
- Release notes are cumulative — each `/start-issue` adds to them as work
  progresses.
