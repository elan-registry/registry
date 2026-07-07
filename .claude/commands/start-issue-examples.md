# Start Issue — Example Flows (Reference Only)

This file is NOT loaded at runtime. It exists for reference when editing
`start-issue.md`. See the operative command at `.claude/commands/start-issue.md`.

---

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

0. Update test plan  — Add test scenarios to plans/test-plan-<milestone>.md
1. /simplify         — Review and clean up the code (optional)
2. /review-pr        — Multi-agent local review (recommended before push)
3. /commit           — Commit your changes
4. /commit-push-pr   — Push and create a PR targeting `milestone/v3.2.0`
                       Include "Closes #423" in the PR body.
5. /address-pr-comments — After CI runs, review and fix any PR comments
6. /finish-issue     — Monitor CI, squash-merge, and close the issue
```

---

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

0. Update test plan  — Add test scenarios to plans/test-plan-<milestone>.md
1. /simplify         — Review and clean up the code (optional)
2. /review-pr        — Multi-agent local review (recommended before push)
3. /commit           — Commit your changes
4. /commit-push-pr   — Push and create a PR targeting `milestone/v2.16.0`
                       Include "Closes #512" in the PR body.
5. /address-pr-comments — After CI runs, review and fix any PR comments
6. /finish-issue     — Monitor CI, squash-merge, and close the issue

Remember: Include the bug escape analysis in the PR description so
reviewers can verify preventive test coverage.
```
