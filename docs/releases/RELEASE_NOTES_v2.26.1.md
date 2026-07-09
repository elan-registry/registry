# Elan Registry v2.26.1 Release Notes

**Release Date:** TBD
**Type:** Patch Release — Validation & Input Fixes

## Required Actions After Deployment

None.

## User-Facing Changes

### Bug Fixes

- **Validator alignment** ([#1233](https://github.com/unibrain1/elanregistry/issues/1233)): Owner profile city/state/country fields now accept up to 100 characters (matching the database schema), preventing silent truncation when a long location is saved via the car form and then edited in the profile. Required-field validation no longer incorrectly rejects the value `"0"`. Unknown form fields with null or empty values are dropped rather than written to the database.

## Developer-Facing Changes

### Improvements

- **Input class: type-safe POST/GET checks** ([#867](https://github.com/unibrain1/elanregistry/issues/867)): `ElanRegistry\Input::exists(string $type)` replaced by `Input::existsPost()` and `Input::existsGet()`, eliminating the runtime string dispatch in favour of method-name type safety. The key-based forms (`existsPost('field')` / `existsGet('field')`) enable per-key existence checks. All call sites updated. Documented in `CODING_STANDARDS.md` and `elanregistry_overrides`.
- **E2E smoke coverage for docs portal** ([#1252](https://github.com/unibrain1/elanregistry/issues/1252)): Fixed two stale paths in the not-logged-in e2e suite (`/docs/reference-library.php` → `/docs/reference/index.php`, `/docs/faq/index.php` → `/docs/guides/index.php`) and added smoke tests for 12 additional docs portal pages (docs index, all reference sub-pages, car stories, guides, and pdf-viewer). Also corrected two pre-existing stale selectors for List Cars and Car Stories.

## Admin-Facing Changes

### Bug Fixes

- **Admin contact email — readable car model** ([#1236](https://github.com/unibrain1/elanregistry/issues/1236)): Admin-to-owner contact emails now display a human-readable car model (e.g. "Series 1 / Drophead Coupé") instead of the raw internal pipe-delimited value.

### Improvements

- **Owner search performance** ([#1230](https://github.com/unibrain1/elanregistry/issues/1230)): Admin owner search now executes 1 database query regardless of result count, down from N+1 (26 queries for 25 results).

## Issues Resolved

- [#867](https://github.com/unibrain1/elanregistry/issues/867) — refactor: Input class improvements — type-safe exists() and clarify raw() signature in docs
- [#1230](https://github.com/unibrain1/elanregistry/issues/1230) — fix: N+1 query in owner search — use Owner::qualityScoreFromRow() directly on search result rows
- [#1233](https://github.com/unibrain1/elanregistry/issues/1233) — fix: validator alignment — city length caps, default case guard, required-field check, and chassis routing
- [#1236](https://github.com/unibrain1/elanregistry/issues/1236) — fix: admin contact email sends raw pipe-delimited model string in carContext instead of human-readable label
- [#1252](https://github.com/unibrain1/elanregistry/issues/1252) — test: fix stale e2e paths and add smoke tests for docs portal pages
