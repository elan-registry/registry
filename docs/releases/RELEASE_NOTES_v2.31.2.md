# Elan Registry v2.31.2 Release Notes

**Release Date:** November 8, 2026 (draft)
**Type:** Patch Release - Correct Behavior for Owners and Visitors

Real 1972-1974 chassis numbers pass, the verification email links to Update, and a car save keeps every stored photo or says why not.
The maps show every car on first load.

## User-Facing Changes

- A car save with a photo that did not reach the server now stops and tells the owner why.
- A 1972-1974 car with a 9-character chassis number (for example 73081602L) now saves with no admin override.

## Admin-Facing Changes

- An admin tool clears the override and note on cars that now pass and lists unclear notes for hand edits.

## Issues Resolved

- [#2626](https://github.com/elan-registry/registry/issues/2626) — A car save with a dropped photo name tells the owner, and keeps stored photos.
- [#2619](https://github.com/elan-registry/registry/issues/2619) — Behavioral tests for the stored-photo-name read and the unplaced-upload stop.
- [#1975](https://github.com/elan-registry/registry/issues/1975) — An edit of a car that does not load stops early with a correct message.
- WIP: [#2613](https://github.com/elan-registry/registry/issues/2613) — Chassis validation accepts the 9-character format for 1972-1974 cars.
- WIP: [#2608](https://github.com/elan-registry/registry/issues/2608) — The verification email has an Update button after Verify and Sold.
- WIP: [#2552](https://github.com/elan-registry/registry/issues/2552) — The map style moves to @versatiles/style 9.
- WIP: [#2568](https://github.com/elan-registry/registry/issues/2568) — The statistics map shows every car on first load.
