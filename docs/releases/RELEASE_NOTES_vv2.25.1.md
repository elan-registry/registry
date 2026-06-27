# Elan Registry v2.25.1 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - UI & UX Improvements

## Required Actions After Deployment

None

## User-Facing Changes

### New Features

- **Full-page account experience** ([#923](https://github.com/unibrain1/elanregistry/issues/923)): Account page now renders as a proper full-page override, enabling a complete and consistent owner account experience.

### Improvements

- **Chassis validation feedback** ([#754](https://github.com/unibrain1/elanregistry/issues/754)): Users now see an error message when chassis availability check fails silently instead of receiving no feedback.
- **Image load error recovery** ([#755](https://github.com/unibrain1/elanregistry/issues/755)): FilePond now shows clear error feedback when existing car images fail to load, rather than silently dropping them.
- **Consistent validation error display** ([#1019](https://github.com/unibrain1/elanregistry/issues/1019)): Form validation errors now use a single consistent visual pattern across all three previously inconsistent display paths.

## Issues Resolved

- [#531](https://github.com/unibrain1/elanregistry/issues/531) — Create OwnerView class to consolidate owner presentation logic
- [#754](https://github.com/unibrain1/elanregistry/issues/754) — fix: add user feedback when chassis availability check fails silently
- [#755](https://github.com/unibrain1/elanregistry/issues/755) — fix: improve FilePond error recovery when existing car images fail to load
- [#881](https://github.com/unibrain1/elanregistry/issues/881) — Playwright local config: tests cannot run against MAMP
- [#923](https://github.com/unibrain1/elanregistry/issues/923) — refactor: replace account hook pair with usersc/account.php full-page override
- [#924](https://github.com/unibrain1/elanregistry/issues/924) — refactor: extract car hero action buttons into shared partial
- [#925](https://github.com/unibrain1/elanregistry/issues/925) — refactor: extract shared car display card partials (vehicle info, factory data)
- [#928](https://github.com/unibrain1/elanregistry/issues/928) — bug: ElanRegistryOwner::update() broad catch wraps post-commit find() causing misleading rollback log
- [#1019](https://github.com/unibrain1/elanregistry/issues/1019) — ux: standardize validation error display across all three error mechanisms
