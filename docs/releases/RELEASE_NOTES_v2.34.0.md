# Elan Registry v2.34.0 Release Notes

**Release Date:** November 18, 2026
**Type:** Minor Release - Members Reach Owners Without Errors

A member can register, find a car, and contact its owner. When a step fails,
the member gets a clear reason.

## User-Facing Changes

- Contacting an owner whose email address is not valid now shows a clear message that points to the Feedback form.
- A car color can have up to 50 characters, and a car field that is too long shows its limit.
- A refused location search shows the reason, and location suggestions are current and announced to screen readers.
- Data Quality statistics count only real photos and filled-in fields, and a failed tab loads again when selected.

## Admin-Facing Changes

- Join-failure log rows show the script error type, the Turnstile state, and the Turnstile error code.

## Issues Resolved

- [#2712](https://github.com/elan-registry/registry/issues/2712) — Contact-owner form returns a clear error, not a 500, when the owner's email address is malformed.
- [#2696](https://github.com/elan-registry/registry/issues/2696) — Car form length limits match the database column sizes.
- [#2728](https://github.com/elan-registry/registry/issues/2728) — Join form failure reports state the error type and the Turnstile error code.
- [#2533](https://github.com/elan-registry/registry/issues/2533) — A location search that the server refuses shows the real reason, not a network failure.
- [#1512](https://github.com/elan-registry/registry/issues/1512) — Location picker has no race condition or silent discard, and has correct combobox ARIA.
- [#1507](https://github.com/elan-registry/registry/issues/1507) — Data Quality counts skip empty photo lists and blank fields. A failed statistics tab loads again.
