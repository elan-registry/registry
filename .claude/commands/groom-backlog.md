---
description: Sweep open issues for low-value/make-work/edge-case candidates, recommend closures and milestone placement, act on one approval
model: opus
---

# Groom Backlog

Keep output brief — terse status lines, no preamble, no restating of steps.

An on-demand audit of open issues: find low-value, make-work, trivial-test,
or extreme-edge-case candidates for closure, and recommend a milestone (or a
new milestone) for everything worth keeping that has no clear release target.
Unlike `/plan-milestone`, this does not seal a milestone or write acceptance
criteria — it only closes, moves, and creates milestones, then stops.

This reuses `/plan-milestone` Step 3's three-question gate. If that gate's
criteria change, check whether this command needs the same change — they
describe one decision applied at two different times (pre-seal vs. anytime).

## Arguments

- `$ARGUMENTS` — optional. A milestone version (e.g. `v2.17.0`) or `backlog`
  to scope the sweep to one milestone. Omit to sweep every open milestone
  plus unmilestoned issues (the default, full sweep).

## Step 1: Pull the issues

```bash
# Unmilestoned
gh api "repos/elan-registry/registry/issues?state=open&per_page=100" --paginate \
  --jq '.[] | select(.milestone == null) | {number, title, labels: [.labels[].name], body}'

# Each open milestone (repeat per milestone, or filter to $ARGUMENTS)
gh api "repos/elan-registry/registry/milestones?state=open" \
  --jq '.[] | {number, title}'
gh api "repos/elan-registry/registry/issues?state=open&milestone=<NUMBER>&per_page=100" --paginate \
  --jq '.[] | {number, title, labels: [.labels[].name], body}'
```

Read each issue's full body, not just the title — a title like "investigate
X" can hide either a bounded chore or an open-ended one; the body decides.

## Step 2: Gate each issue

First, carve out the issues this gate never applies to, the same two
categories `/plan-milestone` Step 3 exempts:

```bash
gh issue list --label "signal:forced" --state open --json number,title
gh issue list --label "gate-critical" --state open --json number,title
```

`gate-critical` issues (per `docs/development/ISSUE_WORKFLOW.md`, currently
issues #1752 and #1843) are load-bearing for a standing gate — never a closure
candidate regardless of signal/workaround/breakage answers. Mark them
**KEEP, gate-critical — exempt** in Step 4's table, not run through the
three questions below. `signal:forced` issues also skip the gate; mark them
**KEEP, signal:forced — exempt**.

Apply `/plan-milestone` Step 3's three questions to every *other* issue,
regardless of its current milestone:

1. **Who noticed?** Name the signal (`signal:*` label, or state "nobody,
   self-generated"). No real signal → closure candidate.
2. **What do they do today instead?** An acceptable workaround with no
   reported pain → closure candidate, not just "low priority."
3. **What breaks if this never ships?** Nothing concrete → closure
   candidate.

Then, for survivors, apply the edge-case test:

> How many real owners take this path in a year? If we don't handle it,
> does it fail gracefully or badly?

- Few users, fails gracefully → closure candidate.
- Many users, or fails badly → keep.

Also flag, independent of the three questions:

- **Trivial tests** — a test whose assertion can't fail given the code it
  covers, or that duplicates an existing test's coverage under a new name.
- **Make-work** — a task that exists because it was easy to write down, not
  because it serves the theme question (ISSUE_WORKFLOW.md's framing). A
  checklist errand with no code change (e.g. "file a report, link it, wait,
  close") is make-work for backlog-tracking purposes even if the underlying
  action has merit — recommend doing it now and closing, not carrying it.

Use `found.md`'s defect/cleanup distinction to word each recommendation:
if nothing gets better when it's fixed, say so plainly.

## Step 3: For survivors, recommend placement

Exempt issues (`gate-critical`/`signal:forced`, marked in Step 2) skip this
step's placement logic — they stay in their current milestone (or Backlog)
untouched. Still list each one in Step 4's table, as
**KEEP, gate-critical/signal:forced — exempt, no placement change**, so a
future run doesn't silently drop them from the output.

For each other issue that passes the gate:

- **Already in a themed release milestone** (not Backlog) whose theme it
  clearly serves → leave it, no action.
- **In Backlog, unmilestoned, or in a release milestone it doesn't actually
  serve** → find the closest existing open milestone by theme match (read
  each milestone's description). If none fits within reason, propose a new
  milestone.
- **Explicitly structured to ride along with other work** (e.g. a grab-bag
  issue that says "pull a group in when that file is next touched") →
  leave in Backlog. Don't force placement that defeats the issue's own
  design.

For a new milestone, pick the version number by the existing scheme: the
next integer after the highest `vX.Y.0` currently in use across all
milestones (open or closed), open or closed state. Check both:

```bash
gh api "repos/elan-registry/registry/milestones?state=all&per_page=100" --paginate \
  --jq '.[].title' | grep -oE 'v2\.[0-9]+' | sort -t. -k2 -n | tail -1
```

Numbered milestones in this project are not strictly chronological (e.g.
v2.30.x–v2.31.x are a verification-system sub-sequence) — a new,
unsequenced theme takes the next open integer, not a slot inside an
existing sub-sequence.

This grep-and-sort is the only place in the project's commands that derives
a milestone version automatically rather than taking it as an argument.
Before creating the milestone, state the computed number and cross-check it
by eye against the full title list the command just printed — confirm the
highest existing number really is what it looks like, not an
off-by-one from a sub-sequence like v2.30–v2.31. Don't skip this check to
save a step.

## Step 4: Present and confirm once

Produce one table before taking any action:

```text
## Recommended closures
| # | Title | Reason (signal / workaround / edge-case / make-work) |
|---|-------|--------------------------------------------------------|

## Recommended milestone moves
| # | Title | Current | Recommended | Why |
|---|-------|---------|-------------|-----|

## Recommended new milestone(s)
| Version | Theme | Issues |
|---------|-------|--------|
```

Ask the user to approve, adjust, or reject each section — one round, not
per-item. Wait for approval before Step 5.

## Step 5: Execute

Only after approval:

```bash
# Closures
gh issue close NNN --comment "<one-line reason, matching the table>"

# New milestone(s)
gh api repos/elan-registry/registry/milestones -f title="vX.Y.0: Theme" -f description="<theme sentence>"

# Moves
gh issue edit NNN --milestone "vX.Y.0: Theme"
```

Closure comments state the reason plainly and note it can be reopened if
circumstances change — never imply an action (like an upstream filing) that
hasn't actually happened.

## Step 6: Output summary

- Closed: list with reasons
- Moved: list with old → new milestone
- Created: new milestone(s) with version and theme
- Left unchanged: Backlog grab-bag items and anything the user declined

## Important

- Never close an issue with a comment claiming a follow-up action (filing,
  reporting, deploying) was completed unless it actually was in this
  session. If the user says they'll do it separately, say that.
- This command does not write acceptance criteria or apply `status:ready` —
  that's `/plan-milestone`'s job when a milestone is actually sealed for
  work.
- `signal:owner` and `signal:analytics` issues are never closed here without
  the user's explicit sign-off in Step 4 — these carry the strongest signal
  in the workflow and a wrong call is costly to reverse in trust, even
  though the GitHub action itself is reversible.
