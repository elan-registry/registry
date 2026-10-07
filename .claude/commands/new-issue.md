---
description: Capture a new GitHub issue — title, signal, beneficiary, and evidence only
model: opus
---

# Create Issue Command

Keep output brief — terse status lines, no preamble, no restating of steps.

This command captures an issue. It does not scope it. Capture records four
things: a title, one signal label, a one-line beneficiary, and the quote or
evidence (`docs/development/ISSUE_WORKFLOW.md`, "1. Capture"). Acceptance
criteria, approach, and estimates are planning work: `/plan-milestone`
Step 4 writes acceptance criteria for the issues it selects. Never suppress
a capture. The filter is at planning.

Do not launch the PM or expert agents. Do not write acceptance criteria, a
proposed solution, or a complexity estimate. Do not set a milestone.

## Step 1: Get the Problem Statement

If the user gave a problem statement with the command, use it. Otherwise
ask: "What problem or idea do you want to record?"

## Step 2: Search for Duplicates

```bash
gh issue list -R elan-registry/registry --state all --search "KEYWORDS" --limit 10
```

If an open issue already covers it, show it and ask with AskUserQuestion:
`Add a comment to #NNN instead` (recommended when the new text is evidence
for that issue) or `Create a new issue`. On the comment option, post the
quote or evidence with `gh issue comment` and stop.

## Step 3: Draft the Issue

Find the facts yourself. Ask the user only for what you cannot find, in one
AskUserQuestion round (up to 4 questions).

- **Title** — a Conventional-Commits preamble (CODING_STANDARDS.md, "Issue
  & PR Title Conventions"). A defect is `bug:`, because it has no acceptance
  criteria yet. Otherwise use the closest type: `feat:`, `chore:`, `docs:`,
  `tech-debt:`, `security:`, `seo:`, `test:`, `refactor:`.
- **Signal** — exactly one label. Use the table in ISSUE_WORKFLOW.md §1:
  `signal:owner`, `signal:analytics`, `signal:operator`, `signal:defect`,
  `signal:forced`, `signal:discovered`. If you cannot tell, ask: "Who
  noticed this, and what did they say?" "Nobody — I thought of it" is a
  valid answer and gets `signal:operator`.
- **Never infer `signal:owner` or `signal:analytics`.** Apply them only
  with a real message or a real measurement to point at.
- **Beneficiary** — one sentence: who is worse off today, or better off if
  this ships. If the user cannot name somebody other than themselves, write
  the issue anyway.
- **Quote or evidence** — the requester's words, verbatim, or the
  measurement, log line, or error. Never paraphrase a quote.
- **Labels** — the signal label, `triage`, and `bug` for a defect or
  `enhancement` for a feature.

Show the draft and ask with AskUserQuestion: `Create it` or `Change it`.

## Step 4: Create the Issue

```bash
gh issue create -R elan-registry/registry --title "TYPE: TITLE" \
  --label "signal:X,triage,LABEL" --body "$(cat <<'EOF'
## Signal

signal:X — who noticed this, and how.

## Beneficiary

One sentence.

## Quote / evidence

> Verbatim words, or the measurement or error.
EOF
)"
```

## Step 5: State the Next Step

End with plain text, not a menu:

"Issue #NUMBER created: URL. It has no milestone. The next
`/plan-milestone` weighs it."

Do not offer `/start-issue`. With no milestone, `/start-issue` would put the
issue on whichever milestone branch is checked out and skip the planning
gate.
