# Elan Registry v[VERSION] Release Notes

**Release Date:** [DATE]
**Type:** [Patch/Minor/Major] Release - [Brief Description]

## User-Facing Changes

Changes visible to public registry visitors (car listings, owner pages, search, etc.). One sentence each.

- **[Feature/Change Name]** ([#NNN](https://github.com/elan-registry/registry/issues/NNN)): One-sentence description of what changed and its benefit to users.

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.). One sentence each.

- **[Change Name]** ([#NNN](https://github.com/elan-registry/registry/issues/NNN)): One-sentence description.

## Issues Resolved

- [#NNN](https://github.com/elan-registry/registry/issues/NNN) — One-sentence summary of what shipped.
- [#NNN](https://github.com/elan-registry/registry/issues/NNN) — One-sentence summary of what shipped.

---

## Template Instructions

**Delete everything below the `---` line when creating actual release notes.**

### Working Draft Convention

`docs/releases/` holds **only the current milestone's working draft**. Once
`/release-milestone` publishes the notes to GitHub Releases, the file is
deleted from the repo. GitHub Releases is the canonical archive — do not
accumulate historical files here.

### For AI Agents

When generating release notes:

1. **Gather all changes** from the milestone: closed issues, merged PRs, and
   commits since the last release tag.
2. **User-Facing Changes** are changes visible to public registry visitors
   (car listings, owner pages, search, etc.). **Admin-Facing Changes** are
   changes visible only to administrators (admin dashboard, maintenance tools,
   settings). Keep these sections separate. Each item is one line, benefit-focused.
   Remove a section or subsection entirely if it has no entries.
3. **Issues Resolved** lists every closed issue in the milestone, sorted by
   issue number. Each entry is **one sentence** summarizing what shipped —
   not the verbatim GitHub title, and not a changelog essay. Full detail
   lives in the issue/PR itself; the release notes are a pointer, not a
   substitute for reading it. Format: `- [#NNN](URL) — One-sentence summary.`
   This describes the *finished* release notes — `/finish-milestone` verifies
   every entry matches this format before opening the milestone PR.
   Mid-milestone, entries for issues not yet completed carry a `WIP:` prefix
   (`- WIP: [#NNN](URL) — One-sentence summary.`), added by `/start-milestone`
   when it pre-populates the section and removed by `/finish-issue` once that
   issue's PR merges — every entry must have that prefix stripped by the time
   `/finish-milestone` runs.
4. **Deployment steps do not go here.** Migrations, new env vars,
   admin-script/permission registration, and manual verification runbooks
   belong in the deploy sheet — rendered by `/finish-milestone` at
   `docs/plans/releases/<version>-deploy.md` from
   `RELEASE_INSTRUCTIONS_TEMPLATE.md` — not in this file. This file is a
   changelog index; the deploy sheet is the operational procedure.
5. **Be concise.** No multi-line descriptions. One sentence per entry in
   every section. Link to the issue/PR for anyone who needs more.
6. **No emoji** in section headers.

### Section Guidelines

| Section | Purpose | Style |
| ------- | ------- | ----- |
| User-Facing Changes | What public visitors will notice | Benefit-focused, one sentence each |
| Admin-Facing Changes | What administrators will notice | Same format; keep separate from user-facing |
| Issues Resolved | Complete closure list | Sorted by issue number, one-sentence summary (not verbatim GH title) |

### Release Requirements

- **Mandatory** for all major (x.0.0) and minor (x.y.0) releases
- **Optional** for patch releases (x.y.z), recommended for significant patches
- All releases must have corresponding git tags
- GitHub releases must be created with `gh release create`

### Placeholder Reference

- `[VERSION]` → `2.14.0`, `3.0.0`
- `[DATE]` → `February 1, 2026`
- `[Patch/Minor/Major]` → Based on semantic versioning
- `[Brief Description]` → `Data Quality & Validation`, `Security Hardening`
- `[#NNN]` → Actual GitHub issue/PR number
