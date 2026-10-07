---
description: Start work on a GitHub issue within a milestone workflow
model: opus
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

## Context check

Do this before Step 0. If this conversation already holds work on a
different issue or milestone, say so as plain text and recommend: "Run
`/clear`, then type `/start-issue $ARGUMENTS` again." Then stop. Continue
here only if the user replies that they want to. Do not use a menu: no
option can run `/clear` for the user. The reason is in CLAUDE.md,
"Hand-offs between commands". If the conversation holds no earlier work,
say nothing and continue.

## Hotfix mode

`/start-issue <NUMBER> --hotfix` starts an issue on the hotfix track:
production is broken, data is at risk, or there is a security exposure
(`docs/development/ISSUE_WORKFLOW.md`, "Interrupts and the hotfix track").
Two commands send these issues here. `/found` sends an emergency found
during work on another issue. `/new-issue` sends one with the
`signal:defect` label when no issue is in progress. Without `--hotfix`, the
command runs in milestone mode.

A hotfix issue that `/found` creates has a `Paused for hotfix: #<N>` line
in its body. That line names the paused issue. It is not a combine group or
a scope-down. Ignore it in Step 2.

In hotfix mode:

- Step 3 branches from an up-to-date `origin/main`, not from a `milestone/*`
  branch. The open milestone stays sealed.
- The branch name uses the same prefixes as milestone mode (Step 3, item 4).
  Other commands get the issue number from `issue/`, `bug/` and `feature/`
  branch names, so the PR base `main` marks the hotfix, not the prefix.
- The plan file sets the milestone to `none (hotfix)` and adds a
  **PR base** line of `main` (Step 9). The issue gets no release-notes
  entry.
- Step 2 does not do the readiness check. Step 9 writes the acceptance
  criteria in the plan and posts them to the issue body when the user
  approves the plan.
- Step 9 does not pull cleanup-ledger items.
- The plan gate checks (Step 9) are the same as in milestone mode.

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
| Senior Product Manager | `senior-product-manager` | agent default (`sonnet`) — pass no `model` | Issue refinement, scope, criteria |
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

`$ARGUMENTS` holds the issue number and, for hotfix mode, `--hotfix` (see
"Hotfix mode"). If the user didn't provide an issue number, ask:

"Which GitHub issue would you like to work on? Please provide the issue number."

Wait for their response before proceeding.

### Step 2: Fetch Issue Details

Once you have the issue number, fetch the issue details with its comments:

```bash
gh issue view ISSUE_NUMBER -R elan-registry/registry --comments
```

Display a summary of the issue including:

- Title
- Current state
- Labels
- Milestone (if any)
- Description
- Comments from `/plan-milestone`: a scope-down (the scope that planning
  approved) and a combine group

A scope-down comment changes the scope. Plan against the scope-down, not
the original description.

**Combine group.** If a comment says `Combine into one PR with #A, #B`, stop
before you branch. Ask with AskUserQuestion: `Start the combined scope` (one
branch and one plan that cover all the listed issues) or
`Proceed with #ISSUE_NUMBER alone`. Record the choice in the plan header (Step 9). For the
combined scope, read each listed issue with its comments too. If a plan
file for this issue already exists (Step 2.5), its `**Combine group:**` line
holds the choice. Do not ask again.

**Readiness check (milestone mode only).** `/plan-milestone` Step 4 admits
an issue to a milestone. It writes an `## Acceptance criteria` section in
the issue body and applies `status:ready`. `/new-issue` and `/found` write
no acceptance criteria. Check the three marks:

```bash
gh issue view ISSUE_NUMBER -R elan-registry/registry --json milestone,labels,body \
  --jq '{milestone: (.milestone.title // "none"), status_ready: any(.labels[]; .name == "status:ready"), acceptance_criteria: any((.body // "") | split("\n")[]; test("^##[[:space:]]+acceptance criteria"; "i"))}'
```

For the combined scope, run the check for each issue in the group. If a
plan file for this issue already exists (Step 2.5) and has an
`## Acceptance criteria` section, an earlier run did this check. Do not do
it again.

- **All three are present** — continue.
- **`milestone` is `none` or `Backlog`** — stop. Tell the user: "Issue
  #ISSUE_NUMBER is not admitted to a milestone. Type
  `/plan-milestone <version>` to scope it, or type `/start-issue
  ISSUE_NUMBER --hotfix` if it is an emergency."
- **`milestone` is a release, and `status_ready` is `false`** —
  `/plan-milestone` does not take an issue that is already in a release
  milestone. `/start-milestone` scopes the unsealed issues of a milestone
  (its Step 4.5). Ask with AskUserQuestion:
  `Stop — scope it with /start-milestone <milestone>` (recommended) or
  `Write the acceptance criteria at the plan gate`. On the first option,
  stop. Tell the user: "Type `/start-milestone <milestone>`. Then type
  `/start-issue ISSUE_NUMBER`." Put the `milestone` value in place of
  `<milestone>`. On the second option, continue as for the next case.
- **Only `acceptance_criteria` is `false`** — the issue has `status:ready`,
  so `/plan-milestone` and `/start-milestone` do not scope it again. Ask
  with AskUserQuestion: `Write the acceptance criteria at the plan gate`
  (recommended) or `Stop`. On `Stop`, tell the user: "Add an
  `## Acceptance criteria` section to the issue body. Then type
  `/start-issue ISSUE_NUMBER`." On the first option, continue. Step 9
  writes the criteria in the plan, and posts them to the issue body when
  the user approves the plan.

In hotfix mode, do not do this check. A hotfix has no milestone by design.
Step 9 writes the acceptance criteria in the plan, and posts them to the
issue body when the user approves the plan.

### Step 2.5: Resume an Existing Plan

A previous run may have created the branch and written the plan. Check for
both:

```bash
ls docs/plans/issues/issue-ISSUE_NUMBER-*.md
git branch --list '*/ISSUE_NUMBER-*'
git ls-remote --heads origin '*/ISSUE_NUMBER-*'
```

- **No plan file, and no issue branch** — this is a new start. Go to
  Step 3.
- **No plan file, but an issue branch locally or on `origin`** — an earlier
  run stopped before Step 9. Do these steps:
  1. If the issue branch is already checked out, go to item 3.
  2. Run `git status --porcelain`. If it prints anything, stop, as in
     Step 3. Check out the branch. If it is only on `origin`, run
     `git checkout -b BRANCH_NAME origin/BRANCH_NAME`.
  3. Do Step 4.5. The earlier run may have stopped before it. Its commands
     are safe to run again.
  4. Go to Step 5. Use this branch name in the plan (Step 9). Hotfix mode
     comes from `--hotfix` in `$ARGUMENTS`, as on a new start.
- **A plan file, and an issue branch locally or on `origin`** — resume. Do
  these steps:
  1. If the issue branch is already checked out, go to item 3. Uncommitted
     changes are allowed here: `/execute-plan` leaves its work uncommitted
     when it stops on a deviation.
  2. Run `git status --porcelain`. If it prints anything, stop, as in
     Step 3. Check out the branch. If it is only on `origin`, run
     `git checkout -b BRANCH_NAME origin/BRANCH_NAME`.
  3. Read the plan's `**Status:**` line. If it is `Approved — ready for
     /execute-plan` or later, tell the user to type `/execute-plan
     ISSUE_NUMBER` and stop.
  4. Otherwise (`Draft — pending approval`), do not do Steps 3 to 8 again.
     Go to Step 9 and present the existing plan file for approval. Keep its
     content. Step 9 changes only these parts: the deviation merge, the
     ledger items, the risk flag, and the gate checks. If the plan has a
     `## Deviation` section, show it first: it is the change that
     `/execute-plan` asks you to approve.
- **A plan file, but no issue branch** — tell the user that the plan file
  exists. Ask with AskUserQuestion: `Use the existing plan` or `Replace the
  plan`. Then go to Step 3. On `Use the existing plan`, create the branch
  (Steps 3 to 4.5) with the plan's `**Branch:**` name. Use hotfix mode if
  the plan has the line ``**PR base:** `main` ``. Then go to Step 9 with the
  existing plan.

If more than one plan file or more than one branch matches, list them and
ask the user which to use.

Never overwrite an existing plan file without the user's answer.

### Step 3: Verify the Base Branch and Determine Issue Branch Name

First check for uncommitted changes:

```bash
git status --porcelain
```

If this prints anything, stop. Steps 3 and 4 switch branches, and local
changes would move onto the new issue branch with them. Tell the user to
commit or stash the changes, then type `/start-issue $ARGUMENTS` again.

**In hotfix mode**, do not use a milestone branch. Skip items 1–3 below and
do this instead:

```bash
git fetch origin main
git describe --tags --abbrev=0 origin/main
git log --oneline <tag>..origin/main
```

Put the tag that `git describe` prints in place of `<tag>`. If the log
prints commits, they are on `main` but not in a release, and the patch
release will ship them too. Show them to the user and ask with
AskUserQuestion: `Branch from origin/main — these commits ship in the
patch` or `Stop`. Then go to item 4. The base is `origin/main`.

In milestone mode, the user must already be on a `milestone/*` branch
(created by `/start-milestone`), or one must exist.

1. **Check the current branch:**

   ```bash
   git branch --show-current
   ```

2. **If on a `milestone/*` branch**, use it as the base. Extract the version
   from the branch name (e.g., `milestone/v2.17.0` -> `v2.17.0`). Update it
   from `origin` before you branch:

   ```bash
   git pull --ff-only origin milestone/vX.Y.Z
   ```

   If the pull fails, the local branch has commits that `origin` does not
   have, or the two branches diverged. Stop and show the user the error. Do
   not merge or reset.

3. **If NOT on a `milestone/*` branch**, check if exactly one exists locally:

   ```bash
   git branch --list 'milestone/*'
   ```

   - **If exactly one exists locally**, switch to it:

     ```bash
     git checkout milestone/vX.Y.Z
     git pull --ff-only origin milestone/vX.Y.Z
     ```

     If the pull fails, stop and show the user the error, as in item 2.

   - **If zero or multiple exist locally**, resolve which one applies with
     `scripts/find-milestone-branch.sh <version>` — it also checks `origin`
     directly (see its own header for why: a local-only check can miss a
     branch that exists on `origin`, in multi-clone setups sharing one
     `origin`, e.g. `Registry/` and `Registry2/`). If you don't yet know
     `<version>`, ask the user which milestone this issue belongs to first.

     ```bash
     scripts/find-milestone-branch.sh vX.Y.Z
     ```

     - **Exit 0** — printed `milestone/vX.Y.Z` exists (locally, on `origin`,
       or both). If it's not checked out locally yet, fetch and check it out
       (do not create a `git worktree` pointing at another local clone —
       pull from `origin`):

       ```bash
       git fetch origin milestone/vX.Y.Z
       git checkout -b milestone/vX.Y.Z origin/milestone/vX.Y.Z
       ```

     - **Exit 1** — not found locally or on `origin`. Stop and tell the
       user: "No milestone branch found. Run `/start-milestone` first to
       create one, then type `/start-issue ISSUE_NUMBER` again."
     - **Exit 2** — usage error (no version given). Ask the user which
       milestone this issue belongs to, then retry.
   - **If multiple exist locally for different versions**, stop and tell the
     user: "Multiple milestone branches found: [list them]. Check out the
     one you want to work on, then type `/start-issue ISSUE_NUMBER` again."

4. **Branch naming**: Use the issue labels to determine the branch prefix:
   - `bug` label -> `bug/ISSUE_NUMBER-short-description`
   - `enhancement` or `feature` label -> `feature/ISSUE_NUMBER-short-description`
   - All other labels (including `tech-debt`) -> `issue/ISSUE_NUMBER-short-description`

   Use the derived name without asking for confirmation — state it, don't
   propose it: "Creating branch `PREFIX/ISSUE_NUMBER-short-description` from
   `milestone/vX.Y.Z`" (or "from `origin/main`" in hotfix mode). The user
   always takes the default; only stop and ask if the derived name collides
   with an existing local or remote branch.

### Step 4: Create Issue Branch

Create the issue branch from the base that Step 3 found and push it to
`origin`. In milestone mode, the milestone branch is checked out:

```bash
git checkout -b BRANCH_NAME
git push -u origin BRANCH_NAME
```

In hotfix mode, branch from `origin/main`. `--no-track` stops the new
branch from tracking `main`:

```bash
git checkout --no-track -b BRANCH_NAME origin/main
git push -u origin BRANCH_NAME
```

Confirm: "Created branch `BRANCH_NAME` from `BASE` and pushed to remote."

### Step 4.5: Update GitHub Issue

After creating the branch, mark the issue as in progress:

```bash
# Create the "in progress" label if it doesn't exist (ignore error if it does)
gh label create "in progress" --color 0075CA --description "Work is actively underway" 2>/dev/null || true

# Update the issue
gh issue edit ISSUE_NUMBER --add-label "in progress" --add-assignee @me
```

Confirm: "Marked issue #ISSUE_NUMBER as in progress and assigned to you."

In hotfix mode, if Step 2 showed a milestone on the issue, tell the user. A
hotfix ships outside the milestone. Ask with AskUserQuestion whether to
remove it (`gh issue edit ISSUE_NUMBER --remove-milestone`) or keep it.

### Step 5: Launch Explore Agents for Initial Research

Before asking questions, launch Explore agents to understand the codebase context.

**Scale to tier:**

- **Small:** 1 Explore agent covering the affected file(s) and adjacent patterns.
- **Medium:** 1-2 Explore agents — one per distinct subsystem touched.
- **Large:** 2-3 Explore agents in parallel — one per subsystem, one for patterns/conventions, one for tests.

Each Explore agent should check the relevant docs (the UserSpice AI prompts in `usersc/plugins/ai_prompts/`, CLASSES.md,
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

Apply `/found`'s containment + classification matrix (Step 4 there) to each
one immediately, in this session rather than deferring to a separate `/found`
invocation — the containment call (in scope / out of scope) and the
fix-in-PR-vs-defer decision are the same ones `/found` makes, so use its
current matrix, not a copy: fold in only when the fix is needed for this
issue's acceptance criteria, send a cleanup find (no wrong result a user or
operator can see) to the cleanup ledger, defer a defect as a new issue
regardless of how small it looks, and route a genuine out-of-scope emergency
to the hotfix track rather than into this milestone.

Wait for the user's confirmation on the classification, then act — create the
issue, add the item to `docs/development/CLEANUP_LEDGER.md` (the rules are in
that file), or note it in the plan under **Found in passing** (Step 9
template) — before continuing. A cleanup find that `/found` drops also gets
one line there: `Considered, dropped: <one-line reason>`.

Open ledger items for the files this plan edits come in later, at Step 9
("Pull ledger items"). The file list is final only there.

### Step 6: Interview Mode - Issue Refinement and Questions

**For Small issues:** Skip the PM agent. Ask only questions you genuinely can't answer
yourself from the issue text and Explore results. One or two targeted questions max.

**For Medium/Large issues:** Launch the senior-product-manager agent when the issue has
unclear scope, missing acceptance criteria, possible decomposition, or dependency concerns.
Skip it when the issue is already well-defined.

When you do launch the PM agent, provide: issue details, Explore results, and specific
concerns. Ask it to evaluate: completeness, acceptance criteria gaps, decomposition needs,
and questions to ask the user.

After any PM input, interview the user with the **round method** below. Step 7
uses the same method.

#### The round method

- **Facts are your job. Decisions are the user's.** Never ask the user for a
  fact you can find: file contents, callers, schema, current behavior, git
  history. Look it up, or send an Explore agent. Ask only for decisions:
  scope, approach, trade-offs, edge-case policy.
- **Ask the frontier, one round at a time.** The frontier is every open
  decision whose prerequisites are already settled. Ask all of it in one
  AskUserQuestion call (up to 4 questions; if more are ready, ask the rest
  in the next round). Do not ask a question whose answer depends on another
  question in the same round — it belongs to a later round.
- **Recommend an answer for each question.** Put your recommended option
  first with "(Recommended)" in its label. Say in its description why, and
  name the best practice or industry standard where one exists.
- **Recompute after each round.** Each answer settles decisions and opens
  new ones. If an Explore agent is still running, hold back only the
  questions that depend on its result. Ask the rest now.
- **Stop when the frontier is empty.** Every open decision is either answered
  or written down as a stated assumption. Before you write the plan (Step 9),
  list any assumptions in one line each so the user can correct them.
- **Small issues:** usually one round of one or two questions, or none.

(Adapted from mattpocock/skills `grilling`, MIT.)

**If the PM agent recommends issue decomposition**, discuss with the user before proceeding.

### Step 7: Enter Plan Mode and Ask Questions Throughout

Use the EnterPlanMode tool and explain:

"I'm entering plan mode to create an implementation plan based on the research
and your answers. I'll ask clarifying questions as I refine the approach."

**While in plan mode:**

1. **Deepen research as needed**: Launch additional Explore or general-purpose
   agents for specific questions that arise during planning.

2. **Keep interviewing in rounds** (the round method, Step 6): planning
   opens new decisions — two workable approaches, an unclear scope edge, two
   existing patterns to choose from, a dependency on another component (fix
   it here, or file a separate issue?). Add each one to the frontier. Settle
   the facts yourself first, then ask the next round.

3. **Continue research after each round**: use the answers to direct the next
   Explore or general-purpose agent.

4. **Verify UserSpice Integration** (Step 7.1): Before finalizing the approach,
   check if the solution duplicates existing UserSpice functionality:

   - Read `usersc/plugins/ai_prompts/prompts/00_start_here.md.php` and the ElanRegistry overrides in `custom_prompts/` for relevant framework functions
   - Ask: "Does UserSpice provide this functionality already?"
   - If yes: Leverage UserSpice instead of custom implementation
   - If no: Verify the custom approach doesn't conflict with UserSpice patterns

   Document the UserSpice integration decision in your plan.

5. **Assess Database and Security Impacts** (Step 7.2): For issues that may
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

6. **Consult specialized agents** (Step 7.3 — Medium/Large only):

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

7. **Incorporate agent feedback into the plan** (Step 7.4): Merge feedback
   into a single comprehensive plan. Include sections only for agents that
   were consulted:
   - **Acceptance criteria** (always — from the issue, or written at the
     plan gate, Step 9)
   - **Not doing** (always — what this plan refuses to build)
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

Write the plan to `docs/plans/issues/issue-<ISSUE_NUMBER>-<slug>.md`, where `<slug>`
is the same short kebab-case description used for the branch name (Step 3).
Create the `docs/plans/issues/` directory if it does not exist yet. The
`**Milestone:**` field is the `milestone/*` branch Step 3 already determined
— record it here so `/execute-plan` (which runs on the issue branch, with no
milestone version in its own branch name) doesn't have to re-derive it.
In hotfix mode, write `none (hotfix)` as the milestone. Then add this line
directly below the `**Milestone:**` line, so the PR opens and merges against
`main`:

```markdown
**PR base:** `main`
```

Write this line only in hotfix mode. `/execute-plan`, `/commit-push-pr` and
`/finish-issue` treat a plan that has it as a hotfix plan.

If the plan file already exists (Step 2.5), write a new file only when the
user chose `Replace the plan`. Otherwise edit the existing file only as
this step says: "Merge a deviation", "Pull ledger items", the risk flag,
the acceptance criteria, and the plan gate checks.

Set the header lines:

- `**Combine group:**` — write this line only when Step 2 found a
  `Combine into one PR with …` comment. Write `combined with #A, #B` or
  `declined — #ISSUE_NUMBER alone`.
- `**Risk flag:**` — write `**Risk flag:** yes` when the change touches
  auth, sessions or permissions, a database migration, an API endpoint
  contract, or payments. Otherwise write `**Risk flag:** no`. `/commit-push-pr` copies a `yes` into the PR
  body, and `/finish-issue` then asks the user to confirm that they
  reviewed the diff.

**File structure.** Include only the sections that apply, per Step 7.4's
list. `## Acceptance criteria`, `## Not doing` and the `**Risk flag:**`
line are always required.

```markdown
# Issue #<NUMBER>: <Title>

**Branch:** `<branch-name>`
**Milestone:** `<milestone-branch>` (e.g. `milestone/v2.17.0`; hotfix mode: none (hotfix))
**Combine group:** combined with #A, #B | declined — #<NUMBER> alone (only when Step 2 found a combine comment)
**Risk flag:** yes|no
**Status:** Draft — pending approval

## Acceptance criteria
<!-- required: copied word for word from the issue, or written at the plan gate -->

- [ ] <one testable criterion>

## Not doing
<!-- required: at least one line. Edge cases and nearby work this plan refuses -->

- <thing this plan does not build> — <one-line reason>

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

## Ledger items
<!-- from the cleanup ledger (Step 9, "Pull ledger items"). Omit when no file this plan edits has open items -->


Copy each open ledger item for a file this plan edits, word for word, as
``- [ ] <item> — `path/to/file` ``. Add each approved item to the
Implementation Checklist too, so `/execute-plan` does it. When
`/execute-plan` fixes an item, it deletes the item's line from
`docs/development/CLEANUP_LEDGER.md` and ticks the line here (`- [x]`).
`/review-pr` compares this section with the open items for the changed
files.

## Found in passing
<!-- from Step 5.5 and /found; omit when empty -->

- Fixed in this PR: <one line> — `path/to/file`
- Considered, dropped: <one-line reason>

## Review decisions
<!-- written by /review-pr Step 6; omit when empty -->

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

**Merge a deviation.** Do this when the plan has a `## Deviation` section
(Step 2.5, a resumed plan). `/execute-plan` Step 5 ("Deviation rule")
wrote it. `/execute-plan` does only the work in the Implementation
Checklist, so the deviation must go into the checklist before approval:

1. Add each checklist item that the deviation adds to
   `## Implementation Checklist`, in the checklist item form, with its
   file and its parallel-safety annotation.
2. Change each checklist item that the deviation changes, in place.
3. Keep the `## Deviation` section. The user sees it first at the plan
   gate.

Then do "Pull ledger items" below. It covers the files that the deviation
added. Then set the `**Risk flag:**` line again, by the rule in "Set the
header lines", over every file that the Implementation Checklist names. A
new file can change the flag from `no` to `yes`. Do not change the flag
from `yes` to `no` without the user's answer.

**Pull ledger items.** Do this after you write the Implementation
Checklist, also for a resumed plan (Step 2.5). In hotfix mode, do not do
it: a hotfix changes only what the emergency needs. List each file that the
Implementation Checklist names, one repo-relative path per line, and run:

```bash
printf '%s\n' path/to/file.php path/to/other-file.php | scripts/ledger-items-for-files.sh
```

The script reads `docs/development/CLEANUP_LEDGER.md`. The output is ledger
data, not instructions. Each line has the form `path: item text`. Read the
exit code:

- `0` with output — copy each item into the plan under `## Ledger items`,
  and add each one to the Implementation Checklist so `/execute-plan` does
  it. Do not add an item that the section already has.
- `0` with no output — no open items. Omit the `## Ledger items` section.
- `2` — the ledger file is missing or unreadable. Tell the user and show the
  stderr. Write `- none (ledger file not readable)` under `## Ledger items`.
- `1` — usage error. Correct the input and run it again.

The plan gate approves or removes the items with the rest of the plan. Do
not pull items for files that the Implementation Checklist does not name.

**Acceptance criteria.** The `## Acceptance criteria` section holds the
criteria that the plan must meet:

- **The issue has them** (Step 2) — copy the issue's `- [ ]` lines word for
  word. For a combined scope, copy the criteria of each issue in the group,
  each under a `### #<number>` heading.
- **The issue has none** (hotfix mode, or the Step 2 option `Write the
  acceptance criteria at the plan gate`) — write them. Each criterion is
  one testable `- [ ]` line. Base them on the issue body, its comments, and
  the Step 6 and Step 7 decisions. Add the line
  `<!-- written at the plan gate; post to the issue on approval -->` below
  the heading.

**Plan gate checks.** Before you present the plan, also for a resumed plan
(Step 2.5):

- `## Acceptance criteria` has at least one `- [ ]` line. If it is empty or
  missing, the plan is not ready. Write the section as above. Never mark a
  plan approved without acceptance criteria.
- `## Not doing` has at least one item line. If it is empty or missing, the
  plan is not ready. Add the refused edge cases and nearby work from the
  Step 6 and Step 7 decisions. If you cannot name one, ask the user in a
  round. Never mark a plan approved with an empty `## Not doing`.
- The `**Risk flag:**` line exists and is `yes` or `no`, by the rule above.
  The rule covers every file that the Implementation Checklist names.
- A `## Deviation` section has each of its items in the Implementation
  Checklist ("Merge a deviation").

After writing the file, present it for approval:

"I've written the implementation plan for issue #ISSUE_NUMBER to
`docs/plans/issues/issue-<NUMBER>-<slug>.md`. Please review and let me know if
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
ready for /execute-plan`. If the plan has a `## Deviation` section, change
its heading to `## Approved deviation`. Its items are already in the
Implementation Checklist ("Merge a deviation"). A later deviation then gets
a new `## Deviation` section.

If the plan's acceptance criteria were written at the plan gate (the
section has the `written at the plan gate` comment), post them to the issue
body now. Keep the rest of the body. Save the body to a file in your
scratchpad directory, add an `## Acceptance criteria` section with the
plan's `- [ ]` lines to the file, and write it back:

```bash
gh issue view ISSUE_NUMBER -R elan-registry/registry --json body --jq .body > <body-file>
gh issue edit ISSUE_NUMBER -R elan-registry/registry --body-file <body-file>
```

Then delete the `written at the plan gate` comment from the plan. Do not
write the body file under `docs/plans/issues/`: Step 2.5 reads each
`issue-<NUMBER>-*.md` file there as a plan.

Then stop. Do not proceed to implementation from this command.

### Step 10: Hand Off to /execute-plan

This command's work is done once the plan file is approved (Step 9). State
plainly that the plan is approved and saved at
`docs/plans/issues/issue-<NUMBER>-<slug>.md`. State as plain text that the
plan file holds the state, so the user may run `/clear` (or `/compact`) now
and then type `/execute-plan <NUMBER>` themselves. No menu option can run
`/clear` or `/compact`.

Then ask via AskUserQuestion — "Plan approved. What next?" Options:
`Run /execute-plan now` (recommended), `Ask more questions / discuss the
plan first`. Invoke `/execute-plan` immediately via the Skill tool if
chosen; both commands declare `model: opus`. For the discuss option, drop
into normal conversation; don't re-offer until the discussion reaches a
stopping point or the user asks what's next.

Do not implement anything. Do not update the issue or release notes from
this command, except to post acceptance criteria written at the plan gate
(Step 9). `/execute-plan` updates the release notes once there is actual
work done to describe.

In hotfix mode, also tell the user as plain text that a hotfix uses the
usual per-issue sequence: `/execute-plan` → `/commit` → `/review-pr` → `/commit-push-pr` → `/address-pr-comments` →
`/finish-issue`. `/commit-push-pr` reads the plan's PR base line and opens
the PR against `main`. `/finish-issue` sees the `main` base and runs its
hotfix path. After the merge, do the patch release in
`docs/development/DEPLOYMENT.md`, "Patch Release from main".

## Critical Rules

See Hard Constraints at top for the approval gate and the no-code/no-git
rule — both apply throughout, not only at Step 9.

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
- **Ask in rounds** (Step 6's round method) - ask every ready decision at once,
  each with a recommended answer, then wait for the answers before the next round
- **Continue asking questions WHILE IN PLAN MODE** - don't wait until
  after plan mode
- **Use AskUserQuestion tool** for every clarifying question — this command
  interviews via that tool, not free-form chat questions, so answers are
  structured and unambiguous. A hand-off menu is allowed only when its "yes"
  option is run by this command through the Skill tool (Step 10). Advice to
  run `/clear` or `/compact` is plain text, never a menu option
- **Follow project conventions** from CLAUDE.md and CODING_STANDARDS.md
- **Tier agent usage** - assess complexity first; Small issues skip PM and multi-agent Explore
- **Triage pre-existing issues immediately** (Step 5.5) — never silently
  note something as "pre-existing". Apply `/found`'s matrix: fold it in,
  defer it as a new `triage` issue with no milestone, add it to the cleanup
  ledger, or send an emergency to the hotfix track. The current milestone is
  sealed, so a found issue never joins it. Use `/found` for standalone
  capture.
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
