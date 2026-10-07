---
description: Capture a pre-existing issue found during development and classify it for immediate fix, deferral, or the cleanup ledger
model: haiku
---

# Found: Capture Pre-Existing Issue

Keep output brief — terse status lines, no preamble, no restating of steps.

Capture an issue discovered incidentally during planning or development work,
classify it using the containment + severity framework, and take the appropriate
action without disrupting the current task.

`/found` is for finds made while an issue is in progress. With no issue in
progress, do not use `/found`. Capture a production break with `/new-issue`
(signal `signal:defect`, labels `bug` and `triage`), then type
`/start-issue <N> --hotfix`. The hotfix track below is for a break found in
the middle of an issue.

## Arguments

- `$ARGUMENTS` — one-line description of the found issue (e.g., "null check
  missing in Car::getOwner()")

## Workflow

### Step 1: Gather context

```bash
git branch --show-current
scripts/check-plan-state.sh
```

The issue number in the branch name is `CURRENT_ISSUE`. Read the plan file
that the `path:` line names. The files that its Implementation Checklist
lists are the files in scope for the current PR. If the `path:` line is
`(none)`, the plan is still a draft in `/start-issue`: the files in scope
are the files that the draft plan lists.

### Step 2: Classify — Containment

Ask:

> "Is the fix for this contained to files the plan already lists, or does
> it require touching other files?"

- **In scope** — the fix touches only files that the plan lists
- **Out of scope** — the fix touches a file that the plan does not list.
  This is true also for a file that you already edited by mistake.

Wait for the answer.

### Step 3: Classify — Necessity (in scope) or Emergency (out of scope)

For an **in-scope** find, ask:

> "Can this issue's acceptance criteria be met without fixing this?"

- **No** — the fix is required for the current issue to be done
- **Yes** — the current issue is complete without it

For an **out-of-scope** find, ask:

> "Is production broken, is data at risk, or is this a security exposure?"

- **Yes** — an emergency
- **No** — not an emergency. Then ask the in-scope question above: "Can this
  issue's acceptance criteria be met without fixing this?" **No** means a
  **deviation**. **Yes** means everything else.

Wait for each answer.

### Step 3b: Classify — Defect or cleanup (defer paths only)

Do this step only when the acceptance criteria can be met without the fix,
and the find is not an emergency. Ask:

> "Can a user or an operator see a wrong result from this — a wrong value,
> lost data, a failed request, a missing email, a misleading message?"

- **Yes** — a **defect**
- **No** — **cleanup**: dead code, duplicated code, naming, comments, type
  annotations, lint noise, stale rows that have no runtime effect, or a
  consistency fix with no change in behavior

Wait for the answer.

For cleanup, also ask yourself: what gets better when this is fixed? If you
cannot name one thing, do not add it to the ledger. Drop it: see "Fix in
current PR and Dropped" below. Report `Dropped: <one-line reason>` and
resume.

### Step 4: Apply the decision matrix and act

| Containment | Classification | Action |
| --- | --- | --- |
| In scope | Needed for the acceptance criteria | **Fix in current PR** |
| In scope | Not needed, defect | **Defer** — new issue, however small the fix looks |
| In scope | Not needed, cleanup | **Ledger** — one item on the cleanup ledger, no new issue |
| Out of scope | Production broken / data at risk / security exposure | **Hotfix track** — new issue, then `/start-issue <N> --hotfix`; patch release from `main` outside the milestone |
| Out of scope | Not an emergency, needed for the acceptance criteria | **Deviation** — `/execute-plan` Step 5, "Deviation rule" |
| Out of scope | Not an emergency, not needed, defect | **Defer** — new issue with `triage` label, no milestone |
| Out of scope | Not an emergency, not needed, cleanup | **Ledger** — one item on the cleanup ledger, no new issue |

**Why fix size is not a cell in this matrix.** "It's only 30 minutes" is a
self-assessed estimate, made at the moment of maximum enthusiasm, and it is
the most common way a diff grows past its plan. The test is whether the
acceptance criteria can be met without the fix — not how long the fix looks.

**Why an out-of-scope fix is never folded in.** A change to a file that the
plan does not list is a deviation. `/execute-plan` must stop and send the
plan back to the approval gate. Fold in a fix only when all of its files are
in the plan.

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

#### Fix in current PR and Dropped

> "I'll fold this into the current PR. I'll note it in the plan and PR
> description under 'Found in passing'."

No new issue needed. Add one line under the **Found in passing** heading of
the plan file (`/start-issue` Step 9 template). Add the heading if the plan
has none. Use the first form for Fix in current PR and the second for
Dropped:

```markdown
- Fixed in this PR: <one line> — `path/to/file`
- Considered, dropped: <one-line reason>
```

The Dropped line is a record for the reviewers of this PR. It tells them
that the find was seen and dropped on purpose. It does not stop a review of
a later PR from raising the same find.

If the PR is already open, also add the line to a `## Found in passing`
section of the PR body. Save the body with `gh pr view <pr-number> --json
body --jq .body`, add the line, and write it back with `gh pr edit
<pr-number> --body-file <file>`.

#### Deviation

The fix is needed for the acceptance criteria, but it touches a file that
the plan does not list. Do not make the fix. Follow `/execute-plan` Step 5,
"Deviation rule": record the change in the plan, ask for re-approval on the
issue, and stop. If the plan is still a draft in `/start-issue`, add the
file to the draft plan instead.

#### Hotfix track

Only for production being broken, data at risk, or a security exposure. The
fix branches from `main`, not from the milestone branch, and ships as a
patch release outside the current milestone — do **not** add the issue to
the open milestone, which stays sealed at its planned scope.

Prefix `CONCISE_TITLE` with `bug:` (or the closest matching type if this
isn't actually a defect — e.g. `security:`) — this issue has no acceptance
criteria yet, so it hasn't earned a `fix:` preamble. See CODING_STANDARDS.md
"Issue & PR Title Conventions".

The hotfix pauses `CURRENT_ISSUE`. The `Paused for hotfix:` line records it.
`/finish-issue` reads that line after the hotfix merges and names the
command that resumes the paused issue. Keep the line at the start of its
own line. Use `--body-file` with a quoted heredoc, so the shell does not
change the text:

```bash
gh issue create \
  --repo elan-registry/registry \
  --title "bug: CONCISE_TITLE" \
  --label "bug,triage,signal:defect" \
  --body-file - <<'EOF'
Pre-existing issue found while working on #CURRENT_ISSUE.

Paused for hotfix: #CURRENT_ISSUE

DESCRIPTION
EOF
```

> "Created issue #NNN on the hotfix track — the current milestone is
> unchanged. #CURRENT_ISSUE is paused."

#### Defer

Defer receives only defects (Step 3b). Prefix `CONCISE_TITLE` with `bug:`.
Add the `bug` label: `/start-issue` uses it to choose the `bug/` branch
prefix and to add the Bug Escape Analysis to the plan.

```bash
gh issue create \
  --repo elan-registry/registry \
  --title "bug: CONCISE_TITLE" \
  --label "bug,triage,signal:discovered" \
  --body-file - <<'EOF'
Pre-existing issue found while working on #CURRENT_ISSUE.

DESCRIPTION
EOF
```

> "Created issue #NNN with the `triage` label for later review."

#### Ledger

The cleanup ledger is `docs/development/CLEANUP_LEDGER.md`. Its "Rules"
section sets the format. Add the item with the Edit tool. Do not use a
script.

1. Read `docs/development/CLEANUP_LEDGER.md`.
2. Write the file's repo-relative path: no `:line` suffix and no leading
   `./` or `/`.
3. Find the level-3 heading under `## Items` that matches the path. Each
   backticked token in a heading names a path, and a heading can name more
   than one. A token matches when it is the exact path, or when it ends in
   `/` and the path starts with it. This is the rule that
   `scripts/ledger-items-for-files.sh` uses. Text outside the backticks is
   a note.
4. If that heading already has an item with the same text, add nothing.
   Tell the user: "Already in the cleanup ledger under `<heading>`."
5. If a heading matches, add the item as the last `- [ ]` line under it.
6. If no heading matches, add `` ### `path/to/file.php` `` with the item
   under it. Put the new group in path order among the other groups.
7. Write the item on one line, in this form:

   ```markdown
   - [ ] ONE_LINE_ITEM (found in #CURRENT_ISSUE)
   ```

The edit is a change to a tracked file. On an issue branch, it goes in that
branch's PR, with the rest of the work. Tell the user:

> "Added to the cleanup ledger under `<heading>`. The edit goes in this
> branch's PR."

On any other branch, tell the user that the edit is not committed, and
name the branch.

### Step 5: Resume — or hand off, for the hotfix track

For Fix in current PR, Defer, Ledger and Dropped: state what action was taken in one sentence,
then immediately return to the current task. Do not interrupt the flow further.

For a Deviation, do not resume. `/execute-plan` Step 5, "Deviation rule",
tells the user the next command.

For the **Hotfix track**, do not resume. This is the one finding that
interrupts a milestone (see `docs/development/ISSUE_WORKFLOW.md`, "Interrupts
and the hotfix track"). Report the new issue number. Tell the user that the
current issue (#CURRENT_ISSUE) is paused, and that `/start-issue` stops
while uncommitted changes exist. Tell them to save the in-progress work
first:

- **Commit it on the issue branch.** Prefer this. `/start-issue` and
  `/execute-plan` resume from a committed branch.
- **Or stash it.** Then, before the paused issue resumes, check out its
  branch and run `git stash pop` on it.

Then give the user the hotfix sequence as plain text. Tell them to run
`/clear` first, because the hotfix is a new issue:

1. `/start-issue NNN --hotfix` — branches from `origin/main` and writes the
   plan. The plan gate applies.
2. `/execute-plan` → `/commit` → `/review-pr` →
   `/commit-push-pr` → `/address-pr-comments`. `/commit-push-pr` reads the
   plan's PR base line and opens the PR against `main`.
3. `/finish-issue NNN` — sees the `main` base, merges into `main`, and skips
   the milestone-only steps.
4. The patch release: `docs/development/DEPLOYMENT.md`, "Patch Release from
   main". It ends with a merge of `main` into the open milestone branch.
5. `/clear`, then `/start-issue CURRENT_ISSUE` — resumes the paused issue.
   It continues at its approval step, or tells you to type `/execute-plan`.

## Quick reference

| Example found issue | Containment | Classification | Action |
| --- | --- | --- | --- |
| Missing null check on a path this issue's criteria depend on | In scope | Needed | Fix in current PR |
| Dead code in a file the plan lists | In scope | Not needed, cleanup | Ledger |
| Wrong total on an admin report, in a file the plan lists | In scope | Not needed, defect | Defer |
| SQL query without prepared statement in a different module | Out of scope | Security exposure | Hotfix track |
| A helper outside the plan returns the wrong value, and this issue's criteria need it | Out of scope | Not an emergency, needed | Deviation |
| Save reports success when the DB write failed, in another module | Out of scope | Not an emergency, not needed, defect | Defer |
| Unused variable in an unrelated helper | Out of scope | Not an emergency, not needed, cleanup | Ledger |
| A comment that repeats the code below it | Out of scope | Cleanup, nothing gets better | Dropped |
