# Elan Registry v2.32.0 Release Notes

**Release Date:** November 11, 2026
**Type:** Minor Release - Phone Photos Upload

An owner can add full-resolution phone photos to their car. When a photo does not save, the owner gets a clear message.

## User-Facing Changes

- Owners can add phone photos up to 20 MB (the browser shrinks them first), but WebP is no longer accepted. ([#2627](https://github.com/elan-registry/registry/issues/2627))
- A car save with photos over the size limit now tells the owner the photos are too large. ([#2647](https://github.com/elan-registry/registry/issues/2647))
- When a car save rejects photos, the message names each bad photo and gives the reason. ([#1844](https://github.com/elan-registry/registry/issues/1844))
- A new car with an invalid date now shows the date error, and dates one day ahead are allowed. ([#2655](https://github.com/elan-registry/registry/issues/2655))

## Admin-Facing Changes

- A new Car Photos tab lets admins and editors reattach lost photos and fix photo order. ([#2644](https://github.com/elan-registry/registry/issues/2644))

## Issues Resolved

- [#2627](https://github.com/elan-registry/registry/issues/2627) — Raise the car photo upload limit from 3 MB to at least 4.5 MB.
- [#2647](https://github.com/elan-registry/registry/issues/2647) — Tell the owner that the photos are too large when a car save exceeds the server limit.
- [#1844](https://github.com/elan-registry/registry/issues/1844) — Name each rejected photo and its reason in a multi-photo car save.
- [#2644](https://github.com/elan-registry/registry/issues/2644) — Add an admin Car Photos tab to reattach photos that are on disk but not on the car.
- [#1965](https://github.com/elan-registry/registry/issues/1965) — Pin GitHub Actions to commit SHAs and add a Dependabot cooldown.
- [#2655](https://github.com/elan-registry/registry/issues/2655) — Return 422 with the date error on car create. Log the dates. Allow dates one day ahead.
