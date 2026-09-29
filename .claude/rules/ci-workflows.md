---
paths:
  - ".github/workflows/**"
---

# CI workflow parity

A change to `.github/workflows/` must end up the same on the working branch
and on `main`. A workflow that differs between them makes branch CI results a
bad predictor of `main`.

Before you commit a workflow change, run
`git diff origin/main <branch> -- .github/`. If a new step needs files that
exist only on the milestone branch, ask the user how to sync it. A step on
`main` would fail, because the `git-hooks` job runs on every push to `main`.

Parity applies to CI workflow files only. It does not apply to `.claude/`
commands, agents, or other docs.
