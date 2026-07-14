---
description: Full-branch PR review that matches CI scope — diff + complete file content, with user confirmation on recommendations
argument-hint: "[aspects: code|errors|comments|tests|simplify|all]"
---

# PR Review (Full Branch)

Run a comprehensive review against the **full accumulated branch diff** — the
same view the CI `pr-to-milestone-review` check uses. This catches cross-commit
issues (dead code, broken call interactions, unreachable paths) that per-file or
working-tree-only reviews miss.

Use this instead of `/pr-review-toolkit:review-pr` before pushing or creating a PR.

**Review aspects (optional):** `$ARGUMENTS`  
Available: `code` | `errors` | `comments` | `tests` | `simplify` | `all` (default)

---

## Step 1: Build the full branch diff

Find the milestone base branch (or fall back to `main`):

```bash
# If a PR exists, use its base branch
BASE=$(gh pr list --head "$(git branch --show-current)" --state open \
  --json baseRefName --jq '.[0].baseRefName // empty' \
  --repo unibrain1/elanregistry 2>/dev/null)

# Fall back to the single milestone/* branch if no PR yet
if [ -z "$BASE" ]; then
  BASE=$(git branch --list 'milestone/*' | head -1 | tr -d ' *')
fi

# Last resort
BASE=${BASE:-main}

MERGE_BASE=$(git merge-base HEAD origin/$BASE 2>/dev/null || git merge-base HEAD $BASE)
git diff $MERGE_BASE..HEAD
```

Also get the list of changed files:

```bash
git diff --name-only $MERGE_BASE..HEAD
```

Read the **full content** of every changed file (not just the diff lines). Both
inputs together give the same view as the CI reviewer: what changed and what the
file looks like now in its entirety.

---

## Step 2: Determine applicable review agents

Based on `$ARGUMENTS` (default: all applicable):

| Aspect    | Agent                                      | When to run                                          |
|-----------|--------------------------------------------|------------------------------------------------------|
| `code`    | `pr-review-toolkit:code-reviewer`          | Always                                               |
| `errors`  | `pr-review-toolkit:silent-failure-hunter`  | If catch blocks, fallbacks, or error paths changed   |
| `comments`| `pr-review-toolkit:comment-analyzer`       | If PHPDoc, inline comments, or docstrings changed    |
| `tests`   | `pr-review-toolkit:pr-test-analyzer`       | If test files changed or new features added          |
| `simplify`| `pr-review-toolkit:code-simplifier`        | After all other agents pass; final polish only       |

If `$ARGUMENTS` is empty or `all`, run all applicable agents based on the changed
file types (skip test analyzer if no test files changed; skip comment analyzer if
no comments/docs added).

---

## Step 3: Launch review agents

Provide **each agent** with:

1. **The full branch diff** (from Step 1)
2. **The full content of every changed file** (read each file in full)
3. **This instruction appended to the agent prompt**:

> "Review this as the complete accumulated set of changes on this branch — not
> just the latest commit. Look specifically for cross-commit issues: functions
> that are defined but no longer called, fallback values that can never be
> reached, CSRF or token interactions that break when multiple edits are combined,
> and anything that looks correct in a per-file diff but is broken when viewed as
> a whole. The project is Elan Registry (PHP 8.2 / UserSpice 6). Apply
> CLAUDE.md standards and docs/development/CODING_STANDARDS.md."

Run all applicable agents **in parallel** for speed. `simplify` always runs last,
after other agents complete.

---

## Step 4: Aggregate and triage findings

Collect all agent findings and categorize them:

| Tier               | Label                | Definition                                                        |
|--------------------|----------------------|-------------------------------------------------------------------|
| **Blocking**       | Must fix before push | Security issue, definite bug, broken logic, standards violation   |
| **Recommendation** | Decide before push   | Style suggestion, dead code, minor improvement, optional refactor |
| **Informational**  | No action needed     | Confirmed-good patterns, context notes                            |

Output a triage table:

```text
## Local Review — Branch: <branch-name>
## Diff scope: <merge-base>..<HEAD> (<N> commits, <M> files)

### Blocking (must fix)
| Agent | File:Line | Issue |
|-------|-----------|-------|

### Recommendations (your call)
| Agent | File:Line | Suggestion |
|-------|-----------|------------|

### Informational
- ...
```

---

## Step 5: Handle findings

**If there are Blocking items:**

- Fix each one (launch `software-developer` agent per file for non-trivial fixes,
  or edit directly for simple ones)
- After fixing, re-run the `pr-review-toolkit:code-reviewer` agent on the full
  branch diff + changed files to confirm clean
- Do NOT proceed until blocking items are resolved

**If there are Recommendation items:**

- Present them to the user with a one-line summary each
- Ask: "Here are recommendations from the review. Which (if any) would you like
  to address before pushing?"
- Wait for the user's response before continuing
- For each item the user wants to address: fix it, then re-run the code-reviewer
  to confirm

**If the review is clean:**

- Report: "Local review clean — no blocking issues, no open recommendations."
- Proceed to `/commit-push-pr` or `/commit`

---

## Notes

- This command reviews **committed local changes** vs the milestone branch.
  Run it after committing your work but before pushing (`/commit` then `/review-pr`).
- The `simplify` aspect runs only after all other aspects pass — don't use it
  to mask unfixed issues.
- To review only specific aspects: `/review-pr code errors`
