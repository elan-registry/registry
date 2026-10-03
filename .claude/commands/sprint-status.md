---
description: Render the current milestone's derived state — theme, issue status, blocked items
model: haiku
---

# Sprint Status

Keep output brief — terse status lines, no preamble.

Renders the readable view of the open milestone from `docs/development/ISSUE_WORKFLOW.md`'s
"Tracking" section: milestone + derived state + the one manual label, no
board to keep in sync. Read-only — this command makes no changes.

## Arguments

- `$ARGUMENTS` — optional milestone version (e.g., `v2.17.0`). If omitted,
  find the currently open milestone automatically.

## Step 1: Run the script

```bash
scripts/sprint-status.sh $ARGUMENTS
```

This prints the full report: theme, and issue counts by Done, In review, In
progress, Ready, Blocked, and Needs attention.

- **Exit 0** — report printed. Show it to the user, then continue to Step 2.
- **Exit 1** — a `gh` call failed (auth, network, or rate limit). Report the
  error. This is not the same as "milestone has no issues."
- **Exit 2** — usage error.
- **Exit 3** — more than one milestone is open and no version was given. The
  script prints the open milestone titles. Ask the user which one to report
  on, then re-run with that version.
- **Exit 4** — no milestone matched the given version, or no milestone is
  open at all.

## Step 2: Judge the theme sentence

Add one line the script cannot derive:

```text
Theme sentence true yet? <yes/no/partial — one line of reasoning>
```

Judge based on what's Done vs. what remains — per the doc, the milestone
ships when the theme is true, not when the issue list is empty. If most
theme issues are done and what's left is housekeeping or a low-value
straggler, say so.

## Important

- This command is read-only. It never edits issues, labels, or milestones.
- `status:blocked` is the only state not derivable from git/GitHub activity
  — if an issue is stuck for a reason with no trace in the repo (e.g.
  waiting on an owner's reply), that's exactly what the label is for. This
  command surfaces it, not diagnoses it.
- Run any time during a milestone — this is a status check, not a workflow
  step with prerequisites.
