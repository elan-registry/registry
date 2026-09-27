---
paths:
  - "docs/plans/**"
---

# Planning documents (`docs/plans/`)

- Git ignores `docs/plans/` because the repository is public and these are
  private working notes. Never commit files from it. After you apply a plan
  to GitHub milestones or issues, delete it with `rm`, not `git rm`.
- **Read `docs/plans/README.md` before you read, write, or delete anything
  here.** It is the authoritative layout and lists files with sensitive data
  (for example spike captures with member email addresses).
- Layout summary for a fresh clone: `issues/issue-<NNN>-<slug>.md`,
  `sprints/<version>.md`, `features/<name>/`, `spikes/<issue>-<slug>/`,
  `analysis/`, `summaries/`, `releases/`. Only `README.md` and `HANDOFF.md`
  belong at the top level. Use `analysis/` when no other subdirectory fits.
