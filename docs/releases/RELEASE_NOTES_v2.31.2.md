# Elan Registry v2.31.2 Release Notes

**Release Date:** November 8, 2026 (draft)
**Type:** Patch Release - Correct Behavior for Owners and Visitors

Real 1972-1974 chassis numbers pass, the verification email links to Update, and a car save keeps every stored photo or says why not.
The statistics map shows every car on first load.

## User-Facing Changes

- A car save with a photo that did not reach the server now stops and tells the owner why.
- The verification email has an Update button. It opens the car's edit page, after a login if necessary.
- Links to a car's edit page now open that car. Before, they opened a blank Add Car form. This applies to the
  "Login and Review or Update Your Car Record" button in the verification email, the "Review & Update" link on the
  verify page, and the "Update Your Car Record" button in the email that an admin sends to an owner.
- A 1972-1974 car with a 9-character chassis number (for example 73081602L) now saves with no admin override.
- Some chassis numbers that passed before now fail: 11-character chassis numbers for model years 1972-1974,
  race cars outside model years 1963-1966, and model years outside 1963-1974. An admin must check each of these cars or set the override.
- The statistics map shows every car on first load, on a phone too.

## Admin-Facing Changes

- An admin tool clears the override and note on cars that now pass and lists unclear notes for hand edits.

## Issues Resolved

- [#2626](https://github.com/elan-registry/registry/issues/2626) — A car save with a dropped photo name tells the owner, and keeps stored photos.
- [#2619](https://github.com/elan-registry/registry/issues/2619) — Behavioral tests for the stored-photo-name read and the unplaced-upload stop.
- [#1975](https://github.com/elan-registry/registry/issues/1975) — An edit of a car that does not load stops early with a correct message.
- [#2613](https://github.com/elan-registry/registry/issues/2613) — Chassis validation accepts the 9-character format for 1972-1974 cars.
- [#2608](https://github.com/elan-registry/registry/issues/2608) — The verification email has an Update button after Verify and Sold.
- [#2552](https://github.com/elan-registry/registry/issues/2552) — The map style moves to @versatiles/style 9.
- [#2568](https://github.com/elan-registry/registry/issues/2568) — The statistics map shows every car on first load.
