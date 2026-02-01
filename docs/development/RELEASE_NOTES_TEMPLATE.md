# Elan Registry v[VERSION] Release Notes

**Release Date:** [DATE]
**Type:** [Patch/Minor/Major] Release - [Brief Description]

## Required Actions After Deployment

[Describe any manual steps needed after deployment, or "None" if no action
required. Include SQL migrations, configuration changes, dependency updates,
or script execution. Use numbered steps with commands.]

## User-Facing Changes

### New Features

- **[Feature Name]** ([#NNN](https://github.com/unibrain1/elanregistry/issues/NNN)): One-line description of the feature and its benefit to users.

### Improvements

- **[Improvement Name]** ([#NNN](https://github.com/unibrain1/elanregistry/issues/NNN)): One-line description of the improvement and its benefit.

### Bug Fixes

- **[Fix Name]** ([#NNN](https://github.com/unibrain1/elanregistry/issues/NNN)): One-line description of what was fixed.

## Technical Changes

- **[Change Name]** ([#NNN](https://github.com/unibrain1/elanregistry/issues/NNN)): One-line description of the technical change.

## Issues Resolved

- [#NNN](https://github.com/unibrain1/elanregistry/issues/NNN) — Issue title
- [#NNN](https://github.com/unibrain1/elanregistry/issues/NNN) — Issue title

## Summary

[One to two sentences: total issues resolved, PRs merged, key themes.]

---

## Template Instructions

**Delete everything below the `---` line when creating actual release notes.**

### For AI Agents

When generating release notes:

1. **Gather all changes** from the milestone: closed issues, merged PRs, and
   commits since the last release tag.
2. **User-Facing Changes** should focus on the benefit to the user, not
   implementation details. Each item is one line. Remove subsections (New
   Features, Improvements, Bug Fixes) if they have no entries.
3. **Technical Changes** are one line each with an issue/PR link. These cover
   code quality, refactoring, CI/CD, test infrastructure, and internal
   improvements that don't directly affect users.
4. **Issues Resolved** lists every closed issue in the milestone, sorted by
   issue number. Use the format `- [#NNN](URL) — Title`.
5. **Required Actions** should only appear when there are actual post-deployment
   steps (SQL migrations, config changes, dependency installs). Otherwise state
   "None".
6. **Be concise.** No multi-line descriptions. No verbose explanations. Link to
   the issue/PR for details.
7. **No emoji** in section headers.

### Section Guidelines

| Section | Purpose | Style |
| ------- | ------- | ----- |
| Required Actions | Post-deploy manual steps | Numbered steps with commands |
| User-Facing Changes | What users will notice | Benefit-focused, one line each |
| Technical Changes | Internal improvements | Brief, one line each with link |
| Issues Resolved | Complete closure list | Sorted by issue number |
| Summary | Quick overview | 1–2 sentences |

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
