# Elan Registry v2.23.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - Encode-at-Output Reform

## Required Actions After Deployment

Run the data migration script once immediately after deploying all code changes:

1. Log in as admin and navigate to the Admin Maintenance tab.
2. Open `app/admin/scripts/fix/03-Decode-All-HTML-Encoded-Fields.php`.
3. Review the pre-flight counts and confirm to proceed.
4. Verify the post-run report shows zero remaining encoded values.

The script creates a `BackupManager` snapshot of `cars`, `users`, and `profiles` before writing. Retain the backup path shown in the output for rollback reference.

## User-Facing Changes

### Improvements

- **Special characters in car records display correctly** ([#838](https://github.com/unibrain1/elanregistry/issues/838), [#841](https://github.com/unibrain1/elanregistry/issues/841)): Apostrophes, accented characters, and symbols in comments, color, engine, chassis, and website fields now display as readable plain text — no more `&amp;` or `&#039;` visible on car detail pages.
- **Owner names and locations display correctly** ([#842](https://github.com/unibrain1/elanregistry/issues/842)): Owner first/last name, city, state, and country no longer show HTML entity strings after a profile re-save.
- **Outbound emails render readable text** ([#839](https://github.com/unibrain1/elanregistry/issues/839)): Car verification, owner contact, and feedback emails no longer contain double-encoded or unescaped content.

## Admin-Facing Changes

### New Features

- **HTML-encoded field migration script** ([#847](https://github.com/unibrain1/elanregistry/issues/847)): One-time admin fix script decodes all double- and triple-encoded text across `cars`, `users`, and `profiles` tables and re-syncs denormalised `cars` copies from corrected source rows.

## Technical Changes

- **`ElanRegistry\Input::raw()` storage-safe input method** ([#843](https://github.com/unibrain1/elanregistry/issues/843)): New project-owned `ElanRegistry\Input` class in `usersc/classes/Input.php` provides `Input::raw()` — a POST/GET reader that performs no HTML encoding, establishing the correct pattern for all values destined for the database. `CODING_STANDARDS.md` updated with input/output encoding guidance.
- **Regression suite for encode-at-output pattern** ([#844](https://github.com/unibrain1/elanregistry/issues/844)): 121 regression tests across `tests/regression/EncodeAtOutputRegressionTest.php` (new), `tests/integration/database/CarDatabaseOperationsTest.php` (extended), and `tests/playwright/car-edit-text-save.test.js` (extended) verify the full encode-at-output reform holds across all 7 text fields. Includes round-trip idempotency, XSS gate, migration decode-logic coverage, and full-browser form submission checks. Also fixes pre-existing `@dataProvider` → `#[DataProvider]` migration in `Issue841RegressionTest.php` and `Issue842RegressionTest.php` (PHPUnit 12 compatibility).
- **Fix script cleanup step in `/start-milestone`** ([#859](https://github.com/unibrain1/elanregistry/issues/859)): `/start-milestone` now prompts at Step 3.5 to classify unarchived scripts in `app/admin/scripts/fix/` as archived, promoted to maintenance, or held. Committed as a discrete first commit on the milestone branch. `CLAUDE.md` milestone lifecycle description updated.
- **Output escaping on car detail and account templates** ([#840](https://github.com/unibrain1/elanregistry/issues/840)): `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` applied to all unescaped car and factory data output points in `app/cars/details.php` and `usersc/plugins/hooker/hooks/account_bottom_hook.php` (~40 output points total). Location fields (`city`, `state`, `country`) also hardened. Website href restricted to `http`/`https` schemes only and `rel="noopener noreferrer"` added. Regression test added in `tests/playwright/car-details-output-escaping.test.js`.

## Dependencies

- **esbuild bumped 0.25.12 → 0.28.1** (PR [#872](https://github.com/unibrain1/elanregistry/pull/872)): dev-dependency upgrade via Dependabot. Build pipeline (`npm run build`) re-verified against CI; no source changes required.

## Issues Resolved

- [#859](https://github.com/unibrain1/elanregistry/issues/859) — process: add fix script cleanup step to /start-milestone workflow
- [#838](https://github.com/unibrain1/elanregistry/issues/838) — bug: owner comments double-encoded on save and display
- [#839](https://github.com/unibrain1/elanregistry/issues/839) — fix: correct HTML encoding in all outbound email paths
- [#840](https://github.com/unibrain1/elanregistry/issues/840) — fix: add missing htmlspecialchars() to car details and account HTML templates
- [#841](https://github.com/unibrain1/elanregistry/issues/841) — fix: extend car text field storage fix to all fields and expand migration scope
- [#842](https://github.com/unibrain1/elanregistry/issues/842) — fix: user and profile text fields double-encoded at storage and in output
- [#843](https://github.com/unibrain1/elanregistry/issues/843) — tech-debt: introduce Input::raw() to prevent re-introduction of storage encoding
- [#844](https://github.com/unibrain1/elanregistry/issues/844) — test: regression suite for encode-at-output pattern across all text fields
- [#847](https://github.com/unibrain1/elanregistry/issues/847) — migration: single script to decode all HTML-encoded text fields across cars, users, and profiles
