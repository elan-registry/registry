---
description: Start work on a GitHub issue within a milestone workflow
---

# GitHub Issue Workflow Command

This command helps you start working on a GitHub issue within a milestone
workflow by creating a branch, entering plan mode, and developing an
implementation plan with continuous clarifying questions. Specialized agents are
invoked as needed throughout the workflow.

## Available Agents

Launch agents via the Task tool. Use parallel instances when work can be partitioned.

| Agent | `subagent_type` | Model | Use When |
| --- | --- | --- | --- |
| Explore | `Explore` | `haiku` | Codebase research |
| Plan | `Plan` | `sonnet` | Implementation strategy |
| Software Developer | `software-developer` | `sonnet` | **Primary coding agent** |
| Senior Architect | `senior-architect` | `sonnet` | Architecture, security, code review |
| Senior Product Manager | `senior-product-manager` | `sonnet` | Issue refinement, scope, criteria |
| Senior Test Engineer | `senior-test-engineer` | `sonnet` | Test strategy and writing |
| Technical Documentation Writer | `technical-documentation-writer` | `haiku` | Docs updates |
| General Purpose | `general-purpose` | `haiku` | Multi-step research |

**Always invoke:** Explore (Step 5), senior-product-manager (Step 6), senior-architect (Step 7.3).
**Always invoke for code changes:** software-developer, senior-test-engineer.
**Skip** docs agent for internal refactoring; test agent for docs-only changes.
**Scale up** with parallel instances for large issues (3+ subsystems, PHPUnit + Playwright).

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

Before asking questions, launch Explore agents via the Task tool to understand
the codebase context. Launch **multiple Explore agents in parallel** if the
issue touches different areas:

- One agent per subsystem/directory affected by the issue
- One agent to find existing patterns and conventions relevant to the change
- One agent to trace dependencies and call chains

**Key documentation to reference during exploration:**

Each Explore agent should check these project documentation files for patterns,
conventions, and existing functionality:

- **USERSPICE_FUNCTIONS.md** - Existing UserSpice framework functions (avoid duplication)
- **CLASSES.md** - Custom application classes (Car, CarView, ElanRegistryOwner, etc.)
- **CODING_STANDARDS.md** - PHP 8+ typing, structure, naming conventions
- **ERROR_HANDLING.md** - Error handling patterns, API response format, exception classes
- **DATABASE.md** - Database schema, relationships, audit trail patterns
- **DEPLOYMENT.md** - Environment-specific considerations

Example: For a transfer system issue, launch parallel Explore agents for:

- `app/cars/` (transfer pages and actions)
- `usersc/classes/` (related classes, check CLASSES.md)
- `tests/` (existing test coverage)

Each agent should also verify: Are similar features already implemented? Do they
follow UserSpice patterns or custom patterns? What security/database considerations
apply?

#### For Bug Issues (bug label): Investigate Testing Gaps

If the issue has a `bug` label, **add a dedicated Explore agent** to investigate:

- Why wasn't this bug caught by existing tests?
- What test coverage gap allowed this to reach production?
- Are there similar untested code paths in related areas?
- What type of test would have prevented this (unit, integration, browser)?

This "escape analysis" is critical for preventing similar bugs. Document findings.

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

7. **Consult specialized agents** (Step 7.3): Before finalizing the plan, determine which
   agents are needed based on the issue type, then launch them **in parallel**:

   - **senior-architect** (always): Provide the issue details, Explore results,
     your research findings, and proposed approach. Ask for review of:

     **Architecture & Standards:**
     - Code structure and PHP 8+ type hints compliance
     - Adherence to CODING_STANDARDS.md
     - Alignment with existing project patterns (CLASSES.md, error handling)
     - UserSpice integration (don't duplicate framework functionality)

     **Security Review:**
     - CSRF token handling (all forms must have tokens)
     - SQL injection prevention (all queries must use prepared statements)
     - Input validation and sanitization (all user inputs)
     - XSS prevention (output escaping)
     - Session/authentication security
     - Sensitive data handling (never commit credentials)

     **Database & Compliance:**
     - Database schema changes and trigger impacts
     - Audit trail implications (when records are modified)
     - GDPR compliance (if handling personal data)
     - Data retention and cleanup policies

     **Maintainability:**
     - Code clarity and documentation
     - Test coverage adequacy
     - Future refactoring considerations

   - **senior-test-engineer** (when code changes are made): Provide the issue
     details and proposed implementation. Ask for a comprehensive test strategy
     using this checklist (skip items that don't apply):

     **Test Strategy Checklist:**
     - [ ] Unit tests (PHPUnit) - test individual functions/methods
     - [ ] Integration tests (PHPUnit) - test component interactions
     - [ ] Regression tests - test that existing functionality still works
     - [ ] Edge case tests - boundary conditions, error scenarios
     - [ ] Browser tests (Playwright) - UI interaction, form submission, navigation
     - [ ] Security tests - CSRF validation, XSS prevention, injection prevention
     - [ ] Database tests - audit trail creation, trigger execution if applicable
     - [ ] Existing tests - need updates due to API/behavior changes?

     Launch **separate instances** if both PHPUnit and Playwright tests are
     needed.

   - **technical-documentation-writer** (when changes affect documentation):
     Use the **Documentation Requirements Matrix** below to determine if docs
     updates are needed. Provide the agent with: issue details, proposed changes,
     and which docs to update.

     **Documentation Requirements Matrix:**

     | Change Type | Documentation to Update |
     | --- | --- |
     | **Database schema changes** | DATABASE.md, CLASSES.md (if class models change) |
     | **API endpoints added/changed** | FRONTEND_API_GUIDE.md, ERROR_HANDLING.md (if response format changes) |
     | **New PHP classes/methods** | CLASSES.md, USERSPICE_QUICK_LOOKUP.md (if public API) |
     | **User-visible features** | User guides (in `docs/user/` if exists), release notes |
     | **Configuration/environment changes** | ENVIRONMENT.md, DEPLOYMENT.md |
     | **Coding standards violations fixed** | CODING_STANDARDS.md (if pattern changed) |
     | **Security patterns added** | ERROR_HANDLING.md, CODING_STANDARDS.md |
     | **UserSpice integration changes** | Integration guide (Wiki), USERSPICE_FUNCTIONS.md |
     | **Testing patterns/infrastructure** | TESTING.md |
     | **Deployment procedure changes** | DEPLOYMENT.md, QUICK_REFERENCE.md |

     Then ask: "Based on these changes, which documentation should be updated?"

   - **Skip agents that aren't relevant**: Internal refactoring that doesn't
     change APIs doesn't need the docs agent. Docs-only changes don't need the
     test agent.

8. **Incorporate agent feedback into the plan**: Merge feedback into a single
   comprehensive plan. Include sections only for agents that were consulted:
   - **Bug Escape Analysis** (from Step 7.2.5, if bug issue)
   - **UserSpice Integration** (from Step 7.1)
   - **Database & Security Considerations** (from Step 7.2)
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

5. **Run `/security-review`**: Launch the security-reviewer agent via the
   Agent tool with `subagent_type: "security-reviewer"` to audit all changed
   files. Provide the agent with the full diff of changes. Address any
   Critical or High severity findings before proceeding.

6. **Launch senior-architect agent** for final review of the completed changes.
   Provide the diff of all changes and ask for comprehensive code review:

   - **Security verification**: CSRF tokens, prepared statements, input validation, XSS prevention
   - **Database verification**: Schema consistency, trigger execution, audit trail logging
   - **Code quality**: PHP 8+ types, readability, maintainability
   - **Standards adherence**: CODING_STANDARDS.md, error handling patterns, project conventions
   - **Test coverage**: Are tests comprehensive? Do they cover security and edge cases?
   - **Documentation**: Are docs complete and accurate?

7. Address any issues raised by the security review or architect review. If
   fixes are needed, launch software-developer agents again for the
   corrections.

8. **Hand off to the developer workflow.** Do NOT commit, push, or create PRs.
   **STOP HERE and wait for the user's explicit instruction before proceeding.**
   Present a summary with the next steps and ask the user which step to run:

   ```text
   Implementation complete for issue #ISSUE_NUMBER. Next steps:

   1. /simplify        — Review and clean up the code (optional)
   2. /review-pr       — Run the multi-agent local review (RECOMMENDED before
                         push; uses your Max/Pro subscription so CI can stay
                         cheap)
   3. /commit          — Commit your changes
   4. /commit-push-pr  — Push and create a PR targeting `MILESTONE_BRANCH`
                         Include "Closes #ISSUE_NUMBER" in the PR body.
   5. /finish-issue    — Monitor CI, squash-merge, and close the issue
   ```

   > **Why `/review-pr` before push?** CI runs a lightweight Sonnet backstop
   > on issue PRs and relies on the author having done a deep review locally.
   > Running `/review-pr` here catches issues on your plan instead of burning
   > CI tokens on repeated pushes. Note: at this stage it reviews working-tree
   > changes (`git diff HEAD`) — run it before `/commit` so you can act on
   > findings without an amended commit.

   Do NOT run any of these steps automatically. Each step requires the user
   to explicitly invoke it (e.g., type `/commit` or `/commit-push-pr`).

   **For bug issues**, also remind the user to include the escape analysis
   in the PR description:

   ```text
   Remember: Include the bug escape analysis in the PR description so
   reviewers can verify preventive test coverage.
   ```

### Update Draft Release Notes

Before handing off, update the draft release notes for the milestone. Extract
the version from the milestone branch name (e.g., `milestone/v2.17.0` ->
`v2.17.0`) and update `docs/releases/RELEASE_NOTES_vX.Y.Z.md`:

1. **If the file doesn't exist yet**, create it from the template at
   `docs/development/RELEASE_NOTES_TEMPLATE.md` with the milestone version.

2. **Add the issue's changes** to the appropriate section(s):
   - User-facing changes -> `## User-Facing Changes`
   - Bug fixes -> `### Bug Fixes`
   - Technical/internal changes -> `## Technical Changes`
   - Include the issue number as a reference: `(#ISSUE_NUMBER)`

3. **Keep it cumulative** -- append to existing entries, don't replace them.
   Each issue adds its line items to the draft.

4. **Use the technical-documentation-writer agent** (`haiku`) to write the
   release notes entry if the changes are non-trivial.

## Critical Rules

- **NEVER commit code** - `/start-issue` does not commit, push, or create PRs.
  After implementation is complete, stop and tell the user to continue with
  `/simplify`, `/review-pr` (recommended), `/commit`, then `/commit-push-pr`.
- **Issue PRs MUST target the milestone branch** - never target `main` directly.
  The issue PR targets `milestone/vX.Y.Z`. Only the final milestone PR
  (created by `/finish-milestone`) targets `main`.
- **Remind user to use `/finish-issue`** - after the PR passes review and CI,
  the user should run `/finish-issue` to squash-merge and clean up.
- **Ask questions ONE AT A TIME** - wait for each answer before asking the next
- **Continue asking questions WHILE IN PLAN MODE** - don't wait until
  after plan mode
- **Use AskUserQuestion tool** when appropriate for multiple-choice questions
- **Follow project conventions** from CLAUDE.md and CODING_STANDARDS.md
- **Read before modifying** - always read files before suggesting changes
- **Test thoroughly** - run diagnostics and tests before considering work complete
- **Launch Explore agents early** - use parallel Explore agents in Step 5 to
  build context before asking questions or planning
- **Check documentation during Explore** - agents must review USERSPICE_FUNCTIONS.md,
  CLASSES.md, CODING_STANDARDS.md, and other relevant docs for patterns
- **Investigate testing gaps for bugs** (Step 5 & 7.2.5) - For `bug` labeled issues,
  analyze why the bug escaped testing and document preventive automated tests
- **Verify UserSpice integration** (Step 7.1) - do not duplicate framework
  functionality; leverage existing UserSpice functions
- **Assess database and security impacts** (Step 7.2) - identify schema changes,
  audit trail impacts, security requirements, and GDPR implications upfront
- **Security architecture review** - senior-architect must verify CSRF tokens,
  prepared statements, input validation, XSS prevention, and data handling
- **Mandatory test checklist** - test engineer uses the provided checklist to
  identify all test types needed (unit, integration, regression, browser, security)
- **Documentation requirements matrix** - use the matrix to determine which docs
  need updating based on the change type
- **Only invoke agents that are needed** - match agents to the issue type;
  skip docs agent for internal refactoring, skip test agent for docs-only changes
- **Scale agents up** - launch multiple instances of the same agent type when
  work can be partitioned (e.g., separate Explore agents per subsystem, separate
  test agents for PHPUnit vs Playwright)
- **Run independent agents in parallel** - when agents don't depend on each
  other's output, launch them simultaneously for efficiency
- **Never close issues manually** - use `Closes #NNN` in the PR body so
  issues close automatically on merge

## Example Flow

```text
User: /start-issue 423

You: I'll help you work on issue #423. Let me fetch the details...

[fetches issue details]

Issue #423: Add export functionality for car data
Status: Open
Labels: enhancement
Milestone: v3.2.0

[checks current branch]

You're on `milestone/v3.2.0` -- good.

I see this is labeled `enhancement`, so I'll create a branch named
`feature/423-car-data-export` from `milestone/v3.2.0`. Does this work,
or would you prefer a different name?

User: That's fine

You: [creates branch and pushes to remote]

Created branch `feature/423-car-data-export` from `milestone/v3.2.0` and
pushed to remote.

[marks issue as in progress, assigns to user]

Marked issue #423 as in progress and assigned to you.

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

Now let me verify UserSpice integration and assess database/security impacts...

[checks USERSPICE_FUNCTIONS.md for existing export functionality]
[identifies that DataTables has built-in export capabilities - leverage UserSpice]

[assesses database impacts: export doesn't require schema changes, no new audit trails]
[assesses security: export data is already public, CSRF token required on form]

Now let me consult the specialized agents for their input...

[launches IN PARALLEL via Task tool:
 - senior-architect: review proposed approach (including security/database verification)
 - senior-test-engineer (PHPUnit): unit/integration/security test strategy
 - senior-test-engineer (Playwright): browser test strategy
 - technical-documentation-writer: docs impact assessment (using matrix)]

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

All changes implemented, tested, documented, and reviewed.

Implementation complete for issue #423. Next steps:

1. /simplify        — Review and clean up the code (optional)
2. /review-pr       — Multi-agent local review (recommended before push)
3. /commit          — Commit your changes
4. /commit-push-pr  — Push and create a PR targeting `milestone/v3.2.0`
                      Include "Closes #423" in the PR body.
5. /finish-issue    — Monitor CI, squash-merge, and close the issue
```

## Bug Issue Example Flow

Here's how a bug issue flows through the enhanced workflow:

```text
User: /start-issue 512

You: I'll help you work on issue #512. Let me fetch the details...

Issue #512: Car price accepts negative values
Status: Open
Labels: bug
Milestone: v2.16.0

[checks current branch]

You're on `milestone/v2.16.0` -- good.

I see this is a bug issue. I'll create a branch named
`bug/512-negative-price-validation` from `milestone/v2.16.0`. Does this work?

User: Yes

You: [creates branch and pushes to remote]

Created branch `bug/512-negative-price-validation` from `milestone/v2.16.0`
and pushed to remote.

[marks issue as in progress, assigns to user]

Marked issue #512 as in progress and assigned to you.

Let me explore the code and investigate why this bug wasn't caught by tests...

[launches 2 Explore agents IN PARALLEL:
 - Explore agent 1: app/cars/ (price input handling and validation)
 - Explore agent 2: tests/ (existing validation tests, why gap exists)]

[Explore agents investigate:]
- Price input in car registration form doesn't validate for negative values
- Recent refactor removed validation logic from form processing
- No PHPUnit test exists for price input validation
- Similar price inputs in other forms (update car) also lack validation tests

Based on the investigation:

**Why it escaped:** Price validation was removed in a refactor, but no regression
tests existed. The input acceptance wasn't covered by PHPUnit or browser tests.

Should I proceed with understanding the full scope and creating a preventive
test strategy?

User: Yes, continue

[enters plan mode]
[asks clarifying questions about expected behavior]

User: Price should reject negatives and zero; minimum is $1

Now let me create the escape analysis and preventive measures...

[documents in plan:]
**Bug Escape Analysis:**
- Root Cause: Price validation removed in form refactor (commit xyz)
- Testing Gap: No unit test for price validation; code path never tested
- Preventive: Add PHPUnit test for price input validation
- Related: Other price inputs (car update form) also need similar tests

[launches senior-architect for review]
[launches senior-test-engineer with explicit test strategy for validation tests]

Here's my plan with escape analysis and preventive tests...

[after approval]

[implements fix + validation]
[writes comprehensive tests for price validation]

Implementation complete for issue #512. Next steps:

1. /simplify        — Review and clean up the code (optional)
2. /review-pr       — Multi-agent local review (recommended before push)
3. /commit          — Commit your changes
4. /commit-push-pr  — Push and create a PR targeting `milestone/v2.16.0`
                      Include "Closes #512" in the PR body.
5. /finish-issue    — Monitor CI, squash-merge, and close the issue

Remember: Include the bug escape analysis in the PR description so
reviewers can verify preventive test coverage.
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
