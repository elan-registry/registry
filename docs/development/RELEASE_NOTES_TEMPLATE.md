# Elan Registry v[VERSION] Release Notes

**Release Date:** [DATE]
**Type:** [Patch/Minor/Major] Release - [Brief Description]

## Required Actions After Deployment

[Describe any manual steps needed after deployment, or "None" if no action
required. Include SQL migrations, configuration changes, dependency updates,
or script execution. Use numbered steps with commands.]

## User-Facing Changes

Changes visible to public registry visitors (car listings, owner pages, search, etc.).

### New Features

- **[Feature Name]** ([#NNN](https://github.com/unibrain1/elanregistry/issues/NNN)): One-line description of the feature and its benefit to users.

### Improvements

- **[Improvement Name]** ([#NNN](https://github.com/unibrain1/elanregistry/issues/NNN)): One-line description of the improvement and its benefit.

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.).
Uses the same subsections (New Features, Improvements) as User-Facing Changes above.

- **[Change Name]** ([#NNN](https://github.com/unibrain1/elanregistry/issues/NNN)): One-line description.

## Issues Resolved

- [#NNN](https://github.com/unibrain1/elanregistry/issues/NNN) — GitHub issue title (verbatim)
- [#NNN](https://github.com/unibrain1/elanregistry/issues/NNN) — GitHub issue title (verbatim)

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
   issue number. Use the exact GitHub issue/PR title verbatim.
   Format: `- [#NNN](URL) — GitHub issue title`
4. **Required Actions** should only appear when there are actual post-deployment
   steps (SQL migrations, config changes, dependency installs). Otherwise state
   "None".
5. **Be concise.** No multi-line descriptions. One line per entry. The Issues
   Resolved list carries the narrative — link to the issue/PR for details.
6. **No emoji** in section headers.

### Section Guidelines

| Section | Purpose | Style |
| ------- | ------- | ----- |
| Required Actions | Post-deploy manual steps | Numbered steps with commands |
| User-Facing Changes | What public visitors will notice | Benefit-focused, one line each; subsections New Features and Improvements only |
| Admin-Facing Changes | What administrators will notice | Same format; keep separate from user-facing |
| Issues Resolved | Complete closure list | Sorted by issue number, verbatim GH title |

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
