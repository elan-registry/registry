---
description: Capture a pre-existing issue found during development and classify it for immediate fix or deferral
model: claude-haiku-4-5
---

# Found: Capture Pre-Existing Issue

Keep output brief — terse status lines, no preamble, no restating of steps.

Capture an issue discovered incidentally during planning or development work,
classify it using the containment + severity framework, and take the appropriate
action without disrupting the current task.

## Arguments

- `$ARGUMENTS` — one-line description of the found issue (e.g., "null check
  missing in Car::getOwner()")

## Workflow

### Step 1: Gather context

```bash
git branch --show-current
```

Note the current issue branch and milestone branch. Note which files are already
in scope for the current PR (already edited or planned).

### Step 2: Classify — Containment

Ask:

> "Is the fix for this contained to files already in scope for the current PR,
> or does it require touching unrelated files?"

- **In scope** — the fix is in a file you're already editing or planned to edit
- **Out of scope** — requires touching files outside the current PR

Wait for the answer.

### Step 3: Classify — Necessity (in scope) or Emergency (out of scope)

For an **in-scope** find, ask:

> "Can this issue's acceptance criteria be met without fixing this?"

- **No** — the fix is required for the current issue to be done
- **Yes** — the current issue is complete without it

For an **out-of-scope** find, ask:

> "Is production broken, is data at risk, or is this a security exposure?"

- **Yes** — an emergency
- **No** — everything else

Wait for the answer.

### Step 4: Apply the decision matrix and act

| Containment | Classification | Action |
| --- | --- | --- |
| In scope | Needed for the acceptance criteria | **Fix in current PR** |
| In scope | Not needed for the acceptance criteria | **Defer** — new issue, however small the fix looks |
| Out of scope | Production broken / data at risk / security exposure | **Hotfix track** — branch from `main`, patch release outside the milestone |
| Out of scope | Anything else | **Defer** — new issue with `triage` label, no milestone |

**Why "it's only 30 minutes" is no longer a cell in this matrix.** The rule
this replaces let any in-scope low-severity find be folded in if it looked
like under half an hour of work. That estimate is self-assessed, made at the
moment of maximum enthusiasm, and it is the most common way a diff grows past
its plan. The test is now whether the acceptance criteria can be met without
the fix — not how long the fix looks.

**Why an out-of-scope emergency no longer joins the current milestone.** A
sealed milestone is what makes the release predictable. Genuine emergencies
don't wait for the next planning session, but they ship as a patch release
from `main`, leaving the current milestone's scope untouched. Everything else
queues.

#### Fix in current PR

> "I'll fold this into the current PR. I'll note it in the plan and PR
> description under 'Found in passing'."

No new issue needed. Add a "Found in passing" item to the plan and PR body.

#### Hotfix track

Only for production being broken, data at risk, or a security exposure. Branch
from `main`, not from the milestone branch, and ship as a patch release
outside the current milestone — do **not** add the issue to the open
milestone, which stays sealed at its planned scope.

Prefix `CONCISE_TITLE` with `bug:` (or the closest matching type if this
isn't actually a defect — e.g. `security:`) — this issue has no acceptance
criteria yet, so it hasn't earned a `fix:` preamble. See CODING_STANDARDS.md
"Issue & PR Title Conventions".

```bash
gh issue create \
  --repo elan-registry/registry \
  --title "bug: CONCISE_TITLE" \
  --body "Pre-existing issue found while working on #CURRENT_ISSUE.\n\nDESCRIPTION" \
  --label "bug,triage,signal:defect"
```

> "Created issue #NNN on the hotfix track — the current milestone is
> unchanged."

#### Defer

Prefix `CONCISE_TITLE` the same way — `bug:` for a genuine defect, or the
closest matching type (`tech-debt:`, `chore:`, `docs:`) for cosmetic/dead-code/
internal-inconsistency findings that aren't defects.

```bash
gh issue create \
  --repo elan-registry/registry \
  --title "TYPE: CONCISE_TITLE" \
  --body "Pre-existing issue found while working on #CURRENT_ISSUE.\n\nDESCRIPTION" \
  --label "triage,signal:discovered"
```

> "Created issue #NNN with the `triage` label for later review."

### Step 5: Resume — or hand off, for the hotfix track

For Fix in current PR and Defer: state what action was taken in one sentence,
then immediately return to the current task. Do not interrupt the flow further.

For the **Hotfix track**, do not resume. This is the one finding that
interrupts a milestone (see `docs/development/ISSUE_WORKFLOW.md`, "Interrupts
and the hotfix track"): report the new issue number, tell the user the current
task is paused, and stop so they can commit or stash the in-progress work and
start the hotfix from `main` (`/start-issue NNN` on a branch off `main`,
shipped as a patch release). The milestone work resumes after the hotfix is
released.

## Quick reference

| Example found issue | Containment | Classification | Action |
| --- | --- | --- | --- |
| Missing null check on a path this issue's criteria depend on | In scope | Needed | Fix in current PR |
| Dead code in a file you're already editing | In scope | Not needed | Defer |
| SQL query without prepared statement in a different module | Out of scope | Security exposure | Hotfix track |
| Unused variable in an unrelated helper | Out of scope | Anything else | Defer |
