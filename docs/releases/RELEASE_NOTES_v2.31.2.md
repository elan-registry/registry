# Elan Registry v2.31.2 Release Notes

**Release Date:** TBD
**Type:** Patch Release - Correct Behavior for Owners and Visitors

This release accepts real 1972-1974 chassis numbers and adds an Update button to the verification email.
It also keeps stored car photos on save and shows every car on the statistics map.

## User-Facing Changes

- A car save with a missing photo now stops and tells the owner why. ([#2626](https://github.com/elan-registry/registry/issues/2626))
- Editing a car that does not load now stops early and shows a correct message. ([#1975](https://github.com/elan-registry/registry/issues/1975))
- Chassis checks accept only 9 characters for 1972-1974 and reject model years outside 1963-1974. ([#2613](https://github.com/elan-registry/registry/issues/2613))
- The verification email has an Update button, and each car edit link now opens that car. ([#2608](https://github.com/elan-registry/registry/issues/2608))
- The statistics map shows every car on first load. ([#2568](https://github.com/elan-registry/registry/issues/2568))
- A car save keeps the photo order. New photos go after the existing ones, so drag one first to make it main. ([#2642](https://github.com/elan-registry/registry/issues/2642))

## Admin-Facing Changes

- A new admin script clears the chassis override on cars that now pass validation. ([#2613](https://github.com/elan-registry/registry/issues/2613))

## Issues Resolved

- [#2626](https://github.com/elan-registry/registry/issues/2626) — A car save with a dropped photo name tells the owner, and keeps stored photos.
- [#2619](https://github.com/elan-registry/registry/issues/2619) — Behavioral tests for the stored-photo-name read and the unplaced-upload stop.
- [#1975](https://github.com/elan-registry/registry/issues/1975) — An edit of a car that does not load stops early with a correct message.
- [#2613](https://github.com/elan-registry/registry/issues/2613) — Chassis validation accepts the 9-character format for 1972-1974 cars.
- [#2608](https://github.com/elan-registry/registry/issues/2608) — The verification email has an Update button after Verify and Sold.
- [#2552](https://github.com/elan-registry/registry/issues/2552) — The map style moves to @versatiles/style 9.
- [#2568](https://github.com/elan-registry/registry/issues/2568) — The statistics map shows every car on first load.
- [#2642](https://github.com/elan-registry/registry/issues/2642) — A car save keeps the stored photo order.
