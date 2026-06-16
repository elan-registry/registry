---
description: Run a security review of recent code changes (OWASP, CSRF, SQL injection, XSS)
model: claude-haiku-4-5-20251001
---

# Security Review

Perform a comprehensive security audit of recent code changes in this project.

## Steps

1. **Identify changed files**: Run `git diff --name-only` to find modified
   files (both staged and unstaged). If no uncommitted changes exist, compare
   the current branch against its **actual base branch** — not always `main`:

   ```bash
   BRANCH=$(git branch --show-current)
   case "$BRANCH" in
     issue/*|bug/*|feature/*|fix/*)
       # Issue branches base off the current milestone branch.
       BASE=$(git branch -a --list 'origin/milestone/*' | sort -V | tail -1 | sed 's|.*origin/||')
       [ -z "$BASE" ] && BASE="origin/main"
       ;;
     milestone/*)
       BASE="origin/main"
       ;;
     *)
       BASE="origin/main"
       ;;
   esac
   git diff --name-only "$BASE...HEAD"
   ```

   This matters because issue branches are based on `milestone/vX.Y.Z`, not
   `main` — comparing against `main` includes the entire milestone's prior
   work and floods the review with already-reviewed code.

2. **Filter to relevant files**: Focus on `.php` and `.js` files. Skip
   documentation, tests, and static assets unless they contain security-relevant
   code.

3. **Launch the security-reviewer agent** via the Agent tool with
   `subagent_type: "security-reviewer"`. Provide it with:
   - The list of changed files
   - The full diff (`git diff` output or `git diff "$BASE...HEAD"` from step 1)
   - Instructions to read and review each changed file completely

4. **Report results**: Present the security-reviewer agent's findings to the
   user. If critical or high severity issues are found, recommend fixing them
   before proceeding.

## When to Use

- Before creating a commit or pull request
- After implementing features that handle user input, authentication,
  database queries, or file operations
- As a mandatory check per CLAUDE.md guidelines

The security-reviewer agent defines its own comprehensive checklist
(OWASP top 10, project-specific patterns). See
`.claude/agents/security-reviewer.md` for the full scope.
