# Elan Registry v2.31.1 Release Notes

**Release Date:** November 3, 2026
**Type:** Patch Release - Verification Tab Queue and Email Delivery Tab

The Verification tab shows the real send queue and reports unreadable settings. A new admin Email Delivery tab shows the outcome of each mail.

## User-Facing Changes

- A save with a new photo upload keeps older photos and asks owners to rename unmatched files. ([#2609](https://github.com/elan-registry/registry/issues/2609))

## Admin-Facing Changes

- Verification tab times are correct from November to March, but some times recorded before this release stay 1 hour off. ([#2589](https://github.com/elan-registry/registry/issues/2589))
- Unreadable verification settings show as unavailable on the Verification tab and admin banner, and auto-send sends nothing.
  ([#2572](https://github.com/elan-registry/registry/issues/2572),
  [#2587](https://github.com/elan-registry/registry/issues/2587),
  [#2573](https://github.com/elan-registry/registry/issues/2573))
- Eligible and Next Queue show cars in send order with car and owner links, and the All pill is gone. ([#2599](https://github.com/elan-registry/registry/issues/2599))
- Verification cards, chips, and tooltips use clearer labels and causes, and help buttons are easier to tap. ([#2458](https://github.com/elan-registry/registry/issues/2458))
- Recent Activity is a sortable table, newest first, with links to the car, owner, and user. ([#2496](https://github.com/elan-registry/registry/issues/2496))
- A new admin-only Email Delivery tab shows each sent mail and its outcome. ([#2102](https://github.com/elan-registry/registry/issues/2102))
- The Email Delivery tab shows outcomes for mail to addresses that no single owner holds. ([#2611](https://github.com/elan-registry/registry/issues/2611))

## Issues Resolved

- [#2589](https://github.com/elan-registry/registry/issues/2589) — PHP and MySQL use the same time zone all year.
- [#2572](https://github.com/elan-registry/registry/issues/2572) — The Save form no longer overwrites the batch size with 5 when the settings read fails.
- [#2587](https://github.com/elan-registry/registry/issues/2587) — The auto-send job sends no emails when the batch-size read fails.
- [#2573](https://github.com/elan-registry/registry/issues/2573) — The status panel gives a reason and a next step for an unreadable or missing job state.
- [#2599](https://github.com/elan-registry/registry/issues/2599) — Eligible and Next Queue list cars in send order with links to the car and owner.
- [#2458](https://github.com/elan-registry/registry/issues/2458) — Verification tab labels, chip text, tooltips, and accessibility follow-ups.
- [#2496](https://github.com/elan-registry/registry/issues/2496) — Recent Activity shows as a sortable table with car, owner, and user links.
- [#2102](https://github.com/elan-registry/registry/issues/2102) — Admins can see which mail Brevo delivered, bounced, or held with no outcome.
- [#2611](https://github.com/elan-registry/registry/issues/2611) — Brevo events for mail that no single owner holds are stored and shown.
- [#2609](https://github.com/elan-registry/registry/issues/2609) — A save with a new upload keeps older photos with legacy file names.
