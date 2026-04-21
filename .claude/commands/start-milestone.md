---
description: Begin work on a milestone by creating a milestone branch and drafting release notes
---

# Start Milestone

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

### Step 4: List the milestone's open issues

```bash
gh issue list --milestone "<full milestone title>" --state open \
  --json number,title,labels,body
```

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
