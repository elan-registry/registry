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
gh issue list -R elan-registry/registry --state all --search "KEYWORDS" --limit 10 \
  --json number,title,state,labels \
  --jq '.[] | "#\(.number) \(.state) [\([.labels[].name] | join(", "))] \(.title)"'
```

If an open issue already covers it, show it and ask with AskUserQuestion:
`Add a comment to #NNN instead` (recommended when the new text is evidence
for that issue) or `Create a new issue`. On the comment option, post the
quote or evidence with `gh issue comment`. Then say: "Evidence added
to #NNN: URL. No command to type now." Stop.

If a closed issue with the `stale-no-demand` label covers it, the new text
is a rescue (`docs/development/ISSUE_WORKFLOW.md`, "Age-out with evidence
rescue"). Show the issue and ask with AskUserQuestion:
`Reopen #NNN with this evidence` (recommended) or `Create a new issue`. On
the reopen option, go to Step 2.1.

A closed issue without `stale-no-demand` is not a rescue. Show it as
context and continue to Step 3.

### Step 2.1: Reopen an aged-out issue

1. Get the new signal and the quote or evidence. Use the **Signal** and
   **Quote or evidence** rules in Step 3. Ask only for what you cannot
   find, in one AskUserQuestion round.
2. Read the current signal label:

   ```bash
   gh issue view NNN -R elan-registry/registry --json labels \
     --jq '[.labels[].name | select(startswith("signal:"))] | join(",")'
   ```

3. Reopen the issue with the evidence as the comment:

   ```bash
   gh issue reopen NNN -R elan-registry/registry --comment "$(cat <<'EOF'
   Reopened: new signal:X.

   > Verbatim words, or the measurement or error.
   EOF
   )"
   ```

4. Remove the age-out labels. Replace the old signal label with the new
   one. Name in `--remove-label` only the labels the issue has now (from
   the Step 2 search output). If the old and new signal labels are the
   same, leave out `signal:OLD` and `--add-label "signal:X"`.

   ```bash
   gh issue edit NNN -R elan-registry/registry \
     --remove-label "stale,stale-no-demand,signal:OLD" --add-label "signal:X"
   ```

5. Go to Step 5. Use the reopen wording there.

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
`/plan-milestone` weighs it. No command to type now."

After Step 2.1, say instead: "Issue #NUMBER reopened: URL. It is in the
backlog. The next `/plan-milestone` weighs it. No command to type now."

Do not offer `/start-issue`. With no milestone, `/start-issue` would put the
issue on whichever milestone branch is checked out and skip the planning
gate.

**Exception: a production break.** If the issue is `signal:defect` and
production is broken, data is at risk, or there is a security exposure, say
instead: "Issue #NUMBER created: URL. This is a hotfix. Run `/clear`, then
type `/start-issue NUMBER --hotfix`." The hotfix track ships from `main`
outside the milestone (CLAUDE.md, "Developer Workflow").
