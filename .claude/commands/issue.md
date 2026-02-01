---
description: Start work on a GitHub issue with branch creation, planning, and clarifying questions
---

# GitHub Issue Workflow Command

This command helps you start working on a GitHub issue by creating a branch,
entering plan mode, and developing an implementation plan with continuous
clarifying questions. Specialized agents are invoked as needed throughout the
workflow.

## Available Agents

Use the Task tool to launch these agents. Launch multiple agents in parallel
when they don't depend on each other. Launch multiple instances of the same
agent type when work can be partitioned (e.g., one Explore agent per subsystem,
one senior-test-engineer per test suite type).

| Agent Type | `subagent_type` | Model | Use When |
| --- | --- | --- | --- |
| **Explore** | `Explore` | `haiku` | Codebase research: find files, understand patterns, trace call chains. |
| **Plan** | `Plan` | `sonnet` | Design implementation strategy, identify critical files, evaluate trade-offs. |
| **Software Developer** | `software-developer` | `sonnet` | **Primary coding agent.** Write and update application code. |
| **Senior Architect** | `senior-architect` | `sonnet` | Architectural review, security audit, GDPR compliance, code review. |
| **Senior Product Manager** | `senior-product-manager` | `sonnet` | Issue refinement, scope definition, acceptance criteria, milestone planning. |
| **Senior Test Engineer** | `senior-test-engineer` | `sonnet` | Test strategy, writing PHPUnit/Playwright tests, debugging failures. |
| **Technical Documentation Writer** | `technical-documentation-writer` | `haiku` | Create/update docs, README, CLAUDE.md, API docs, release notes. |
| **General Purpose** | `general-purpose` | `haiku` | Multi-step research, web searches, complex analysis. |

**Model guidance:** For built-in agents (Explore, Plan, General Purpose), pass the
`model` parameter in the Task tool call. Project agents (software-developer,
senior-architect, senior-test-engineer, technical-documentation-writer) have their
model set in their `.claude/agents/*.md` frontmatter.

**Agent selection guidance:**

- **Always invoke**: Explore (for initial research), senior-product-manager
  (for issue refinement in Step 6, and proactively for issue creation,
  prioritization, and replanning), senior-architect (for review)
- **Always invoke for code changes**: software-developer (for implementation),
  senior-test-engineer (for tests)
- **Invoke when docs/config/public API changes**: technical-documentation-writer
- **Skip when not needed**: Don't launch docs agent for internal refactoring.
  Don't launch test agent for docs-only changes.
- **Scale up**: If the issue touches 3+ subsystems, launch parallel Explore
  agents. Launch parallel software-developer agents for independent files.
  If tests span PHPUnit and Playwright, launch separate test agents.

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

### Step 3: Determine Branch Name and Base Branch

Before creating a branch or entering plan mode, determine the branch details:

1. **Branch naming**: Use the issue labels to determine the branch prefix:
   - `bug` label → `bug/ISSUE_NUMBER-short-description`
   - `enhancement` or `feature` label → `feature/ISSUE_NUMBER-short-description`
   - All other labels (including `tech-debt`) → `issue/ISSUE_NUMBER-short-description`

   Present the proposed branch name and ask: "I'll create a branch named
   `PREFIX/ISSUE_NUMBER-short-description`. Does this work, or would you prefer
   a different name?"

2. **Base branch**: Default to the current milestone's feature branch if one
   exists (e.g., `milestone/v2.14.0`). Check for an active milestone branch:

   ```bash
   git branch --list 'milestone/*' 'feature/v*'
   ```

   If a milestone/feature branch exists that matches the issue's milestone,
   use it. Otherwise fall back to `main`.

   Ask: "I'll branch from `DETECTED_BASE_BRANCH`. Is that correct?"

Wait for answers before proceeding.

### Step 4: Create Branch

After getting branch preferences, create the branch:

```bash
git checkout -b BRANCH_NAME BASE_BRANCH
```

Confirm: "Created branch `BRANCH_NAME` from `BASE_BRANCH`"

### Step 5: Launch Explore Agents for Initial Research

Before asking questions, launch Explore agents via the Task tool to understand
the codebase context. Launch **multiple Explore agents in parallel** if the
issue touches different areas:

- One agent per subsystem/directory affected by the issue
- One agent to find existing patterns and conventions relevant to the change
- One agent to trace dependencies and call chains

Example: For a transfer system issue, launch parallel Explore agents for:

- `app/cars/` (transfer pages and actions)
- `usersc/classes/` (related classes)
- `tests/` (existing test coverage)

Wait for Explore results before proceeding to questions.

### Step 6: Interview Mode - Issue Refinement and Questions

Before asking human interview questions, **launch the senior-product-manager
agent** via the Task tool to analyze the issue for completeness and refinement
needs.

**Provide the PM agent with:**

- The full issue details (title, description, labels, milestone, acceptance criteria if present)
- The Explore results from Step 5
- Any obvious scope, clarity, or dependency concerns

**Ask the PM agent to evaluate:**

1. Is this issue well-defined and ready for implementation?
2. What's missing or unclear (acceptance criteria, edge cases, scope boundaries)?
3. Does this issue need decomposition? If so, how should it be split?
4. What questions should the orchestrator ask the user to refine this issue?
5. Is the milestone assignment and priority appropriate?
6. Are there dependencies on other issues or systems?

**Wait for the PM agent's assessment before proceeding.**

After receiving the PM agent's recommendations, interview the user using
AskUserQuestion. Incorporate the PM agent's suggested questions along with
your own technical, UI/UX, and implementation questions.

Ask about: scope clarity, acceptance criteria, edge cases, technical
implementation, UI/UX, concerns and tradeoffs. When providing options, tell
me what is the best known practice or the industry standard.

Make sure the questions are not obvious. Be very in-depth and continue until
it is complete.

**If the PM agent recommends issue decomposition or significant scope
changes**, discuss this with the user before proceeding to plan mode. The
user may want to update the issue or create new issues before implementation.

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

5. **Consult specialized agents**: Before finalizing the plan, determine which
   agents are needed based on the issue type, then launch them **in parallel**:

   - **senior-architect** (always): Provide the issue details, Explore results,
     your research findings, and proposed approach. Ask for review of: code
     structure, security implications, maintainability, GDPR compliance if
     applicable, and adherence to project patterns.

   - **senior-test-engineer** (when code changes are made): Provide the issue
     details and proposed implementation. Ask for a test strategy: PHPUnit tests
     (unit, integration, regression), Playwright browser tests, edge cases, and
     whether existing tests need updating. Launch **separate instances** if both
     PHPUnit and Playwright tests are needed.

   - **technical-documentation-writer** (when public API, config, user-facing
     behavior, or docs change): Provide the issue details and proposed changes.
     Ask which docs need updating and what changes should cover.

   - **Skip agents that aren't relevant**: Internal refactoring doesn't need
     the docs agent. Docs-only changes don't need the test agent.

6. **Incorporate agent feedback into the plan**: Merge feedback into a single
   comprehensive plan. Include sections only for agents that were consulted:
   - **Architecture & Design** (from senior-architect)
   - **Implementation Steps** (your plan, informed by architect feedback)
   - **Test Plan** (from senior-test-engineer, if consulted)
   - **Documentation Plan** (from technical-documentation-writer, if consulted)

### Step 8: Exit Plan Mode with Plan

Use ExitPlanMode when you have:

- Asked all necessary clarifying questions
- Explored all relevant code
- Consulted the appropriate specialized agents
- Created a comprehensive implementation plan

### Step 9: Present Plan for Approval

After exiting plan mode, present the plan and ask:

"Here's my implementation plan for issue #ISSUE_NUMBER based on our
discussion and agent input. Please review and let me know if you'd like any
changes before I proceed with implementation."

### Step 10: Implementation (after approval)

Once the user approves the plan, execute using agents strategically:

1. Update the issue with the plan details.

2. **Launch software-developer agents to implement code changes.** Partition
   the work by file or subsystem and launch **parallel instances**:

   - One software-developer agent per independent file or group of related files
   - Provide each agent with: the approved plan (its portion), the file(s) to
     modify, and any relevant context from the Explore/architect research
   - Example: For 3 independent files, launch 3 software-developer agents
     simultaneously. For 2 tightly coupled files, use 1 agent for both.

3. **Launch agents in parallel for post-implementation work.** Only launch
   agents that are relevant to the changes made:

   - **senior-test-engineer**: Write and run tests from the test plan. Launch
     **separate instances** for different test types if needed (e.g., one for
     PHPUnit unit tests, one for Playwright browser tests). Provide each
     instance with the implementation details and its portion of the test plan.

   - **technical-documentation-writer**: Update docs per the documentation
     plan. Run in parallel with test agents when there are no dependencies.

4. Run quality checks:
   - `mcp__ide__getDiagnostics` to check for linting/type errors
   - Relevant test suites (verify the test agent's tests pass)

5. **Launch senior-architect agent** for final review of the completed changes.
   Provide the diff of all changes and ask for a code review focusing on
   security, maintainability, and adherence to project standards.

6. Address any issues raised by the architect review. If fixes are needed,
   launch software-developer agents again for the corrections.

7. Ask if the user wants to:
   - Create a commit
   - Create a pull request (see PR guidelines below)
   - Continue with additional changes

### Pull Request Guidelines

When creating a PR, target the **milestone branch** (not `main`). Include
GitHub closing keywords in the PR body to auto-close the issue on merge:

```text
Closes #ISSUE_NUMBER
```

Example PR creation:

```bash
gh pr create --base milestone/v2.14.0 --title "Issue #369: Add strict types to edit.php" --body "$(cat <<'EOF'
## Summary
- Added declare(strict_types=1)
- Replaced generic exceptions with typed classes

Closes #369

## Test plan
- [ ] Pre-commit hooks pass
- [ ] composer test:quick passes

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

**Do NOT close issues manually with `gh issue close`.** Issues are closed
automatically when the PR merges into the milestone branch.

### Update Draft Release Notes

After creating the PR (or commit if no PR), update the draft release notes
for the milestone at `docs/releases/RELEASE_NOTES_vX.Y.Z.md`:

1. **If the file doesn't exist yet**, create it from the template at
   `docs/development/RELEASE_NOTES_TEMPLATE.md` with the milestone version.

2. **Add the issue's changes** to the appropriate section(s):
   - User-facing changes → `## 👤 User-Facing Changes`
   - Bug fixes → `### Bug Fixes`
   - Technical/internal changes → `## 🔧 Technical Changes`
   - Include the issue number as a reference: `(#ISSUE_NUMBER)`

3. **Keep it cumulative** — append to existing entries, don't replace them.
   Each issue adds its line items to the draft.

4. **Use the technical-documentation-writer agent** (`haiku`) to write the
   release notes entry if the changes are non-trivial.

## Critical Rules

- **Ask questions ONE AT A TIME** - wait for each answer before asking the next
- **Continue asking questions WHILE IN PLAN MODE** - don't wait until
  after plan mode
- **Use AskUserQuestion tool** when appropriate for multiple-choice questions
- **Follow project conventions** from CLAUDE.md and CODING_STANDARDS.md
- **Read before modifying** - always read files before suggesting changes
- **Test thoroughly** - run diagnostics and tests before considering work complete
- **Launch Explore agents early** - use parallel Explore agents in Step 5 to
  build context before asking questions or planning
- **Only invoke agents that are needed** - match agents to the issue type;
  skip docs agent for internal refactoring, skip test agent for docs-only changes
- **Scale agents up** - launch multiple instances of the same agent type when
  work can be partitioned (e.g., separate Explore agents per subsystem, separate
  test agents for PHPUnit vs Playwright)
- **Run independent agents in parallel** - when agents don't depend on each
  other's output, launch them simultaneously for efficiency
- **Never close issues manually** - use `Closes #NNN` in the PR body so
  issues close automatically on merge
- **PR target is the milestone branch** - never PR directly to `main` for
  issue work

## Example Flow

```text
User: /issue 423

You: I'll help you work on issue #423. Let me fetch the details...

[fetches issue details]

Issue #423: Add export functionality for car data
Status: Open
Labels: enhancement
Milestone: v3.2.0

I see this is labeled `enhancement`, so I'll create a branch named
`feature/423-car-data-export`. Does this work, or would you prefer a
different name?

User: That's fine

You: I found a `milestone/v3.2.0` branch matching the issue milestone.
I'll branch from that. Is that correct?

User: Yes

You: [creates branch]

Created branch `feature/423-car-data-export` from `milestone/v3.2.0`.

Let me explore the relevant areas of the codebase first...

[launches 3 Explore agents IN PARALLEL via Task tool:
 - Explore agent 1: app/cars/ (listing pages, DataTables config)
 - Explore agent 2: app/reports/ (existing export patterns)
 - Explore agent 3: tests/ (existing test coverage for car listings)]

[waits for Explore results]

Now let me get product management input on this issue...

[launches senior-product-manager agent via Task tool to assess issue quality,
 completeness, scope, and recommend refinement questions]

[waits for PM assessment]

Based on the PM agent's feedback and the research, I have some questions...

I see we have export functionality in `/app/reports/`. I found two approaches:
1. Add export buttons to existing car listing pages
2. Create a new dedicated export page

Which approach would you prefer?

User: Add buttons to existing pages

You: The car listing uses DataTables. Should the export include:
A) Only currently filtered/searched results
B) All cars in the registry

User: Only filtered results

You: [enters plan mode]
[asks more questions as they arise]

Now let me consult the specialized agents for their input...

[launches IN PARALLEL via Task tool:
 - senior-architect: review proposed approach
 - senior-test-engineer (PHPUnit): unit/integration test strategy
 - senior-test-engineer (Playwright): browser test strategy
 - technical-documentation-writer: docs impact assessment]

[incorporates feedback into plan]
[exits plan mode]

Here's my implementation plan based on our discussion and agent input...

[after approval]

[launches software-developer agents IN PARALLEL via Task tool:
 - software-developer 1: implement export buttons in car listing page
 - software-developer 2: implement AJAX export endpoint
 - software-developer 3: add DataTables export configuration]

[launches IN PARALLEL after code is written:
 - senior-test-engineer: write and run PHPUnit tests
 - senior-test-engineer: write and run Playwright tests
 - technical-documentation-writer: update docs]

[launches senior-architect for final code review]
[addresses any review feedback - launches software-developer if fixes needed]

All changes implemented, tested, documented, and reviewed. Would you like me
to create a commit?

User: Yes, and create a PR

You: [commits changes]
[creates PR targeting milestone/v3.2.0 with "Closes #423" in the body]

PR created: feature/423-car-data-export → milestone/v3.2.0
Issue #423 will close automatically when the PR is merged.
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
