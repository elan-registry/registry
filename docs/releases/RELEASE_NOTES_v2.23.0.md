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

## Issues Resolved

- [#838](https://github.com/unibrain1/elanregistry/issues/838) — bug: owner comments double-encoded on save and display
- [#839](https://github.com/unibrain1/elanregistry/issues/839) — fix: correct HTML encoding in all outbound email paths
- [#840](https://github.com/unibrain1/elanregistry/issues/840) — fix: add missing htmlspecialchars() to car details and account HTML templates
- [#841](https://github.com/unibrain1/elanregistry/issues/841) — fix: extend car text field storage fix to all fields and expand migration scope
- [#842](https://github.com/unibrain1/elanregistry/issues/842) — fix: user and profile text fields double-encoded at storage and in output
- [#843](https://github.com/unibrain1/elanregistry/issues/843) — tech-debt: introduce Input::raw() to prevent re-introduction of storage encoding
- [#844](https://github.com/unibrain1/elanregistry/issues/844) — test: regression suite for encode-at-output pattern across all text fields
- [#847](https://github.com/unibrain1/elanregistry/issues/847) — migration: single script to decode all HTML-encoded text fields across cars, users, and profiles
