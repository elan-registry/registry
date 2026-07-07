---
description: Create a new GitHub issue with PM-driven scope refinement and expert input
model: claude-sonnet-4-6
---

# Create Issue Command

## Step 0: Initialize TaskList

Before any other action, create one tracking task per major step below using
TaskCreate (existing-issue search, PM scope refinement, expert input from
architect/test/docs agents, issue body draft, user review, issue creation,
next-step suggestion).

This command helps create well-defined GitHub issues by engaging specialized
agents to refine scope, architecture, testing, and documentation requirements.

## Workflow Steps

### Step 1: Get the Problem Statement

If the user provided a problem statement with the command, use it. Otherwise ask:

"What problem or feature would you like to create an issue for? Describe it in
your own words — I'll help refine it into a well-structured issue."

Wait for their response before proceeding.

### Step 2: Initial Research

Launch Explore agents to understand the relevant parts of the codebase:

- One agent to find code areas related to the problem statement
- One agent to check for existing related issues or prior art

```bash
gh issue list --state all --search "RELEVANT_KEYWORDS" --limit 10
```

Check for duplicate or related issues. If found, inform the user:

"I found these related issues: [list]. Should we continue with a new issue,
or does one of these already cover your need?"

Wait for confirmation before proceeding.

### Step 3: PM-Driven Scope Definition

Launch the **senior-product-manager** agent with:

- The user's problem statement
- The Explore results (codebase context)
- Any related issues found

Ask the PM agent to produce:

1. A draft issue title (concise, actionable)
2. A draft description with:
   - **Problem statement**: What's wrong or what's needed
   - **Proposed solution**: High-level approach
   - **Acceptance criteria**: Specific, testable conditions for "done"
   - **Out of scope**: What this issue explicitly does NOT cover
3. Suggested labels (bug, enhancement, tech-debt, etc.)
4. Suggested milestone (if applicable)
5. Questions the PM needs answered to finalize scope

### Step 4: Interview — Ask Questions One at a Time

Present the PM's draft to the user, then ask the PM's questions **one at a
time** using the following approach:

- Present each question clearly with context for why it matters
- When providing options, indicate the best known practice or industry standard
- Wait for each answer before asking the next question
- After each answer, determine if follow-up questions are needed

**Do NOT batch questions.** Ask them individually and let each answer inform
the next question.

Continue until scope is fully clarified.

### Step 5: Expert Refinement

Based on the PM's complexity estimate from Step 3, launch only the agents
that add value for this issue's scope:

| Complexity | Agents to launch |
| --- | --- |
| **Trivial / Small (S)** | Skip — PM draft is sufficient; proceed to Step 6 |
| **Medium (M)** | Launch the one agent most relevant to the PM's flagged risk |
| **Large / XL (L/XL)** | Launch all three in parallel |

**For Medium issues** — pick the most relevant:

- **senior-architect** (when there are technical feasibility concerns, security
  implications, DB schema impacts, or dependency risks): Review for complexity
  estimate (S/M/L/XL), architecture risks, security implications, database
  impacts, and dependencies.

- **senior-test-engineer** (when acceptance criteria have testing complexity or
  regression risk is flagged): Review testability, test types needed
  (unit/integration/browser/security), existing coverage gaps.

**For Large/XL issues** — launch all three in parallel:

- **senior-architect**: Technical feasibility, complexity estimate, architecture
  risks, security implications, DB impacts, dependencies, suggested approach.

- **senior-test-engineer**: Testability of acceptance criteria, test types
  needed, existing coverage, regression risks.

- **technical-documentation-writer**: Documentation that will need updating,
  user-facing and developer docs impact.

Wait for all launched agents to complete.

### Step 6: Synthesize and Present Final Draft

Incorporate expert feedback into the issue. Present the final draft to the
user with:

1. **Title**
2. **Description** (problem, solution, acceptance criteria, out of scope)
3. **Labels**
4. **Milestone** (if applicable)
5. **Expert Notes** section summarizing key input from agents:
   - Architecture considerations
   - Complexity estimate
   - Test requirements
   - Documentation impact

Ask: "Here's the refined issue. Would you like to change anything before I
create it?"

If the user requests changes, update the draft and re-present. If the experts
raised concerns that the user hasn't addressed, flag them:

"The architect noted [concern]. Should we address this in the issue scope or
create a separate issue for it?"

### Step 7: Create the Issue

Once the user approves, create the issue on GitHub:

```bash
gh issue create --title "TITLE" --body "BODY" --label "LABELS" --milestone "MILESTONE"
```

Use a HEREDOC for the body to preserve formatting:

```bash
gh issue create --title "Issue title" --body "$(cat <<'EOF'
## Problem

Description of the problem or need.

## Proposed Solution

High-level approach.

## Acceptance Criteria

- [ ] Criterion 1
- [ ] Criterion 2
- [ ] Criterion 3

## Out of Scope

- Item 1
- Item 2

## Technical Notes

- **Complexity:** S/M/L/XL
- **Architecture:** Key considerations from architect
- **Testing:** Required test types
- **Documentation:** Docs that need updating
- **Security:** Any security considerations
EOF
)"
```

After creation, display the issue URL and number.

### Step 8: Offer Next Steps

After creating the issue, ask:

"Issue #NUMBER created: URL

Would you like to:

1. Start working on it now? (`/issue NUMBER`)
2. Create another related issue?
3. That's all for now."

## Critical Rules

- **Ask questions ONE AT A TIME** — never batch multiple questions
- **PM agent drives the process** — the PM defines scope, others refine it
- **Check for duplicates** — always search for related issues first
- **All acceptance criteria must be testable** — vague criteria get refined
- **Flag expert concerns** — don't silently drop architect/test/docs concerns
- **User has final say** — present recommendations but let the user decide
- **Include complexity estimate** — architect should always estimate S/M/L/XL
- **Use project labels** — check existing labels before suggesting new ones
