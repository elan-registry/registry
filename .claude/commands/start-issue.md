---
description: Start work on a GitHub issue within a milestone workflow
model: claude-opus-4-7
---

# GitHub Issue Workflow Command

## Step 0: Defer TaskList Until Tier Is Known

Do NOT create tasks yet. Fetch the issue (Step 2) and assess complexity tier
first. After Step 2, create only the tasks that apply to the determined tier:

- **Small** (1-2 files, clear scope): 6 tasks — fetch issue + assess, branch +
  mark in progress, explore, implement, test + security review, final summary
- **Medium** (3-5 files, some ambiguity): 8 tasks — fetch issue + assess,
  branch + mark in progress, explore, PM refinement + plan, implement, test,
  security review + architect review, final summary
- **Large** (new subsystem, schema changes, cross-cutting): 10 tasks — all of
  the above plus separate plan confirmation and documentation step

Set each to `in_progress`/`completed` as you progress.

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
| Software Developer | `software-developer` | `sonnet` (Trivial/Small), `opus` (Medium/Large) | **Primary coding agent** — see per-tier override below |
| Senior Architect | `senior-architect` | `sonnet` | Architecture, security, code review |
| Senior Product Manager | `senior-product-manager` | `sonnet` | Issue refinement, scope, criteria |
| Senior Test Engineer | `senior-test-engineer` | `sonnet` | Test strategy and writing |
| Technical Documentation Writer | `technical-documentation-writer` | `haiku` | Docs updates |
| General Purpose | `general-purpose` | `haiku` | Multi-step research |

**Scale agent usage to issue complexity** — see tiers below. Over-invoking agents is waste.
**Always invoke for code changes:** software-developer, senior-test-engineer (unless trivial fix).
**Skip** docs agent for internal refactoring; test agent for docs-only changes.

**Per-tier model override for `software-developer`** — the agent's default
model (set in `.claude/agents/software-developer.md`) is Opus. For
Trivial/Small issues, override to Sonnet to avoid Opus overkill on routine
CRUD-style work:

```text
Agent({
  subagent_type: "software-developer",
  model: "sonnet",          // Trivial/Small only — omit for Medium/Large
  description: "...",
  prompt: "..."
})
```

Omit `model` for Medium/Large issues so the agent inherits its default
(Opus) — those tiers need the deeper reasoning.

## Issue Complexity Tiers

Assess complexity immediately after fetching the issue. Choose the tier and follow its workflow.

| Tier | Profile | Agent pattern |
| --- | --- | --- |
| **Small** | 1-2 files, clear scope, explicit acceptance criteria, no DB/security changes | 1 Explore → software-developer → security-reviewer (if forms/SQL touched) |
| **Medium** | Feature, 3-5 files, some ambiguity, or touches DB/auth | 1-2 Explore → PM (if scope unclear) → Plan → software-developer → test engineer → security-reviewer |
| **Large** | New subsystem, schema changes, cross-cutting concern, or significant ambiguity | Full workflow below |

For Small issues skip: PM agent, pre-implementation architect call, parallel Explore agents.
For Medium issues skip: pre-implementation architect call (architect reviews code, not plans).

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

   - **technical-documentation-writer** (only when changes affect public APIs, schema,
     classes, or user flows): Ask which docs need updating based on the change type.

   Do NOT launch senior-architect pre-implementation. Architect review happens post-implementation (Step 10.6) when there is actual code to review.

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
   - **Model override by tier:** Pass `model: "sonnet"` for Trivial/Small
     issues. Omit `model` for Medium/Large (agent default is Opus). See the
     per-tier override note in the agent table at the top of this file.

3. **Launch agents in parallel for post-implementation work.** Only launch
   agents that are relevant to the changes made:

   - **senior-test-engineer**: Write and run tests from the test plan. Launch
     **separate instances** for different test types if needed (e.g., one for
     PHPUnit unit tests, one for Playwright browser tests). Provide each
     instance with the implementation details and its portion of the test plan.

   - **technical-documentation-writer**: Update docs per the documentation
     plan. Run in parallel with test agents when there are no dependencies.

4. Run quality checks:
   - Relevant test suites (verify the test agent's tests pass)
   - Pre-commit hooks run PHPStan and phpcs on staged files — these catch type and lint errors

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

   **Model by tier:** For Large issues pass `model: "opus"` to the architect
   agent — cross-cutting changes benefit from deeper reasoning. For Small and
   Medium issues omit `model` (agent inherits its Sonnet default).

7. Address any issues raised by the security review or architect review. If
   fixes are needed, launch software-developer agents again for the
   corrections.

8. **Hand off to the developer workflow.** Do NOT commit, push, or create PRs.
   **STOP HERE and wait for the user's explicit instruction before proceeding.**
   Present a summary with the next steps and ask the user which step to run:

   ```text
   Implementation complete for issue #ISSUE_NUMBER. Next steps:

   0. Update test plan  — Add test scenarios to plans/test-plan-<milestone>.md
   1. /simplify         — Review and clean up the code (optional)
   2. /review-pr        — Run the multi-agent local review (RECOMMENDED before
                          push; uses your Max/Pro subscription so CI can stay
                          cheap)
   3. /commit           — Commit your changes
   4. /commit-push-pr   — Push and create a PR targeting `MILESTONE_BRANCH`
                          Include "Closes #ISSUE_NUMBER" in the PR body.
   5. /address-pr-comments — After CI runs, review and fix any PR comments
   6. /finish-issue     — Monitor CI, squash-merge, and close the issue
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
- **Tier agent usage** - assess complexity first; Small issues skip PM, pre-implementation architect, and multi-agent Explore
- **Triage pre-existing issues immediately** (Step 5.5) — never silently note something as "pre-existing"; apply the
  containment + severity matrix and either fold it in, create an issue in the current milestone, or defer with `triage` label.
  Use `/found` for standalone capture.
- **Investigate testing gaps for bugs** - for `bug` labeled issues, include escape analysis in the plan
- **Verify UserSpice integration** (Step 7.1) - do not duplicate framework functionality
- **Assess database and security impacts** (Step 7.2) - identify schema changes and security requirements upfront
- **No pre-implementation architect call** - architect reviews code after implementation, not plans
- **Only invoke agents that are needed** - match agents to the issue type; skip docs agent for internal refactoring, skip test agent for docs-only changes
- **Scale agents up** - separate test agents for PHPUnit vs Playwright when both are needed
- **Run independent agents in parallel** - when agents don't depend on each
  other's output, launch them simultaneously for efficiency
- **Never close issues manually** - use `Closes #NNN` in the PR body so
  issues close automatically on merge

## Examples

See `.claude/commands/start-issue-examples.md` for worked example flows (reference only — not loaded at runtime).
