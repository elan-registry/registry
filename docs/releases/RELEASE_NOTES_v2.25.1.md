# Elan Registry v2.25.1 Release Notes

**Release Date:** 2026-06-28
**Type:** Patch Release - UI & UX Improvements

## Required Actions After Deployment

- Run **`01-remove-account-hooks.php`** (`app/admin/scripts/fix/`) — removes the two legacy `account.php` hook rows from `us_plugin_hooks`. The full-page override (`usersc/account.php`) renders the account page directly; the hook rows are no longer needed. Script is idempotent.

## User-Facing Changes

### New Features

- **Recent Additions showcase** ([#1021](https://github.com/unibrain1/elanregistry/issues/1021)): The home page "One of the Cars" card is replaced by a "Recent Additions" showcase that fades through up to 12 cars every 5 seconds, mixing newly added entries (marked with a NEW badge) with random older cars. Manual prev/next navigation is available; auto-rotation respects `prefers-reduced-motion`.
- **NEW badge on car list** ([#1022](https://github.com/unibrain1/elanregistry/issues/1022)): Recently added cars (within 90 days, or among the 5 most recently registered) now show a NEW badge next to the Details button in the car registry list, making new registrations easy to spot at a glance.
- **Full-page account experience** ([#923](https://github.com/unibrain1/elanregistry/issues/923)): Account page now renders as a proper full-page override, enabling a complete and consistent owner account experience.

### Improvements

- **Chassis validation feedback** ([#754](https://github.com/unibrain1/elanregistry/issues/754)): Users now see an error message when chassis availability check fails silently instead of receiving no feedback.
- **Image load error recovery** ([#755](https://github.com/unibrain1/elanregistry/issues/755)): FilePond now shows clear error feedback when existing car images fail to load, rather than silently dropping them.
- **fetchImages API failure feedback** ([#1031](https://github.com/unibrain1/elanregistry/issues/1031)): When the server returns a failure response or the network call fails during photo hydration, the edit form now shows the warning banner and disables submit — preventing silent photo data loss from a form submit in an unknown state.
- **Consistent validation error display** ([#1019](https://github.com/unibrain1/elanregistry/issues/1019)): Form validation errors now use a single consistent visual pattern across all three previously inconsistent display paths.

## Technical Changes

- **Owner display consolidation** ([#531](https://github.com/unibrain1/elanregistry/issues/531)): New `ElanRegistry\OwnerView` static utility class centralises all owner name, quality badge, location, contact info, and missing-fields rendering. Eliminates ~200+ lines of duplicated inline HTML across 8 template files. Consistent quality score thresholds (≥80 success, ≥60 warning, <60 danger) and XSS escaping applied uniformly at the view layer.
- **Car detail partials** ([#924](https://github.com/unibrain1/elanregistry/issues/924), [#925](https://github.com/unibrain1/elanregistry/issues/925)): Hero action buttons and both car detail cards (Vehicle Information, Factory Data) extracted into shared PHP partials under `usersc/includes/partials/`. Eliminates ~240 lines of duplicated HTML from `details.php`; the new `usersc/account.php` (#923) adopts these partials directly. Accessibility fix: all decorative Font Awesome icons now carry `aria-hidden="true"`; guest contact button corrected from `btn-sm` to `btn`.

## Admin-Facing Changes

### Bug Fixes

- **Misleading rollback log on successful owner updates** ([#928](https://github.com/unibrain1/elanregistry/issues/928)): `ElanRegistryOwner::update()` and `create()` no longer log "Owner update failed" / "Owner creation failed" when a post-commit reload throws. The post-commit `find()` and success-logger calls now run outside the transaction's try/catch block, so the data-was-saved state is correctly reflected even when the reload fails. Also fixes a no-op `ROLLBACK` issued after a successful `COMMIT` in the same case.

### New Features

- **Chassis override indicator and filter** ([#1020](https://github.com/unibrain1/elanregistry/issues/1020)): Invalid Chassis Numbers table now shows a check icon for cars with the chassis override flag set, and includes a "Hide cars with override set" checkbox to filter them out — making triage faster by distinguishing cars already handled from those still needing attention.

### Improvements

- **Local Playwright test suite fully restored** ([#881](https://github.com/unibrain1/elanregistry/issues/881)): Fixed broken local MAMP config (`elan_registry` typo, leading-slash `goto` calls), updated all test files to work against the local dev environment, converted TypeScript security specs to JavaScript to eliminate Node.js DEP0205 deprecation warnings, upgraded `@playwright/test` from 1.56 to 1.61.1 (also resolves residual DEP0205 from Playwright's own TypeScript loader on Node 26) and downgraded `dotenv` from 17 to 16 (also triggered DEP0205). 118 tests pass; 35 skip gracefully (E2E tests that target production environments, and authenticated tests that skip when `.env.local` credentials are absent).

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
- [#1020](https://github.com/unibrain1/elanregistry/issues/1020) — admin: show chassis override indicator and add filter to Invalid Chassis Numbers table
- [#1021](https://github.com/unibrain1/elanregistry/issues/1021) — feat: replace "One of the Cars" with cycling car showcase
- [#1022](https://github.com/unibrain1/elanregistry/issues/1022) — feat: add NEW badge to recently added cars in the car list
- [#1031](https://github.com/unibrain1/elanregistry/issues/1031) — fix: fetchImages silent failures — data.success guard and outer catch logging
