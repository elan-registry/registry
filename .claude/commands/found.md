---
description: Capture a pre-existing issue found during development and classify it for immediate fix, deferral, or the cleanup ledger
model: haiku
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

### Step 3b: Classify — Defect or cleanup (defer paths only)

Do this step only when Step 3 answered **Yes** for an in-scope find (the
current issue is complete without the fix) or **No** for an out-of-scope find
(not an emergency). Ask:

> "Can a user or an operator see a wrong result from this — a wrong value,
> lost data, a failed request, a missing email, a misleading message?"

- **Yes** — a **defect**
- **No** — **cleanup**: dead code, duplicated code, naming, comments, type
  annotations, lint noise, stale rows that have no runtime effect, or a
  consistency fix with no change in behavior

Wait for the answer.

For cleanup, also ask yourself: what gets better when this is fixed? If you
cannot name one thing, record nothing. Report `Dropped: <one-line reason>`
and resume.

### Step 4: Apply the decision matrix and act

| Containment | Classification | Action |
| --- | --- | --- |
| In scope | Needed for the acceptance criteria | **Fix in current PR** |
| In scope | Not needed, defect | **Defer** — new issue, however small the fix looks |
| In scope | Not needed, cleanup | **Ledger** — one item on the cleanup ledger, no new issue |
| Out of scope | Production broken / data at risk / security exposure | **Hotfix track** — branch from `main`, patch release outside the milestone |
| Out of scope | Anything else, defect | **Defer** — new issue with `triage` label, no milestone |
| Out of scope | Anything else, cleanup | **Ledger** — one item on the cleanup ledger, no new issue |

**Why fix size is not a cell in this matrix.** "It's only 30 minutes" is a
self-assessed estimate, made at the moment of maximum enthusiasm, and it is
the most common way a diff grows past its plan. The test is whether the
acceptance criteria can be met without the fix — not how long the fix looks.

**Why an out-of-scope emergency does not join the current milestone.** A
sealed milestone is what makes the release predictable. Genuine emergencies
don't wait for the next planning session, but they ship as a patch release
from `main`, leaving the current milestone's scope untouched. Everything else
queues.

**Why cleanup goes to a ledger, not a new issue.** A cleanup find usually
says "not broken" in its own body, and one issue per find grows the backlog
faster than it drains. A cleanup item costs the least
when a change already has the file open. The ledger keeps these items in one
place, grouped by file, and `/start-issue` pulls a file's items into a plan
when that plan touches the file.

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

#### Ledger

The cleanup ledger is the one open issue with the `cleanup-ledger` label.
Find it:

```bash
gh issue list --repo elan-registry/registry --label cleanup-ledger \
  --state open --json number --jq '.[0].number'
```

If the command returns nothing, stop. Tell the user that no open ledger issue
exists. Do not create a ledger issue and do not create a separate issue.

Keep one group per file. A group is a heading line that starts with
``### `path/to/file.php` `` (some headings name more than one file), and the
checkbox lines under it, up to the next `###` heading. Groups sit in the
ledger issue body and in its comments, often several to a comment, after some
intro text. Look for a heading line for this file:

```bash
F='path/to/file.php'
gh api "repos/elan-registry/registry/issues/LEDGER_NUMBER/comments" --paginate \
  | jq -r --arg h "### \`$F\`" \
    '.[] | select(.body | split("\n") | any(startswith($h))) | .id' | head -1
gh issue view LEDGER_NUMBER --repo elan-registry/registry --json body \
  --jq '.body' | grep -n "^### \`$F\`"
```

- **A heading is found** — insert the new item as the last checkbox line of
  that file's group (before the next `###` heading, or at the end). Change
  nothing else. Write the whole body back:

  ```bash
  # comment:
  gh api -X PATCH "repos/elan-registry/registry/issues/comments/COMMENT_ID" \
    -f body="$NEW_BODY"
  # issue body:
  gh issue edit LEDGER_NUMBER --repo elan-registry/registry --body-file <file>
  ```

- **No heading is found** — add one comment with the file path as the
  heading:

  ```bash
  gh issue comment LEDGER_NUMBER --repo elan-registry/registry --body "### \`path/to/file.php\`
  - [ ] ONE_LINE_ITEM (found while working on #CURRENT_ISSUE)"
  ```

> "Added to cleanup ledger #LEDGER_NUMBER under `path/to/file.php`."

### Step 5: Resume — or hand off, for the hotfix track

For Fix in current PR, Defer, Ledger and Dropped: state what action was taken in one sentence,
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
| Dead code in a file you're already editing | In scope | Not needed, cleanup | Ledger |
| Wrong total on an admin report, in a file you're already editing | In scope | Not needed, defect | Defer |
| SQL query without prepared statement in a different module | Out of scope | Security exposure | Hotfix track |
| Save reports success when the DB write failed, in another module | Out of scope | Anything else, defect | Defer |
| Unused variable in an unrelated helper | Out of scope | Anything else, cleanup | Ledger |
| A comment that repeats the code below it | Out of scope | Cleanup, nothing gets better | Dropped |
