---
description: Run a security review of recent code changes (OWASP, CSRF, SQL injection, XSS)
model: haiku
---

# Security Review

Keep output brief — terse status lines, no preamble, no restating of steps.

Perform a comprehensive security audit of recent code changes in this project.

For plan-driven work, `/execute-plan` Step 7 already launches `security-reviewer`
against the full diff whenever the plan's Database & Security Considerations
section is non-empty or a changed file touches forms/SQL/auth — this command
is largely superseded for that path. Use it directly for ad-hoc work with no
plan file, or to re-run a security pass in isolation without repeating the
rest of `/execute-plan`'s review round.

## Steps

1. **Identify changed files**: Review the whole branch, not only the
   uncommitted part. The scope is every commit since the branch left its
   base, plus staged, unstaged and untracked changes. Use the PR's base
   branch when a PR exists. Otherwise resolve the base with
   `scripts/resolve-base-branch.sh`. Issue branches start from
   `milestone/vX.Y.Z`, so a diff against `main` adds the whole milestone.

   ```bash
   # An empty --head lists every open PR, so skip the lookup on a detached HEAD.
   BRANCH=$(git branch --show-current)
   BASE=
   [ -n "$BRANCH" ] && BASE=$(gh pr list --head "$BRANCH" --state open \
     --json baseRefName --jq '.[0].baseRefName // empty' \
     --repo elan-registry/registry 2>/dev/null)
   if [ -z "$BASE" ]; then
     BASE=$(scripts/resolve-base-branch.sh) || { echo "could not resolve a base branch (detached HEAD?)" >&2; exit 1; }
     BASE=${BASE#origin/}
   fi
   MERGE_BASE=$(git merge-base HEAD "origin/$BASE" 2>/dev/null || git merge-base HEAD "$BASE") \
     || { echo "no merge base with $BASE" >&2; exit 1; }
   echo "BASE=$BASE MERGE_BASE=$MERGE_BASE"
   # Committed, staged and unstaged changes since the merge base
   git diff --name-only "$MERGE_BASE"
   # Untracked files
   git ls-files --others --exclude-standard
   ```

   Exit 1 means that no base or merge base was found, for example on a
   detached HEAD. Stop and report the message. Do not fall back to
   `origin/main`. Check out the branch, then run `/security-review` again.

2. **Filter to relevant files**: Focus on `.php` and `.js` files. Skip
   documentation, tests, and static assets unless they contain security-relevant
   code.

3. **Launch the security-reviewer agent** via the Agent tool with
   `subagent_type: "security-reviewer"`. Provide it with:
   - The list of changed files and untracked files from step 1
   - The full diff: `git diff <MERGE_BASE>`, with the SHA that step 1
     printed
   - Instructions to read and review each changed file completely, and each
     untracked file, which the diff does not show

4. **Report results**: Present the security-reviewer agent's findings to the
   user. If critical or high severity issues are found, recommend fixing them
   before proceeding.

5. **Next step**: Tell the user in plain text. Fix Critical and High
   findings first. Then recommend `/clear` and the next command of the
   workflow, usually `/commit` or `/commit-push-pr`. The next command does not
   need this review's context. This command does not start it.

## When to Use

- Before creating a commit or pull request
- After implementing features that handle user input, authentication,
  database queries, or file operations
- As a mandatory check per CLAUDE.md guidelines

The security-reviewer agent defines its own comprehensive checklist
(OWASP top 10, project-specific patterns). See
`.claude/agents/security-reviewer.md` for the full scope.
