# Elan Registry v2.34.0 Release Notes

**Release Date:** November 18, 2026
**Type:** Minor Release - Members Reach Owners Without Errors

A member can register, find a car, and contact its owner. When a step fails,
the member gets a clear reason.

## User-Facing Changes

- Contacting an owner whose email address is not valid now shows a clear message that points to the Feedback form. ([#2712](https://github.com/elan-registry/registry/issues/2712))
- A car color can have up to 50 characters, and a car field that is too long shows its limit. ([#2696](https://github.com/elan-registry/registry/issues/2696))
- A location search with no match or a refusal says why, and suggestions are current and read by screen readers.
  ([#2533](https://github.com/elan-registry/registry/issues/2533), [#1512](https://github.com/elan-registry/registry/issues/1512))
- Data Quality statistics count only real photos and filled-in fields, and a failed tab loads again when selected. ([#1507](https://github.com/elan-registry/registry/issues/1507))

## Admin-Facing Changes

- Admin log entries for a failed join attempt now state the likely cause. ([#2728](https://github.com/elan-registry/registry/issues/2728))

## Issues Resolved

- [#2712](https://github.com/elan-registry/registry/issues/2712) — Contact-owner form returns a clear error, not a 500, when the owner's email address is malformed.
- [#2696](https://github.com/elan-registry/registry/issues/2696) — Car form length limits match the column sizes. A skipped transfer notice logs under CarTransfer.
- [#2728](https://github.com/elan-registry/registry/issues/2728) — Join form failure reports state the error type and the Turnstile error code.
- [#2533](https://github.com/elan-registry/registry/issues/2533) — A location search that the server refuses shows the real reason, not a network failure,
  and a search with no match says so.
- [#1512](https://github.com/elan-registry/registry/issues/1512) — Location picker has no race condition or silent discard, and has correct combobox ARIA.
- [#1507](https://github.com/elan-registry/registry/issues/1507) — Data Quality counts skip empty photo lists and blank fields. A failed statistics tab loads again.
