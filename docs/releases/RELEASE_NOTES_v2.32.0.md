# Elan Registry v2.32.0 Release Notes

**Release Date:** November 11, 2026
**Type:** Minor Release - Phone Photos Upload

An owner can add full-resolution phone photos to their car. When a photo does not save, the owner gets a clear message.

## User-Facing Changes

- Owners can add phone photos up to 20 MB each. The browser shrinks each photo before it saves. WebP photos are no longer accepted. (#2627)
- A car save with too many large photos now says the photos are too large, with the limit. (#2647)
- When a car save rejects photos, the message names each bad photo and gives the reason. (#1844)

## Admin-Facing Changes

- [Placeholder: add entries as issues complete.]

## Issues Resolved

- [#2627](https://github.com/elan-registry/registry/issues/2627) — Raise the car photo upload limit from 3 MB to at least 4.5 MB.
- [#2647](https://github.com/elan-registry/registry/issues/2647) — Tell the owner that the photos are too large when a car save exceeds the server limit.
- WIP: [#1844](https://github.com/elan-registry/registry/issues/1844) — Name each rejected photo and its reason in a multi-photo car save.
- WIP: [#2628](https://github.com/elan-registry/registry/issues/2628) — Correct the photo-load failure banner on car edit.
- WIP: [#2153](https://github.com/elan-registry/registry/issues/2153) — Send a verification email when an admin restores an archived account.
- WIP: [#2644](https://github.com/elan-registry/registry/issues/2644) — Add an admin Car Photos tab to reattach photos that are on disk but not on the car.
- WIP: [#1965](https://github.com/elan-registry/registry/issues/1965) — Pin GitHub Actions to commit SHAs and add a Dependabot cooldown.
