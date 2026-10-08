---
description: Signal review, theme selection, and gate — seal a milestone's issue list before branching
model: opus
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

- `$ARGUMENTS` — the milestone version number (e.g., `v2.17.0`). If no
  open milestone has this version, Step 1 offers to create it.

## Candidate filter

A candidate is an open issue, not a pull request, with no milestone or in
the `Backlog` milestone. The `Backlog` milestone is not a release
commitment. An issue in any other milestone belongs to that release and is
never a candidate. Every issue query in this command uses `--paginate`
(`gh api`) or `--limit 500` (`gh issue list`), because the backlog has more
than 100 open issues.

## Context check

Do this before Step 0. If this conversation already holds work on an earlier
milestone or issue, say so as plain text and recommend: "Run `/clear`, then
type `/plan-milestone $ARGUMENTS` again." Then stop. Continue here only if
the user replies that they want to. Do not use a menu: no option can run
`/clear` for the user. The reason is in CLAUDE.md, "Hand-offs between
commands". If the conversation holds no earlier work, say nothing and
continue.

## Step 0: Initialize TaskList

Create one tracking task per step below (signal review, theme, gate,
seal, output) via TaskCreate.

## Step 1: Read the signals

Find the milestone number and full title:

```bash
gh api "repos/elan-registry/registry/milestones?state=open&per_page=100" --paginate \
  --jq '.[] | select(.title | test("^$ARGUMENTS([: ]|$)")) | [.number, .title] | @tsv'
```

A milestone title can have a suffix after the version, for example
`v2.31.0: Reachable Owners and Findable Cars` or `v2.30.6 - Enable
Verification System`.

- **One line** → record the number as `MILESTONE_NUM` and the full title as
  `MILESTONE_TITLE`. Step 4 uses both.
- **More than one line** → stop. Show the titles. Tell the user: "Rename or
  close the extra milestone on GitHub, then type
  `/plan-milestone $ARGUMENTS`."
- **No line** → ask the user: "No open milestone has the version
  `$ARGUMENTS`. Create it? Reply with the full title (it must start with
  `$ARGUMENTS`, for example `$ARGUMENTS: <short theme>`), or `no` to stop."
  - A title that starts with `$ARGUMENTS` → create the milestone and record
    the two values from the output. Then continue with this step.

    ```bash
    gh api repos/elan-registry/registry/milestones -f title="<full title>" \
      --jq '[.number, .title] | @tsv'
    ```

  - A title that does not start with `$ARGUMENTS` → ask again.
  - `no` → stop. Tell the user: "Create the milestone on GitHub, then type
    `/plan-milestone $ARGUMENTS`."

Read the newest release retrospective, if one exists:

```bash
find docs/plans/releases -name '*-retro.md' 2>/dev/null | sort -V | tail -1
```

`/finish-milestone` Step 6.5 writes this file. It holds three answers, in
this order: what we shipped that nobody needed, what we learned about the
theme's audience, and which signal we ignored. Show all three answers to the
user before Step 2. Step 3 uses the first answer. No file → say "No
retrospective found." and continue.

Pull every candidate (see "Candidate filter"), newest first:

```bash
gh api "repos/elan-registry/registry/issues?state=open&sort=created&direction=desc&per_page=100" --paginate \
  --jq '.[] | select(.pull_request == null) | select(.milestone == null or .milestone.title == "Backlog") | {number, title, labels: [.labels[].name], created_at}'
```

Group by `signal:*` label. Read `signal:owner` and `signal:analytics` issues
in full — these are the strongest evidence. Note any `signal:operator`
issues that lack a second reason to exist (per the doc, these carry a higher
bar).

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

This is the fuller gate — `/start-milestone` Step 4.5 is its lighter-weight
fallback for a milestone that reaches that command without having been
sealed here first, expanded with milestone-specific categories (make-work,
trivial tests, superseded, duplicate scope) that don't apply to a
backlog-wide, pre-implementation gate. If this gate's criteria change, check
whether `/start-milestone` Step 4.5 needs the same change — the two describe
one decision, not two.

Pull candidate issues from the backlog that could serve the theme (not just
those already loosely related — scan broadly, the theme is the filter):

```bash
gh api "repos/elan-registry/registry/issues?state=open&per_page=100" --paginate \
  --jq '.[] | select(.pull_request == null) | select(.milestone == null or .milestone.title == "Backlog") | {number, title, labels: [.labels[].name], body}'
```

First, sort out the `signal:defect` candidates. For each one, decide from
the body whether a user can see the defect: an owner, a visitor, or an
admin or editor in the site UI. A user-visible defect stays in. It does not
have to serve the theme, and it skips the questions and the edge-case test
below. It counts toward the 3–6 theme issues in Step 4. Put it in the
"surviving" table with the signal `signal:defect (user-visible)`, so the
user sees it. A defect that no user can see goes through the gate like any
other candidate.

For each other candidate, apply the three questions:

1. **Who noticed?** Name the signal. "Nobody, I thought of it" → out.
2. **What do they do today instead?** Acceptable workaround → not a release
   item.
3. **What breaks if this never ships?** Nothing → close it, don't just
   leave it.

If the retrospective from Step 1 names work that nobody needed, compare each
candidate with it. A candidate of the same kind must show a stronger signal
to survive. Say which candidates this applies to.

Then, for the survivors, apply the edge-case test to the issue itself (and
flag it for the plan-gate step in `/start-issue` to re-apply per-branch):

> How many real owners take this path in a year? If we don't handle it,
> does it fail gracefully or badly?

- Many users, fails badly → build it.
- Few users, fails badly → only the guard is in scope, not the full
  feature. The issue can still be included, scoped down. Step 4 records the
  reduced scope on the issue.
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

Two more kinds of work skip this gate entirely — check for both
separately. Take only candidates (no milestone, or `Backlog`). An issue of
either kind in another milestone stays in that milestone.

- **`signal:forced`** — at most one per milestone (the housekeeping slot in
  Step 4). If more than one is a candidate, the user picks which ships now.
- **`gate-critical`** — include every candidate. These sit outside the
  Step 4 cap and do not consume the housekeeping slot: a gate you cannot
  trust makes every other rule decorative, so its repairs never wait.

```bash
gh issue list --repo elan-registry/registry --label "signal:forced" --state open --limit 500 \
  --json number,title,milestone \
  --jq '.[] | select(.milestone == null or .milestone.title == "Backlog") | {number, title}'
gh issue list --repo elan-registry/registry --label "gate-critical" --state open --limit 500 \
  --json number,title,milestone \
  --jq '.[] | select(.milestone == null or .milestone.title == "Backlog") | {number, title}'
```

## Step 4: Seal the milestone

Cap: **3–6 theme issues (user-visible `signal:defect` issues count here),
plus at most one housekeeping issue, plus every `gate-critical` candidate**
(uncapped — see Step 3). If more than six theme
candidates survived Step 3, ask the user which six take priority — the
remainder stay in the backlog, not force-added.

For each selected issue:

1. Write the acceptance criteria. `/new-issue` only captures an issue. It
   writes no acceptance criteria, so this step is the first place they are
   written. Base them on the issue body and Step 3's reasoning. Each
   criterion is one testable line. If the body already has an
   `## Acceptance criteria` section, check it against the theme and the
   Step 3 scope, and correct it.
2. Add the criteria to the issue body as an `## Acceptance criteria`
   section of `- [ ]` lines. Keep the rest of the body. Save the body to a
   file, edit the file, and write it back:

   ```bash
   gh issue view NNN --repo elan-registry/registry --json body --jq .body > <body-file>
   gh issue edit NNN --repo elan-registry/registry --body-file <body-file>
   ```

   Do not apply `status:ready` to an issue without this section.
3. Give the title its scoped type. A captured issue can have a `bug:`
   prefix, which means "not yet scoped". Now it has acceptance criteria, so
   change the prefix to the type of the work that ships: `fix:`, `feat:`,
   `test:`, `chore:`, `docs:`, `refactor:`, `tech-debt:`, `security:`, or
   `seo:` (`docs/development/CODING_STANDARDS.md`, "Issue & PR Title
   Conventions"). Keep the rest of the title. Then assign the milestone,
   apply `status:ready`, and remove `triage`:

   ```bash
   gh issue edit NNN --repo elan-registry/registry --title "<type>: <description>" \
     --milestone "<MILESTONE_TITLE>" --add-label "status:ready" --remove-label "triage"
   ```

   `--milestone` takes the full title from Step 1, not the version alone.

   If the issue does not have the `triage` label, leave out
   `--remove-label "triage"`. If the title already has the correct scoped
   type, leave out `--title`.

4. If Step 3 scoped the issue down to the guard only, write acceptance
   criteria for the guard only. Then add a comment that states the reduced
   scope:

   ```bash
   gh issue comment NNN --repo elan-registry/registry \
     --body "Scope for $ARGUMENTS: <the guard only, one line>. The full feature is out of scope (few owners take this path)."
   ```

Then find combine groups among the selected issues: issues that touch the
same code and must land as one PR. Show the groups to the user and ask for
approval. For each approved group, post one comment on each issue in the
group. Use exactly this phrase, because `/start-issue` reads it. On each
issue, list the other issues of the group, not the issue itself:

```bash
gh issue comment NNN --repo elan-registry/registry \
  --body "Combine into one PR with #A, #B (planned in $ARGUMENTS)."
```

For cut candidates: close outright if they failed all three questions, per
`/start-milestone`'s existing closing pattern:

```bash
gh issue close NNN --repo elan-registry/registry \
  --comment "Closing as low-value / make-work during milestone planning. Can be reopened if prioritized."
```

Otherwise leave the issue open. Do not change its milestone (none or
`Backlog`) or its labels. Add one comment with the one-line reason from the "Candidates
cut" table:

```bash
gh issue comment NNN --repo elan-registry/registry \
  --body "Not in $ARGUMENTS: <reason from the Step 3 table>."
```

Set the milestone description to the theme sentence (not an issue list):

```bash
gh api repos/elan-registry/registry/milestones/<MILESTONE_NUM> -X PATCH \
  -f description="<theme sentence>"
```

## Step 5: Output summary

- The theme sentence
- The full milestone title (`MILESTONE_TITLE`), and "created" if Step 1
  created it
- Sealed issue list (number, title, signal) — theme issues, user-visible
  defects, the housekeeping issue, and `gate-critical` issues separately
- Combine groups commented on (Step 4), or "none"
- Cut candidates and why
- Any issues closed outright
- Next step, as plain text: "Run `/clear`, then type
  `/start-milestone $ARGUMENTS` to create the branch and begin building."
  The sealed milestone on GitHub holds the result, so the next command does
  not need this planning context. Do not start it through the Skill tool.

## Important

- This command never creates branches, commits code, or touches release
  notes — it only changes issue metadata (milestone, labels, body,
  comments) and the milestone description via the GitHub API.
- `signal:owner` and `signal:analytics` are never inferred here — if an
  issue's label looks wrong against its actual content, flag it to the user
  rather than silently relabeling (see ISSUE_WORKFLOW.md's "signal records
  origin" rule — only a rescue or a confirmed mis-classification changes a
  label).
- If `/start-milestone` is run without this command having sealed anything
  first, its own Step 4.4/4.5 still perform an equivalent (lighter-weight)
  gate — this command is the fuller version, meant to run first in the
  typical flow. `/start-milestone` skips Steps 4.4 and 4.5 when it finds
  what Step 4 here leaves: a theme-sentence milestone description and
  `status:ready` on every open issue in the milestone.
