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
criteria. It closes issues, moves issues out of a release milestone into
`Backlog`, creates milestones, and ages out stale issues. Then it stops.
It never moves an issue into a release milestone (Step 3).

Orphaned cleanup-ledger groups need no sweep here: `composer check:docs`
fails when a `CLEANUP_LEDGER.md` heading names a path that does not exist.

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
  --jq '.[] | select(.pull_request == null) | select(.milestone == null) | {number, title, labels: [.labels[].name], body}'

# Each open milestone (repeat per milestone, or filter to $ARGUMENTS)
gh api "repos/elan-registry/registry/milestones?state=open&per_page=100" --paginate \
  --jq '.[] | [.number, .title] | @tsv'
gh api "repos/elan-registry/registry/issues?state=open&milestone=<NUMBER>&per_page=100" --paginate \
  --jq '.[] | select(.pull_request == null) | {number, title, labels: [.labels[].name], body}'
```

Record each milestone's number and full title. A title can have a suffix
after the version, for example `v2.31.0: Reachable Owners and Findable
Cars`. Step 5 uses the full title. For a version in `$ARGUMENTS`, take the
milestone whose title is the version alone or the version followed by `:`
or a space. For `backlog`, take the `Backlog` milestone and the
unmilestoned issues. No match, or more than one match → stop. Show the
open milestone titles. Tell the user: "Type `/groom-backlog` with the
version of one open milestone, `backlog`, or no argument."

Read each issue's full body, not just the title — a title like "investigate
X" can hide either a bounded chore or an open-ended one; the body decides.

## Step 2: Gate each issue

First, carve out the issues this gate never applies to, the two labels
that `/plan-milestone` Step 3 exempts. `/plan-milestone` also keeps a
user-visible `signal:defect` issue without the theme test. This command has
no theme, so a defect goes through the questions below:

```bash
gh issue list --repo elan-registry/registry --label "signal:forced" --state open --limit 500 --json number,title
gh issue list --repo elan-registry/registry --label "gate-critical" --state open --limit 500 --json number,title
```

The label is the list. Do not keep issue numbers here. `gate-critical`
issues (see `docs/development/ISSUE_WORKFLOW.md`) are load-bearing for a
standing gate — never a closure candidate regardless of
signal/workaround/breakage answers. Mark them
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

This command never moves an issue into a release milestone. Only
`/plan-milestone` adds issues to a release, after its gate. Its "Candidate
filter" reads only unmilestoned and `Backlog` issues. An issue that this
command put in a release milestone would skip that gate. The only move is
out of a release milestone, into `Backlog`.

For each other issue that passes the gate:

- **Already in a release milestone** (not Backlog) whose theme it clearly
  serves → leave it, no action.
- **In a release milestone it does not serve** → recommend a move to
  `Backlog`, but only when all three checks below pass. If a check fails,
  list the issue as **no move** with the reason. The user de-scopes it by
  hand, in the milestone's own workflow.
- **In Backlog or unmilestoned** → do not move it. Recommend a target: the
  closest open milestone by theme (read each milestone's description), or a
  new milestone. `/plan-milestone <version>` gates it from the backlog.
- **Explicitly structured to ride along with other work** (e.g. a grab-bag
  issue that says "pull a group in when that file is next touched") →
  leave in Backlog, with no target.

Checks before a move to `Backlog`. Empty output passes the check:

```bash
# 1. The issue has no branch
git ls-remote --heads origin '*/<n>-*'
git branch --list '*/<n>-*'

# 2. The issue has no open PR
gh pr list --repo elan-registry/registry --state open --limit 500 \
  --json number,headRefName --jq '.[] | select(.headRefName | test("/<n>-")) | "#\(.number) \(.headRefName)"'

# 3. The milestone is not active (exit 1 and no branch name means no branch)
scripts/find-milestone-branch.sh <version>
```

`<version>` is the `vX.Y.Z` part of the milestone's full title. A move
also removes `status:ready`, because a backlog issue is not ready
(`docs/development/ISSUE_WORKFLOW.md`, "Tracking").

A recommended target names the milestone by its full title from Step 1,
not the version alone.

For a new milestone, pick the version number by the existing scheme: the
highest major version in use, and the next minor after the highest minor of
that major, across all milestones, open or closed. This prints the highest
`<major> <minor>` pair. The new milestone is `v<major>.<minor + 1>.0`:

```bash
gh api "repos/elan-registry/registry/milestones?state=all&per_page=100" --paginate \
  --jq '.[].title' | sed -nE 's/^v([0-9]+)\.([0-9]+).*/\1 \2/p' \
  | sort -k1,1n -k2,2n | tail -1
```

Numbered milestones in this project are not strictly chronological. A run
of minors can be one theme's sub-sequence. A new, unsequenced theme takes
the next open minor, not a slot inside an existing sub-sequence.

This grep-and-sort is the only place in the project's commands that derives
a milestone version automatically rather than taking it as an argument.
Before creating the milestone, state the computed number and cross-check it
by eye against the full title list the command just printed — confirm the
highest existing number really is what it looks like, not an
off-by-one from a sub-sequence. Don't skip this check to
save a step.

The new milestone's full title is `v<major>.<minor + 1>.0: <short theme>`.
Step 4 shows it and Step 5 uses it exactly.

## Step 3.5: Age out stale issues

An issue that nobody asked about for 180 days has no demand. Propose a
`stale` warning first. Propose a close only 14 days after the warning. Do
not change any issue in this step. Step 5 acts only on approval.

**Exempt issues.** Never age out an issue that has any of these:

- A milestone other than `Backlog`. The `Backlog` milestone is not a
  release commitment, so it does not exempt an issue.
- The label `signal:forced`, `component: security`, or `gate-critical`.

**Human comment.** A comment counts only when `user.type` is not `"Bot"`
and the body does not start with `Marked stale:` (the warning that this
step posts). Label, milestone, and title changes are events, not comments.
They do not count. To get the date of the newest human comment on issue
`<n>` (empty output means none):

```bash
gh api "repos/elan-registry/registry/issues/<n>/comments?per_page=100" --paginate \
  --jq '.[] | select(.user.type != "Bot") | select(.body | startswith("Marked stale:") | not) | .created_at' \
  | sort | tail -1
```

**A. Warn.** Find the open issues created more than 180 days ago that are
not exempt and not already `stale`:

```bash
CUTOFF=$(date -u -v-180d +%Y-%m-%d)
gh issue list --repo elan-registry/registry --state open --limit 1000 \
  --search "created:<$CUTOFF -label:signal:forced -label:\"component: security\" -label:gate-critical -label:stale" \
  --json number,title,createdAt,milestone \
  --jq '.[] | select(.milestone == null or .milestone.title == "Backlog") | "#\(.number) \(.createdAt[:10]) \(.title)"'
```

For each result, get the newest human comment. Propose **Warn** when there
is none, or when it is older than `$CUTOFF`.

**B. Close.** Find the open issues that have the `stale` label:

```bash
gh issue list --repo elan-registry/registry --state open --label stale --limit 1000 \
  --json number,title,milestone,labels \
  --jq '.[] | "#\(.number) [\(.milestone.title // "no milestone")] [\([.labels[].name] | join(", "))] \(.title)"'
```

For each result, find when the `stale` label was added. The newest
`labeled` event for `stale` gives the date:

```bash
gh api "repos/elan-registry/registry/issues/<n>/events?per_page=100" --paginate \
  --jq '.[] | select(.event == "labeled" and .label.name == "stale") | .created_at' \
  | sort | tail -1
```

Then get the newest human comment. Propose one action:

- **Remove stale** — the issue is now exempt, or it has a human comment
  after the label date.
- **Close** — the label date is 14 or more days ago
  (`date -u -v-14d +%Y-%m-%dT%H:%M:%SZ` prints that limit), and no human
  comment came after it.
- Otherwise — no action. The 14 days have not passed.

## Step 4: Present and confirm once

Produce one table before taking any action:

```text
## Recommended closures
| # | Title | Reason (signal / workaround / edge-case / make-work) |
|---|-------|--------------------------------------------------------|

## Recommended moves to Backlog
| # | Title | Current (full title) | Why it does not serve that milestone |
|---|-------|----------------------|--------------------------------------|

## No move (failed a Step 3 check)
| # | Title | Current (full title) | Failed check (branch / open PR / active milestone) |
|---|-------|----------------------|----------------------------------------------------|

## Targets for /plan-milestone (no change on GitHub)
| # | Title | Target (full title) | Why |
|---|-------|---------------------|-----|

## Recommended new milestone(s)
| Full title | Theme sentence | Target issues (they stay in Backlog) |
|------------|----------------|--------------------------------------|

## Age-out
| # | Title | Action (warn / close / remove stale) | Created or stale since | Last human comment |
|---|-------|--------------------------------------|------------------------|--------------------|
```

An issue in the age-out table must not also be in the closures or moves
tables. Pick one row for it.

Ask the user to approve, adjust, or reject each section — one round, not
per-item. Wait for approval before Step 5.

## Step 5: Execute

Only after approval:

```bash
# Closures
gh issue close NNN --repo elan-registry/registry --comment "<one-line reason, matching the table>"

# New milestone(s): the full title from the Step 4 table
gh api repos/elan-registry/registry/milestones -f title="<full title>" -f description="<theme sentence>"

# Moves: into Backlog only. Leave out --remove-label when the issue has no status:ready
gh issue edit NNN --repo elan-registry/registry --milestone "Backlog" --remove-label "status:ready"

# Age-out: warn
gh issue edit NNN --repo elan-registry/registry --add-label "stale"
gh issue comment NNN --repo elan-registry/registry \
  --body "Marked stale: no activity in 180 days. This issue closes in 14 days unless someone comments with a reason to keep it."

# Age-out: close
gh issue edit NNN --repo elan-registry/registry --add-label "stale-no-demand"
gh issue close NNN --repo elan-registry/registry --reason "not planned" \
  --comment "Closed: no demand in the 14 days after the stale warning. A new signal reopens it."

# Age-out: remove stale
gh issue edit NNN --repo elan-registry/registry --remove-label "stale"
```

The warning comment must start with `Marked stale:`. Step 3.5 ignores
comments that start with it, so the warning does not count as demand.

Closure comments state the reason plainly and note it can be reopened if
circumstances change — never imply an action (like an upstream filing) that
hasn't actually happened.

## Step 6: Output summary

- Closed: list with reasons
- Moved to Backlog: list with the old milestone
- No move: list with the failed check
- Targets: each target milestone with its issues
- Created: new milestone(s) with full title and theme
- Age-out: issues warned, closed with `stale-no-demand`, and `stale`
  removed
- Left unchanged: Backlog grab-bag items and anything the user declined

End with the next step, as plain text, not a menu:

- If there is a target milestone, say: "Run `/clear`, then type
  `/plan-milestone <version>` for the milestone you want to plan next."
  List the target versions.
- Otherwise, say: "No command to type now."

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
