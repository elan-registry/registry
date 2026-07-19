---
description: Capture a pre-existing issue found during development and classify it for immediate fix or deferral
model: claude-haiku-4-5-20251001
---

# Found: Capture Pre-Existing Issue

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

### Step 3: Classify — Severity

Ask:

> "How severe is this?"

- **High** — security vulnerability, data loss risk, or incorrect visible behaviour
- **Low** — cosmetic, code quality, dead code, or minor internal inconsistency

Wait for the answer.

### Step 4: Apply the decision matrix and act

| Containment | Severity | Action |
| --- | --- | --- |
| In scope | High | **Fix in current PR** — fold into current scope |
| In scope | Low | **Fix in current PR** if < ~30 min; otherwise defer |
| Out of scope | High | **Fix in current milestone** — new issue, elevated priority |
| Out of scope | Low | **Defer** — new issue with `triage` label, no milestone |

#### Fix in current PR

> "I'll fold this into the current PR. I'll note it in the plan and PR
> description under 'Found in passing'."

No new issue needed. Add a "Found in passing" item to the plan and PR body.

#### Fix in current milestone

```bash
gh issue create \
  --repo elan-registry/registry \
  --title "CONCISE_TITLE" \
  --body "Pre-existing issue found while working on #CURRENT_ISSUE.\n\nDESCRIPTION" \
  --label "bug,triage" \
  --milestone "CURRENT_MILESTONE_TITLE"
```

> "Created issue #NNN in the current milestone."

#### Defer

```bash
gh issue create \
  --repo elan-registry/registry \
  --title "CONCISE_TITLE" \
  --body "Pre-existing issue found while working on #CURRENT_ISSUE.\n\nDESCRIPTION" \
  --label "triage"
```

> "Created issue #NNN with the `triage` label for later review."

### Step 5: Resume

State what action was taken in one sentence, then immediately return to the
current task. Do not interrupt the flow further.

## Quick reference

| Example found issue | Containment | Severity | Action |
| --- | --- | --- | --- |
| Missing null check in a file you're already editing | In scope | High | Fix in current PR |
| Dead code in a file you're already editing | In scope | Low | Fix in current PR |
| SQL query without prepared statement in a different module | Out of scope | High | Fix in current milestone |
| Unused variable in an unrelated helper | Out of scope | Low | Defer |
