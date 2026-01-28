---
description: Start work on a GitHub issue with branch creation, planning, and clarifying questions
---

# GitHub Issue Workflow Command

This command helps you start working on a GitHub issue by creating a branch,
entering plan mode, and developing an implementation plan with continuous
clarifying questions. Specialized agents (senior-architect, senior-test-engineer,
docs-maintainer) contribute to both planning and execution.

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

### Step 3: Initial Clarifying Questions (ONE AT A TIME)

Before creating a branch or entering plan mode, ask these questions ONE AT A TIME:

1. **Branch naming**: "I'll create a branch named `issue-ISSUE_NUMBER-short-description`.
   Does this naming work for you, or would you prefer a different name?"

2. **Base branch**: "Which branch should I create the new branch from?
   (default: main)"

Wait for answers before proceeding.

### Step 4: Create Branch

After getting branch preferences, create the branch:

```bash
git checkout -b BRANCH_NAME BASE_BRANCH
```

Confirm: "Created branch `BRANCH_NAME` from `BASE_BRANCH`"

### Step 5: Enter Interview Mode and Ask Questions Throughout

Read the issue and interview me using the AskUserQuestion tool.

Ask about: technical implementation, UI/UX, concerns and tradeoffs. When providing
options, tell me what is the best known practice or the industry standard.

Make sure the questions are not obvious. Be very in-depth and continue until it
is complete.

### Step 6: Enter Plan Mode and Ask Questions Throughout

Use the EnterPlanMode tool and explain:

"I'm entering plan mode to research and create an implementation plan. I'll ask
clarifying questions as I explore the codebase."

**While in plan mode:**

1. **Initial exploration**: Read relevant documentation and explore code to
   understand the context

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
   exploration and planning

4. **Ask follow-up questions as needed**: Don't batch questions - ask them
   naturally as you work through the planning process

5. **Consult specialized agents**: Before finalizing the plan, launch these
   agents **in parallel** using the Task tool to get expert input:

   - **senior-architect** (subagent_type: `senior-architect`): Provide the
     issue details, your research findings, and ask for architectural review
     of your proposed approach. The architect should evaluate: code structure,
     security implications, maintainability, GDPR compliance if applicable,
     and adherence to project patterns.

   - **senior-test-engineer** (subagent_type: `senior-test-engineer`): Provide
     the issue details and proposed implementation approach. Ask for a test
     strategy covering: what PHPUnit tests to write (unit, integration,
     regression), what Playwright browser tests to add, edge cases to cover,
     and whether existing tests need updating.

   - **docs-maintainer** (subagent_type: `docs-maintainer`): Provide the issue
     details and proposed changes. Ask which documentation files need updating,
     whether new documentation is needed, and what the documentation changes
     should cover.

6. **Incorporate agent feedback into the plan**: Merge the architectural
   guidance, test strategy, and documentation plan into a single comprehensive
   implementation plan. The final plan should have clearly labeled sections:
   - **Architecture & Design** (from senior-architect)
   - **Implementation Steps** (your plan, informed by architect feedback)
   - **Test Plan** (from senior-test-engineer)
   - **Documentation Plan** (from docs-maintainer)

### Step 7: Exit Plan Mode with Plan

Use ExitPlanMode when you have:

- Asked all necessary clarifying questions
- Explored all relevant code
- Consulted all three specialized agents
- Created a comprehensive implementation plan with architecture, test, and
  documentation sections

### Step 8: Present Plan for Approval

After exiting plan mode, present the plan and ask:

"Here's my implementation plan for issue #ISSUE_NUMBER based on our
discussion and input from the architecture, testing, and documentation agents.
Please review and let me know if you'd like any changes before I proceed with
implementation."

### Step 9: Implementation (after approval)

Once the user approves the plan, execute the implementation using specialized
agents for their respective areas:

1. Update the issue with the plan details
2. **Implement code changes** following the architecture and implementation
   sections of the plan
3. **Launch senior-test-engineer agent** (subagent_type: `senior-test-engineer`)
   to write and execute the tests defined in the test plan. Provide the agent
   with the implementation details and the test plan from Step 6.
4. **Launch docs-maintainer agent** (subagent_type: `docs-maintainer`) to
   create or update documentation per the documentation plan from Step 6.
   Run this in parallel with the test engineer when there are no dependencies.
5. Run quality checks:
   - `mcp__ide__getDiagnostics` to check for linting/type errors
   - Relevant test suites (verify the test engineer's tests pass)
6. **Launch senior-architect agent** (subagent_type: `senior-architect`) for a
   final review of the completed changes. Provide the diff of all changes and
   ask for a code review focusing on security, maintainability, and adherence
   to project standards.
7. Address any issues raised by the architect review
8. Ask if the user wants to:
   - Create a commit
   - Create a pull request
   - Continue with additional changes

## Critical Rules

- **Ask questions ONE AT A TIME** - wait for each answer before asking the next
- **Continue asking questions WHILE IN PLAN MODE** - don't wait until
  after plan mode
- **Use AskUserQuestion tool** when appropriate for multiple-choice questions
- **Follow project conventions** from CLAUDE.md and CODING_STANDARDS.md
- **Read before modifying** - always read files before suggesting changes
- **Test thoroughly** - run diagnostics and tests before considering work complete
- **Consult agents during planning** - launch senior-architect,
  senior-test-engineer, and docs-maintainer in parallel during plan creation
- **Use agents during execution** - delegate testing, documentation, and
  final review to their respective specialized agents
- **Run independent agents in parallel** - when agents don't depend on each
  other's output, launch them simultaneously for efficiency

## Example Flow

```text
User: /issue 423

You: I'll help you work on issue #423. Let me fetch the details...

[fetches issue details]

Issue #423: Add export functionality for car data
Status: Open
Milestone: v3.2.0

I'll create a branch named `issue-423-car-data-export`. Does this work, or
would you prefer a different name?

User: That's fine

You: Which branch should I create this from? (default: main)

User: main

You: [creates branch]

Created branch `issue-423-car-data-export` from `main`.

I'm entering plan mode to research and create an implementation plan. I'll ask
clarifying questions as I explore the codebase.

[enters plan mode]
[reads CLAUDE.md, explores code]

I see we have export functionality in `/app/reports/`. I found two approaches:

1. Add export buttons to existing car listing pages
2. Create a new dedicated export page

Which approach would you prefer?

User: Add buttons to existing pages

You: [continues exploration]

I see the car listing uses DataTables. Should the export include:
A) Only currently filtered/searched results
B) All cars in the registry

Which should it be?

User: Only filtered results

You: [continues planning based on answers]
[asks more questions as they come up]

Now let me consult the specialized agents for their input...

[launches senior-architect, senior-test-engineer, and docs-maintainer
 agents IN PARALLEL via Task tool]

[incorporates feedback into comprehensive plan with Architecture,
 Implementation, Test, and Documentation sections]

[exits plan mode]

Here's my implementation plan based on our discussion and agent input...

[after approval, implements changes]
[launches senior-test-engineer to write and run tests]
[launches docs-maintainer to update documentation - in parallel with tests]
[launches senior-architect for final code review]
[addresses any review feedback]

All changes implemented, tested, documented, and reviewed. Would you like me
to create a commit?
```

## Plan Mode Question Guidelines

**Good questions to ask in plan mode:**

- Approach decisions: "Should I use X or Y pattern?"
- Scope clarifications: "Should this also handle Z?"
- Edge case handling: "What should happen when...?"
- Integration decisions: "Should this integrate with existing feature X?"
- Preference choices: "I see both approaches used - which for this case?"

**Don't ask questions you can figure out yourself:**

- Code style questions (follow CODING_STANDARDS.md)
- Existing patterns (use what's already in the codebase)
- Documentation location (follow project structure)
