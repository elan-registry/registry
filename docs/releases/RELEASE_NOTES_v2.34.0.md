# Elan Registry v2.34.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - Members Reach Owners Without Errors

A member can register, find a car, and contact its owner. When a step fails,
the member gets a clear reason.

## User-Facing Changes

- Contacting an owner whose email address is not valid now shows a clear message that points to the Feedback form.
- A car color can now have up to 50 characters. A car field that is too long shows its limit.

## Admin-Facing Changes

- [Fill in as issues complete.]

## Issues Resolved

- [#2712](https://github.com/elan-registry/registry/issues/2712) — Contact-owner form returns a clear error, not a 500, when the owner's email address is malformed.
- WIP: [#2696](https://github.com/elan-registry/registry/issues/2696) — Car form length limits match the database column sizes.
- WIP: [#2728](https://github.com/elan-registry/registry/issues/2728) — Join form failure reports from iOS Safari include the error detail.
- WIP: [#2533](https://github.com/elan-registry/registry/issues/2533) — A location search that the server refuses shows the real reason, not a network failure.
- WIP: [#1512](https://github.com/elan-registry/registry/issues/1512) — Location picker has no race condition or silent discard, and has correct combobox ARIA.
- WIP: [#1507](https://github.com/elan-registry/registry/issues/1507) — Statistics count photos and JSON data correctly.
