---
allowed-tools: Bash(git checkout --branch:*), Bash(git add:*), Bash(git status:*), Bash(git push:*), Bash(git commit:*), Bash(gh pr create:*)
description: Commit, push, and open a draft PR
model: claude-haiku-4-5
---

# Commit, Push, and Open a Draft PR

## Context

- Current git status: !`git status`
- Current git diff (staged and unstaged changes): !`git diff HEAD`
- Current branch: !`git branch --show-current`

## Your task

Based on the above changes:

1. Create a new branch if on main
2. Create a single commit with an appropriate message
3. Push the branch to origin
4. Create a **draft** pull request using `gh pr create --draft` — PRs in this
   project are opened as draft so review/fix cycles (`/address-pr-comments`)
   don't spam watchers with notifications; `/finish-issue` marks the PR ready
   for review once it's clean and about to merge.
5. You have the capability to call multiple tools in a single response. You
   MUST do all of the above in a single message. Do not use any other tools
   or do anything else. Do not send any other text or messages besides
   these tool calls.
