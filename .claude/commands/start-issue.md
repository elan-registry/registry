---
description: Start work on a GitHub issue within a milestone workflow
model: claude-opus-5
---

# GitHub Issue Workflow Command

## Hard Constraints (non-negotiable)

> **1. PLAN APPROVAL IS REQUIRED before this command ends.**
> Write the plan to its plan file (Step 9) and present that file's content
> for approval. Do not mark the plan approved until you receive a clear
> "yes / proceed / looks good" or equivalent. If the user changes the
> subject or gives partial feedback, ask again: "Should I proceed with the
> plan as written?"
>
> **2. THIS COMMAND NEVER IMPLEMENTS, COMMITS, PUSHES, OR CREATES PRs.**
> `/start-issue` stops once the plan file is approved. Implementation is a
> separate command, `/execute-plan`, run afterward. Do not write application
> code, run `git add`/`git commit`/`git push`, or launch software-developer
> agents for implementation from within this command.

---

## Step 0: Defer TaskList Until Tier Is Known

Do NOT create tasks yet. Fetch the issue (Step 2) and assess complexity tier
first. After Step 2, create only the tasks that apply to the determined tier:

- **Small** (1-2 files, clear scope): 5 tasks — fetch issue + assess, branch +
  mark in progress, explore, write + approve plan file, final summary
- **Medium** (3-5 files, some ambiguity): 6 tasks — fetch issue + assess,
  branch + mark in progress, explore, PM refinement, write + approve plan
  file, final summary
- **Large** (new subsystem, schema changes, cross-cutting): 7 tasks — all of
  the above plus a separate documentation-plan step

Set each to `in_progress`/`completed` as you progress.

This command helps you start working on a GitHub issue within a milestone
workflow by creating a branch, entering plan mode, and developing an
implementation plan with continuous clarifying questions. It ends by writing
an approved plan file to `docs/plans/` — implementation happens afterward, in
a separate command, `/execute-plan`, which reads that file. Specialized
agents are invoked as needed throughout the research/planning workflow below;
`/execute-plan` invokes its own separate set for implementation.

## Available Agents

Launch agents via the Task tool. Use parallel instances when work can be partitioned.
This command's scope is research and planning only — it does not implement, so
`software-developer` and post-implementation `senior-architect` review are not
used here. See `/execute-plan`'s own agent table for those.

| Agent | `subagent_type` | Model | Use When |
| --- | --- | --- | --- |
| Explore | `Explore` | `haiku` | Codebase research |
| Plan | `Plan` | `sonnet` | Implementation strategy |
| Senior Product Manager | `senior-product-manager` | `sonnet` | Issue refinement, scope, criteria |
| Senior Test Engineer | `senior-test-engineer` | `sonnet` | Test strategy for the plan's Test Plan section |
| Technical Documentation Writer | `technical-documentation-writer` | `haiku` | Documentation-plan scoping |
| General Purpose | `general-purpose` | `haiku` | Multi-step research |

**Scale agent usage to issue complexity** — see tiers below. Over-invoking agents is waste.
**Skip** the docs-scoping consult for internal refactoring; the test-strategy consult for docs-only changes.

## Issue Complexity Tiers

Assess complexity immediately after fetching the issue. Choose the tier and follow its workflow.

| Tier | Profile | Agent pattern |
| --- | --- | --- |
| **Small** | 1-2 files, clear scope, explicit acceptance criteria, no DB/security changes | 1 Explore → write plan file |
| **Medium** | Feature, 3-5 files, some ambiguity, or touches DB/auth | 1-2 Explore → PM (if scope unclear) → Plan → test-strategy consult → write plan file |
| **Large** | New subsystem, schema changes, cross-cutting concern, or significant ambiguity | Full workflow below |

For Small issues skip: PM agent, parallel Explore agents.
This command never launches `senior-architect` — architecture/security review
of actual code happens in `/execute-plan`, after implementation, not here.

## Workflow Steps

### Step 1: Ask for Issue Number (if not provided)

If the user didn't provide an issue number, ask:

"Which GitHub issue would you like to work on? Please provide the issue number."

Wait for their response before proceeding.

### Step 2: Fetch Issue Details

Once you have the issue number, fetch the issue details:

```bash
gh issue view ISSUE_NUMBER
```

Display a summary of the issue including:

- Title
- Current state
- Labels
- Milestone (if any)
- Description

### Step 3: Verify Milestone Branch and Determine Issue Branch Name

This command requires a milestone workflow. The user must already be on a
`milestone/*` branch (created by `/start-milestone`).

1. **Check the current branch:**

   ```bash
   git branch --show-current
   ```

2. **If on a `milestone/*` branch**, use it as the base. Extract the version
   from the branch name (e.g., `milestone/v2.17.0` -> `v2.17.0`).

3. **If NOT on a `milestone/*` branch**, check if exactly one exists:

   ```bash
   git branch --list 'milestone/*'
   ```

   - **If exactly one exists**, switch to it:

     ```bash
     git checkout milestone/vX.Y.Z
     git pull origin milestone/vX.Y.Z
     ```

   - **If zero exist**, stop and tell the user:
     "No milestone branch found. Please run `/start-milestone` first to create
     one, then re-run `/start-issue ISSUE_NUMBER`."
   - **If multiple exist**, stop and tell the user:
     "Multiple milestone branches found: [list them]. Please checkout the one
     you want to work on and re-run `/start-issue ISSUE_NUMBER`."

4. **Branch naming**: Use the issue labels to determine the branch prefix:
   - `bug` label -> `bug/ISSUE_NUMBER-short-description`
   - `enhancement` or `feature` label -> `feature/ISSUE_NUMBER-short-description`
   - All other labels (including `tech-debt`) -> `issue/ISSUE_NUMBER-short-description`

   Present the proposed branch name and ask: "I'll create a branch named
   `PREFIX/ISSUE_NUMBER-short-description` from `milestone/vX.Y.Z`. Does this
   work, or would you prefer a different name?"

Wait for the answer before proceeding.

### Step 4: Create Issue Branch

After getting branch name confirmation, create the issue branch from the
current milestone branch and push to remote:

```bash
git checkout -b BRANCH_NAME
git push -u origin BRANCH_NAME
```

Confirm: "Created branch `BRANCH_NAME` from `MILESTONE_BRANCH` and pushed to
remote."

### Step 4.5: Update GitHub Issue

After creating the branch, mark the issue as in progress:

```bash
# Create the "in progress" label if it doesn't exist (ignore error if it does)
gh label create "in progress" --color 0075CA --description "Work is actively underway" 2>/dev/null || true

# Update the issue
gh issue edit ISSUE_NUMBER --add-label "in progress" --add-assignee @me
```

Confirm: "Marked issue #ISSUE_NUMBER as in progress and assigned to you."

### Step 5: Launch Explore Agents for Initial Research

Before asking questions, launch Explore agents to understand the codebase context.

**Scale to tier:**

- **Small:** 1 Explore agent covering the affected file(s) and adjacent patterns.
- **Medium:** 1-2 Explore agents — one per distinct subsystem touched.
- **Large:** 2-3 Explore agents in parallel — one per subsystem, one for patterns/conventions, one for tests.

Each Explore agent should check the relevant docs (USERSPICE_FUNCTIONS.md, CLASSES.md,
CODING_STANDARDS.md, ERROR_HANDLING.md, DATABASE.md) only when those areas are plausibly
affected — don't blanket-read all docs for every issue.

#### For Bug Issues (bug label): Investigate Testing Gaps

Add an escape-analysis question to the Explore prompt: why wasn't this caught by existing
tests? What code paths were untested? What type of test would prevent recurrence?

Document findings in the plan under **Bug Escape Analysis**.

Wait for Explore results before proceeding.

### Step 5.5: Triage Pre-Existing Issues Found During Exploration

Explore agents regularly surface pre-existing issues — missing validation,
security gaps, dead code, inconsistencies — that are unrelated to the current
issue. **Do not silently note them as "pre-existing" and move on.**

For each one found, apply the containment + severity matrix immediately:

| Containment | Severity | Action |
| --- | --- | --- |
| In files already in scope for this PR | High | Fold into current PR — note in plan and PR description |
| In files already in scope for this PR | Low | Fix in current PR if < ~30 min; otherwise defer |
| Outside current PR scope | High | New issue in current milestone (`bug` + `triage` labels) |
| Outside current PR scope | Low | New issue with `triage` label only; no milestone |

For each found issue, state it explicitly to the user:

> "While exploring, I found [description]. This is [in scope / out of scope]
> and [high / low] severity, so I recommend [action]. Does that seem right?"

Wait for confirmation, then act — create the issue or note it in the plan —
before continuing. Use `/found` for the same classification outside this workflow.

### Step 6: Interview Mode - Issue Refinement and Questions

**For Small issues:** Skip the PM agent. Ask only questions you genuinely can't answer
yourself from the issue text and Explore results. One or two targeted questions max.

**For Medium/Large issues:** Launch the senior-product-manager agent when the issue has
unclear scope, missing acceptance criteria, possible decomposition, or dependency concerns.
Skip it when the issue is already well-defined.

When you do launch the PM agent, provide: issue details, Explore results, and specific
concerns. Ask it to evaluate: completeness, acceptance criteria gaps, decomposition needs,
and questions to ask the user.

After any PM input, interview the user using AskUserQuestion. Ask only non-obvious
questions — scope clarity, approach decisions, edge case handling. When providing options,
note best practice or industry standard.

**If the PM agent recommends issue decomposition**, discuss with the user before proceeding.

### Step 7: Enter Plan Mode and Ask Questions Throughout

Use the EnterPlanMode tool and explain:

"I'm entering plan mode to create an implementation plan based on the research
and your answers. I'll ask clarifying questions as I refine the approach."

**While in plan mode:**

1. **Deepen research as needed**: Launch additional Explore or general-purpose
   agents for specific questions that arise during planning.

2. **Ask clarifying questions ONE AT A TIME as you discover them**:

   - When you find multiple approaches: "I found that we could implement this
     using [Approach A] or [Approach B]. Which would you prefer?"
   - When scope is unclear: "Should this feature also handle [related scenario]?"
   - When you need preferences: "I see we use [Pattern X] in some places and
     [Pattern Y] in others. Which should I follow for this issue?"
   - When dependencies are involved: "This change will affect [Component X].
     Should I update it as part of this issue or create a separate issue?"
   - When requirements need clarification: "The issue mentions [Feature]. Should
     this include [specific behavior]?"
   - When providing options, tell me what is the best known practice or the
     industry standard.

3. **Continue research after each answer**: Use their responses to guide your
   exploration and planning.

4. **Ask follow-up questions as needed**: Don't batch questions - ask them
   naturally as you work through the planning process.

5. **Verify UserSpice Integration** (Step 7.1): Before finalizing the approach,
   check if the solution duplicates existing UserSpice functionality:

   - Review USERSPICE_FUNCTIONS.md for relevant framework functions
   - Ask: "Does UserSpice provide this functionality already?"
   - If yes: Leverage UserSpice instead of custom implementation
   - If no: Verify the custom approach doesn't conflict with UserSpice patterns

   Document the UserSpice integration decision in your plan.

6. **Assess Database and Security Impacts** (Step 7.2): For issues that may
   affect the database, security, or sensitive operations, ask these questions:

   - Does this change affect database schema, triggers, or audit trails?
   - Does this involve user authentication, session handling, or CSRF protection?
   - Does this handle sensitive data (user info, payment data, etc.)?
   - Are there GDPR compliance implications?
   - Does this require prepared statements for all database queries?
   - Does this require input validation or sanitization?

   Document any database, security, or compliance requirements in your plan.

   **For Bug Issues: Document Escape Analysis** (Step 7.2.5): If the issue
   has a `bug` label, create an "Escape Analysis" section in your plan:

   - **Root Cause:**
     - What specifically caused the bug?
     - Why did it reach production?

   - **Testing Gap:**
     - What existing tests should have caught this?
     - Why were those tests missing or insufficient?
     - What code paths were untested?

   - **Preventive Measures:**
     - What automated tests will prevent this bug from recurring?
     - Should be: unit test, integration test, or browser test (or combination)?
     - Are there similar untested code paths needing tests?

   Example: "Bug: Form doesn't validate negative car prices. Root cause: numeric
   validation was removed in refactor. Testing gap: no unit test for price
   validation. Preventive: Add PHPUnit test for price input validation."

   This analysis will be included in the implementation plan and highlighted in
   the PR description.

7. **Consult specialized agents** (Step 7.3 — Medium/Large only):

   **Skip this step for Small issues.** The architect reviews code after implementation, not plans.

   For Medium/Large, launch in parallel only the agents that apply:

   - **senior-test-engineer** (when code changes are made): Ask for a test strategy — which
     test types apply (unit, integration, browser, security, DB) and which existing tests
     need updating. Launch separate instances for PHPUnit vs Playwright if both are needed.
     If the issue adds a new query, explicitly ask for a live-DB verification step (not
     just a mocked-DB unit test) — mocked tests cannot catch a query that is fatally wrong
     against the real schema/sql_mode. If the issue adds a public method accepting
     structured input (array/DB row/JSON), explicitly ask for wrong-typed-value tests per
     field, not just missing-key tests — PHPStan's static array-shape checking cannot catch
     a shape violation in data assembled at runtime, and a present-but-wrong-typed value can
     reach a strictly-typed helper as an uncaught TypeError instead of the method's own
     documented exception.

   - **technical-documentation-writer** (only when changes affect public APIs, schema,
     classes, or user flows): Ask which docs need updating based on the change type.

   Do NOT launch senior-architect from this command. Architect review happens
   post-implementation, inside `/execute-plan`, when there is actual code to
   review — never against a plan.

8. **Incorporate agent feedback into the plan** (Step 7.4): Merge feedback
   into a single comprehensive plan. Include sections only for agents that
   were consulted:
   - **Bug Escape Analysis** (from Step 7.2.5, if bug issue)
   - **UserSpice Integration** (from Step 7.1)
   - **Database & Security Considerations** (from Step 7.2)
   - **Architecture & Design** (your plan, informed by Explore/Plan-agent research)
   - **Implementation Checklist** (see Step 9's format — this is the section
     `/execute-plan` executes against)
   - **Test Plan** (from senior-test-engineer, if consulted)
   - **Documentation Plan** (from technical-documentation-writer, if consulted)

### Step 8: Exit Plan Mode with Draft Plan Content

Use ExitPlanMode when you have:

- Asked all necessary clarifying questions
- Explored all relevant code
- Consulted the appropriate specialized agents
- Drafted all sections of the plan in Step 7.4's list

Exiting plan mode here does not yet mean approval — it hands control back to
write the plan to disk (Step 9), which is what the user actually reviews.

### Step 9: Write the Plan File and Present for Approval

Write the plan to `docs/plans/issue-<ISSUE_NUMBER>-<slug>.md`, where `<slug>`
is the same short kebab-case description used for the branch name (Step 3).
Create the `docs/plans/` directory if it does not exist yet. The
`**Milestone:**` field is the `milestone/*` branch Step 3 already determined
— record it here so `/execute-plan` (which runs on the issue branch, with no
milestone version in its own branch name) doesn't have to re-derive it.

**File structure** (include only the sections that apply, per Step 7.4's list):

```markdown
# Issue #<NUMBER>: <Title>

**Branch:** `<branch-name>`
**Milestone:** `<milestone-branch>` (e.g. `milestone/v2.17.0`)
**Status:** Draft — pending approval

## Bug Escape Analysis
<!-- if bug issue -->

## UserSpice Integration
<!-- decision from Step 7.1 -->

## Database & Security Considerations
<!-- from Step 7.2 -->

## Architecture & Design
<!-- your approach, alternatives considered, why this one -->

## Implementation Checklist

Each item is one concrete, independently verifiable action. Mark file(s)
touched and parallel-safety so `/execute-plan` can decide fan-out and so any
agent can re-check completion against actual repo state.

- [ ] <Action> — `path/to/file.php` (parallel-safe)
- [ ] <Action> — `path/to/other-file.php` (parallel-safe)
- [ ] <Action> — `path/to/file.php` (depends on: <previous item's short name>)
- [ ] Run `senior-test-engineer`-authored tests, verify pass
- [ ] PHPStan baseline hygiene: confirm no touched file carries pre-existing
      `phpstan-baseline.neon` entries (fix or explicitly defer per
      `/execute-plan` Step 6.5)
- [ ] Run `/security-review` (if forms/SQL/auth touched), address Critical/High
- [ ] Run `senior-architect` review of the diff, address findings

## Test Plan
<!-- from senior-test-engineer, if consulted -->

## Documentation Plan
<!-- from technical-documentation-writer, if consulted -->
```

**Checklist item granularity**: one item per concrete action a single agent
run could complete and a later check could verify against repo state (a
function/method exists, a file was created, a test file exists and passes) —
not one item per broad phase like "Implementation" or "Testing".

**Parallel-safety annotations**: mark an item `(parallel-safe)` only if its
file(s) don't overlap with any other parallel-safe item's file(s) and it has
no ordering dependency on another item's output. Mark true dependencies with
`(depends on: <item>)`. When in doubt, do not mark parallel-safe — a false
`(depends on: ...)` costs a little serialized time; a false `(parallel-safe)`
risks two agents corrupting the same file.

After writing the file, present it for approval:

"I've written the implementation plan for issue #ISSUE_NUMBER to
`docs/plans/issue-<NUMBER>-<slug>.md`. Please review and let me know if
you'd like any changes before I mark it approved."

**STOP. Do not mark the plan approved, and do not end this command's turn
implying readiness for `/execute-plan`, until the user explicitly approves.**
A response that changes the subject, asks a follow-up question, or provides
partial feedback is NOT approval. If in doubt, ask: "Should I proceed with
the plan as written?"

If the user requests changes, edit the plan file directly and re-present it
— do not describe the changes in chat without updating the file. The file is
the artifact of record; chat-only revisions that never make it into the file
are exactly the drift this plan-file workflow exists to prevent.

Once approved, update the file's status line to `**Status:** Approved —
ready for /execute-plan` and stop. Do not proceed to implementation from
this command.

### Step 10: Hand Off to /execute-plan

This command's work is done once the plan file is approved (Step 9). State
plainly that the plan is approved and saved at
`docs/plans/issue-<NUMBER>-<slug>.md`, then use AskUserQuestion to offer the
next step rather than a plain-text menu:

- Question: "Plan approved. What next?"
- Options: `Run /execute-plan now` (recommended — this is the only real next
  step in the workflow), `Compact context first` (recommended before a long
  next step — the plan is already persisted to `docs/plans/`, so compacting
  here is safe and won't lose it), `Ask more questions / discuss the plan
  first`
- If the user picks `/execute-plan`, invoke it immediately via the Skill
  tool (`Skill({skill: "execute-plan"})`) rather than telling the user to
  type it themselves.
- If the user picks `Compact context first`, tell them to run `/compact`
  themselves — it's a client-level operation, not something this command can
  trigger via a tool.
- If the user picks the discuss option, drop back into normal conversation —
  do not re-offer the same question on every reply; only re-present it once
  the discussion reaches a natural stopping point or the user asks "what's
  next."

Do not implement anything, and do not update the issue or release notes from
this command — `/execute-plan` does that once there is actual work done to
describe.

## Critical Rules

- **PLAN APPROVAL IS A HARD GATE** — do not mark the plan file approved until
  the user explicitly approves it at Step 9. Partial feedback, silence, or a
  change of subject is NOT approval. Ask again if unclear.
- **THIS COMMAND NEVER WRITES APPLICATION CODE OR TOUCHES GIT** — no
  `git add`/`git commit`/`git push`, no software-developer agents, no
  implementation of any kind. That is entirely `/execute-plan`'s job.
- **The plan file is the artifact of record** — if the user requests changes
  during approval, edit the file directly and re-present it. Do not describe
  revisions only in chat.
- **Never assume — verify via code or ask.** When a question has an objective
  answer the codebase can settle (does this function exist, does this file
  already have a CSRF check, what pattern do other endpoints use), check the
  code — grep/read it, don't guess and don't ask the user something you can
  verify yourself. When it's a judgment call, a preference, or genuinely
  ambiguous scope, use AskUserQuestion — don't silently pick an answer either
  way. Never present something as settled without having done one of the two.
- **Ask questions ONE AT A TIME** - wait for each answer before asking the next
- **Continue asking questions WHILE IN PLAN MODE** - don't wait until
  after plan mode
- **Use AskUserQuestion tool** for every clarifying question, hand-off choice,
  and next-step recommendation — this command interviews via that tool, not
  free-form chat questions, so answers are structured and unambiguous
- **Follow project conventions** from CLAUDE.md and CODING_STANDARDS.md
- **Tier agent usage** - assess complexity first; Small issues skip PM and multi-agent Explore
- **Triage pre-existing issues immediately** (Step 5.5) — never silently note something as "pre-existing"; apply the
  containment + severity matrix and either fold it in, create an issue in the current milestone, or defer with `triage` label.
  Use `/found` for standalone capture.
- **Investigate testing gaps for bugs** - for `bug` labeled issues, include escape analysis in the plan
- **Verify UserSpice integration** (Step 7.1) - do not duplicate framework functionality
- **Assess database and security impacts** (Step 7.2) - identify schema changes and security requirements upfront
- **No architect call from this command** - architect reviews code after implementation, inside `/execute-plan`, not plans
- **Only invoke agents that are needed** - match agents to the issue type;
  skip the docs-scoping consult for internal refactoring, the test-strategy
  consult for docs-only changes
- **Checklist items must be concrete and independently verifiable** — one item
  per action a repo-state check could confirm, not one item per broad phase
- **Mark parallel-safety conservatively** — only mark `(parallel-safe)` when
  file sets truly don't overlap and there's no ordering dependency

## Examples

See `.claude/commands/start-issue-examples.md` for worked example flows
(reference only — not loaded at runtime; some describe the pre-plan-file
flow and may not reflect the current Step 9/10 split).
