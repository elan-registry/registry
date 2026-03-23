# Development Workflow Guide

This guide covers the complete development workflow for Elan Registry:
installation, git basics, the milestone development lifecycle, and merge
conflict resolution.

If you're already familiar with git and GitHub, see
[Quick Reference](Quick-Reference) for a condensed version.

## Table of Contents

- [Command Types Used in This Guide](#command-types-used-in-this-guide)
- [1. Getting Started — Installation](#1-getting-started--installation)
- [2. Git Basics](#2-git-basics)
- [3. Milestone Development Lifecycle](#3-milestone-development-lifecycle)
- [4. Standalone Issues (No Milestone)](#4-standalone-issues-no-milestone)
- [5. Handling Merge Conflicts](#5-handling-merge-conflicts)
- [6. Useful Git Commands Reference](#6-useful-git-commands-reference)
- [7. Getting Help](#7-getting-help)

## Command Types Used in This Guide

| Command | Type | Where It Works |
| --- | --- | --- |
| `git ...` | Standard git command | Any terminal |
| `composer ...` | PHP package manager | Any terminal |
| `npm ...` | Node.js package manager | Any terminal |
| `/start-milestone`, `/start-issue`, etc. | Claude Code command | Claude Code CLI for this repository |

---

## 1. Getting Started — Installation

### Prerequisites

- **1.0** PHP 8.2+ (`php -v`)
- **1.1** MySQL 8.0+ (`mysql --version`)
- **1.2** Composer — <https://getcomposer.org> (`composer --version`)
- **1.3** Node.js and npm — <https://nodejs.org> (`node --version && npm --version`)
- **1.4** Git (`git --version`)
- **1.5** GitHub CLI — <https://cli.github.com> (`gh --version`)

### Clone and Install

- **1.6** Clone the repository:

  ```bash
  git clone https://github.com/unibrain1/elanregistry.git
  cd elanregistry
  ```

- **1.7** Install dependencies:

  ```bash
  composer install
  npm install
  ```

- **1.8** Setup git hooks (CRITICAL — runs quality checks before each commit):

  ```bash
  ./scripts/setup-git-hooks.sh
  ```

- **1.9** Verify hooks installed:

  ```bash
  ./scripts/check-hooks-status.sh
  ```

### Configuration and Testing

- **1.10** Configure your local environment. See the
  [environment configuration](https://github.com/unibrain1/elanregistry/blob/main/docs/development/ENVIRONMENT.md)
  guide for database credentials and API keys. Never commit sensitive data —
  use `.env` (in `.gitignore`).

- **1.11** Verify setup with a quick test:

  ```bash
  composer test:quick
  ```

- **1.12** Read the
  [coding standards](https://github.com/unibrain1/elanregistry/blob/main/docs/development/CODING_STANDARDS.md)
  and [Quick Reference](Quick-Reference) before coding.

---

## 2. Git Basics

### Key Concepts

- **2.0** **Repository:** Project folder with all files and complete change
  history. You cloned a remote copy from GitHub.
- **2.1** **Branch:** Independent line of development. Make changes in
  isolation, then merge back.
- **2.2** **Commit:** Snapshot of changes with a descriptive message.
- **2.3** **Push:** Upload local commits to GitHub.
- **2.4** **Pull Request (PR):** Formal request to merge changes. Requires
  review before merging.

### Golden Rules

- **2.5** Never commit directly to `main` — use milestone and issue branches.
- **2.6** Always create a Pull Request — code gets reviewed before merging.
- **2.7** Keep `main` up to date — pull latest before starting new work.
- **2.8** Use descriptive branch names:
  `milestone/v2.17.0`, `bug/512-negative-price`, `feature/423-car-export`.

---

## 3. Milestone Development Lifecycle

Features and releases follow a structured five-command lifecycle. The branch
structure is: `main` ← `milestone/vX.Y.Z` ← `issue/NNN-slug`

### Overview

```text
/start-milestone v2.17.0     — Create milestone branch, draft release notes
  /start-issue 423            — Branch, plan, implement, test, security review
  /simplify                   — Clean up the code (optional, recommended)
  /commit                     — Commit changes locally
  /commit-push-pr             — Push + PR targeting milestone branch
  /finish-issue 423           — Monitor CI, squash-merge, close issue
  (repeat for each issue)
/finish-milestone v2.17.0    — PR to main, finalize release notes, update wiki
/review-pr                   — Multi-agent PR review before merge
/release-milestone v2.17.0   — Merge, tag, GitHub release, close milestone
```

### Authenticate GitHub CLI (one-time setup) [you]

- **3.0** Claude Code needs permission to access GitHub on your behalf.
  Run the following command **in your terminal** (not in Claude Code):

  ```bash
  gh auth login
  ```

  Follow the prompts to complete authentication. You only need to do this
  **once per machine**.

### Pull the latest code and prepare [git]

- **3.1** Always start by pulling the latest code:

  ```bash
  git checkout main
  git pull origin main
  ```

- **3.2** Clean up old branches that have been merged and removed from
  GitHub [claude]:

  ```text
  /clean_gone
  ```

### Start the milestone [claude]

- **3.3** Run the start-milestone command with the version:

  ```text
  /start-milestone v2.17.0
  ```

- **3.4** This creates a `milestone/v2.17.0` branch from `main`, pushes it
  to GitHub, drafts release notes at
  `docs/releases/RELEASE_NOTES_v2.17.0.md`, lists open issues, and
  recommends an issue order.

### Work on each issue [claude + you]

- **3.5** Start work on the first issue:

  ```text
  /start-issue 423
  ```

- **3.6** `/start-issue` creates an issue branch from the milestone branch
  (e.g., `feature/423-car-data-export`), explores the codebase, plans
  implementation, writes code, runs tests, and performs a security review.
  You stay in control — nothing is committed or pushed yet.

### Review the code [you]

- **3.7** **IMPORTANT:** Do not blindly trust AI-generated code. Review
  every change:

  ```bash
  git diff
  ```

  Check for logic errors, incorrect assumptions, missing edge cases, and
  code you don't understand.

- **3.8** Ask Claude Code to fix any issues before proceeding. You are
  responsible for the code that gets committed.

### Clean up the code (optional, recommended) [claude]

- **3.9** Improve clarity and maintainability:

  ```text
  /simplify
  ```

### Commit the changes [claude]

- **3.10** When satisfied:

  ```text
  /commit
  ```

  > **NOTE:** Pre-commit hooks run quality checks on staged files:
  >
  > 1. PHP Coding Standards (security, type hints, docs)
  > 2. Markdown Linting
  > 3. Regression Test Validation
  > 4. Unit Tests (if critical files modified)
  > 5. PHPStan Static Analysis
  > 6. JavaScript Linting (if JS files modified)
  >
  > If any check fails, the commit is rejected. **You are responsible for
  > fixing the problems** and re-committing.

### Push and create a PR [claude]

- **3.11** Create a PR targeting the milestone branch:

  ```text
  /commit-push-pr
  ```

  > **IMPORTANT:** The PR must target the milestone branch
  > (e.g., `milestone/v2.17.0`), not `main`. Include
  > `Closes #423` in the PR body.

### Test locally [you]

- **3.12** Run test suites:

  ```bash
  composer test:quick              # Unit tests (under 30s)
  composer test:medium             # Unit + Integration tests
  ```

- **3.13** If your changes affect the UI:

  ```bash
  npm run playwright:test
  ```

- **3.14** Do manual functional testing in your browser. Exercise the
  feature, check edge cases and error states.

- **3.15** **IMPORTANT:** Never merge untested code. You are responsible for
  code quality.

### Finish the issue [claude]

- **3.16** Once the PR passes CI checks and review:

  ```text
  /finish-issue 423
  ```

- **3.17** This monitors CI checks, squash-merges the PR into the milestone
  branch (keeping one clean commit per issue), closes the GitHub issue, and
  deletes the issue branch.

- **3.18** Repeat steps 3.5–3.17 for each remaining issue in the milestone.

### When all issues are complete: finish the milestone [claude]

- **3.19** Once all issues are merged into the milestone branch:

  ```text
  /finish-milestone v2.17.0
  ```

- **3.20** This finalizes release notes, updates wiki/architecture
  documentation if the milestone changed application structure, creates a PR
  from the milestone branch targeting `main` with all closing keywords, and
  prepares everything for review.

- **3.21** Run a comprehensive review [claude]:

  ```text
  /review-pr
  ```

- **3.22** Fix any issues identified by the review. Commit fixes, push the
  milestone branch, and re-run `/review-pr` until all issues are resolved.

- **3.23** The repo owner reviews and merges this PR. Your work is ready for
  release.

### Release the milestone [claude]

- **3.24** After the milestone PR is merged:

  ```text
  /release-milestone v2.17.0
  ```

- **3.25** This merges the PR (if not already merged), creates an annotated
  git tag, pushes to origin, publishes a GitHub release with release notes,
  and closes the GitHub milestone.

- **3.26** Deploy manually when ready:

  ```bash
  # Deploy to TEST first (recommended)
  git push test main
  git push test v2.17.0

  # After validation, deploy to PRODUCTION
  git push prod main
  git push prod v2.17.0
  ```

---

## 4. Standalone Issues (No Milestone)

For issues not part of a milestone (quick fixes, hotfixes):

- **4.0** Use `/start-issue` — it detects that the issue has no milestone and
  branches from `main`.

- **4.1** After committing and testing, create a PR directly to `main`:

  ```text
  /commit-push-pr
  ```

- **4.2** Run `/review-pr`, fix any issues, then the repo owner reviews and
  merges.

- **4.3** Use `/release` (the standalone release command) for hotfix releases
  outside the milestone lifecycle.

---

## 5. Handling Merge Conflicts

### What is a Merge Conflict?

When two people edit the same lines in the same file, git can't automatically
decide which changes to keep. This is normal in team development.

### Identifying a Conflict

Git tells you explicitly:

```text
CONFLICT (content): Merge conflict in path/to/file.php
```

The conflicted file contains markers:

```text
<<<<<<< HEAD
  your changes here
=======
  their changes here
>>>>>>> branch-name
```

- **5.0** Section between `<<<<<<<` and `=======` is YOUR code.
- **5.1** Section between `=======` and `>>>>>>>` is the INCOMING code.

### Resolving a Conflict

- **5.2** Open the conflicted file.
- **5.3** Look for conflict markers.
- **5.4** Decide which changes to keep: yours only, theirs only, or both
  combined.
- **5.5** Remove ALL conflict markers.
- **5.6** Save the file.
- **5.7** Stage the resolved file:

  ```bash
  git add path/to/file.php
  ```

- **5.8** Complete the merge:

  ```bash
  git commit
  ```

### Example

**Before** (conflicted):

```php
<<<<<<< HEAD
function getUserName($id) {
    return "John Doe";
=======
function getUserName($id) {
    $user = getUser($id);
    return $user->name;
>>>>>>> feature/user-lookup
}
```

**After** (resolved):

```php
function getUserName($id) {
    $user = getUser($id);
    return $user->name;
}
```

### Tips to Reduce Conflicts

- **5.9** Communicate with teammates about what you're working on.
- **5.10** Keep branches short-lived — finish and merge quickly.
- **5.11** Smaller, focused PRs that change fewer files reduce conflicts.

---

## 6. Useful Git Commands Reference

| Command | Description |
| --- | --- |
| `git status` | Check status and current branch |
| `git diff` | View line-by-line changes |
| `git diff --staged` | View staged changes |
| `git log --oneline -10` | View 10 most recent commits |
| `git branch` | List local branches |
| `git branch -a` | List all branches (local + remote) |
| `git checkout -b branch-name` | Create and switch to new branch |
| `git checkout branch-name` | Switch to existing branch |
| `git stash` | Temporarily save uncommitted changes |
| `git stash pop` | Restore saved changes |
| `git branch -d branch-name` | Delete a local branch |

---

## 7. Getting Help

- Read the
  [coding standards](https://github.com/unibrain1/elanregistry/blob/main/docs/development/CODING_STANDARDS.md)
  for code quality requirements
- See [Quick Reference](Quick-Reference) for common tasks
- Check GitHub Issues for "good first issue" labels
- Start your first feature with `/start-issue <number>`

Welcome to the team!
