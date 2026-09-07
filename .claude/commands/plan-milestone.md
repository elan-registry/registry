---
description: Signal review, theme selection, and gate — seal a milestone's issue list before branching
model: claude-opus-5
---

# Plan Milestone

Keep output brief — terse status lines, no preamble, no restating of steps.

The planning session from `docs/development/ISSUE_WORKFLOW.md` §2. One
sitting, roughly 30 minutes. Produces a sealed milestone (theme description +
gated issue list, each with acceptance criteria written and `status:ready`
applied) that `/start-milestone` then branches from.

This command does not create a branch, touch release notes, or write any
code — it only decides what's in. Run `/start-milestone` after this to begin
building.

## Arguments

- `$ARGUMENTS` — the milestone version number (e.g., `v2.17.0`). If the
  milestone doesn't exist yet on GitHub, create it first:
  `gh api repos/elan-registry/registry/milestones -f title="$ARGUMENTS"`

## Step 0: Initialize TaskList

Create one tracking task per step below (signal review, theme, gate,
seal, output) via TaskCreate.

## Step 1: Read the signals

Pull everything that's arrived since the last planning session:

```bash
# New issues since last milestone was sealed (adjust date to last /plan-milestone run)
gh api "repos/elan-registry/registry/issues?state=open&sort=created&direction=desc&per_page=100" \
  --jq '.[] | select(.milestone == null) | {number, title, labels: [.labels[].name], created_at}'
```

Group by `signal:*` label. Read `signal:owner` and `signal:analytics` issues
in full — these are the strongest evidence. Note any `signal:operator`
issues that lack a second reason to exist (per the doc, these carry a higher
bar).

Also check for a proposed sprint plan, same as `/start-milestone` Step 1.5:

```bash
ls docs/plans/sprints/$ARGUMENTS.md 2>/dev/null
```

If found, read it — it may already propose a theme or cluster.

## Step 2: Pick the theme

State what this release is *for*, in one sentence naming an audience and an
outcome — not a category:

> ✅ "An owner can manage their own car photos without emailing an admin."
> ❌ "Photo improvements." — no audience, no outcome, no finish line.

Themes are **discovered in the signals**, not invented — look for the
cluster from Step 1 rather than picking a topic first and searching for
issues to fit it.

Ask the user:

> "State this milestone's theme in one sentence — who it's for, and what
> they can do afterwards that they can't do now."

Record the answer. It becomes the yardstick for Step 3, the milestone
description, and the ship criterion.

## Step 3: Gate every candidate

Pull candidate issues from the backlog that could serve the theme (not just
those already loosely related — scan broadly, the theme is the filter):

```bash
gh api "repos/elan-registry/registry/issues?state=open&per_page=100" \
  --jq '.[] | select(.milestone == null) | {number, title, labels: [.labels[].name], body}'
```

For each candidate, apply the three questions:

1. **Who noticed?** Name the signal. "Nobody, I thought of it" → out.
2. **What do they do today instead?** Acceptable workaround → not a release
   item.
3. **What breaks if this never ships?** Nothing → close it, don't just
   leave it.

Then, for the survivors, apply the edge-case test to the issue itself (and
flag it for the plan-gate step in `/start-issue` to re-apply per-branch):

> How many real owners take this path in a year? If we don't handle it,
> does it fail gracefully or badly?

- Many users, fails badly → build it.
- Few users, fails badly → note that only the guard is in scope, not the
  full feature — the issue can still be included, scoped down.
- Few users, fails gracefully → **out.** Don't add to the milestone.

Produce two lists:

```text
## Candidates surviving the gate
| # | Title | Signal | Why it serves the theme |
|---|-------|--------|--------------------------|

## Candidates cut
| # | Title | Reason (who noticed / workaround / edge-case) |
|---|-------|--------------------------------------------------|
```

Two kinds of work skip this gate entirely — check for both separately:

- **`signal:forced`** — at most one per milestone (the housekeeping slot in
  Step 4). If more than one is open, the user picks which ships now.
- **`gate-critical`** — always include every open one. These sit outside the
  Step 4 cap and do not consume the housekeeping slot: a gate you cannot
  trust makes every other rule decorative, so its repairs never wait.

```bash
gh issue list --label "signal:forced" --state open --json number,title
gh issue list --label "gate-critical" --state open --json number,title
```

## Step 4: Seal the milestone

Cap: **3–6 theme issues, plus at most one housekeeping issue, plus every
open `gate-critical` issue** (uncapped — see Step 3). If more than six theme
candidates survived Step 3, ask the user which six take priority — the
remainder stay in the backlog, not force-added.

For each selected issue:

1. Write acceptance criteria now, based on the issue body and Step 3's
   reasoning — this is the first point it's worth the effort.
2. Assign the milestone and apply `status:ready`:

   ```bash
   gh issue edit NNN --repo elan-registry/registry \
     --milestone "$ARGUMENTS" --add-label "status:ready"
   ```

3. If acceptance criteria required editing the issue body, do so:

   ```bash
   gh issue edit NNN --repo elan-registry/registry --body "<updated body>"
   ```

For cut candidates: close outright if they failed all three questions, per
`/start-milestone`'s existing closing pattern:

```bash
gh issue close NNN --repo elan-registry/registry \
  --comment "Closing as low-value / make-work during milestone planning. Can be reopened if prioritized."
```

Otherwise leave open, untouched, in the backlog.

Set the milestone description to the theme sentence (not an issue list):

```bash
gh api repos/elan-registry/registry/milestones/<NUMBER> -X PATCH \
  -f description="<theme sentence>"
```

## Step 5: Output summary

- The theme sentence
- Sealed issue list (number, title, signal) — theme issues and the
  housekeeping issue separately
- Cut candidates and why
- Any issues closed outright
- Next step: "Run `/start-milestone $ARGUMENTS` to create the branch and
  begin building."

## Important

- This command never creates branches, commits code, or touches release
  notes — it only changes issue metadata (milestone, labels, body) via the
  GitHub API.
- `signal:owner` and `signal:analytics` are never inferred here — if an
  issue's label looks wrong against its actual content, flag it to the user
  rather than silently relabeling (see ISSUE_WORKFLOW.md's "signal records
  origin" rule — only a rescue or a confirmed mis-classification changes a
  label).
- If `/start-milestone` is run without this command having sealed anything
  first, its own Step 4.4/4.5 still perform an equivalent (lighter-weight)
  gate — this command is the fuller version, meant to run first in the
  typical flow.
