---
paths:
  - "docs/plans/**"
---

# Planning documents (`docs/plans/`)

- Git ignores `docs/plans/` because the repository is public and these are
  private working notes. Never commit files from it. After you apply a plan
  to GitHub milestones or issues, delete it with `rm`, not `git rm`.
  Exception: `docs/plans/releases/v2.30-deploy.md` is one named, committed
  file (see `.gitignore`) that carries a combined deploy sheet across several
  milestones. It is not a general exception for `docs/plans/releases/` — any
  other file in that directory is gitignored like the rest of `docs/plans/`.
- **`docs/plans/README.md`, if present in your checkout, is the authoritative
  layout and lists files with sensitive data** (for example spike captures
  with member email addresses). It is itself gitignored, so a fresh clone
  does not have it — this file and `docs/plans/HANDOFF.md`, when either
  exists locally, are the only planning docs meant to persist across
  clones/sessions; everything else here is scratch space for the current
  session or an in-progress plan.
- Layout summary for a fresh clone: `issues/issue-<NNN>-<slug>.md`,
  `features/<name>/`, `spikes/<issue>-<slug>/`, `analysis/`, `summaries/`,
  `releases/`. Only `README.md` and `HANDOFF.md`
  belong at the top level. Use `analysis/` when no other subdirectory fits.
