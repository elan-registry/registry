# Elan Registry v2.30.5 Release Notes

**Release Date:** TBD
**Type:** Patch Release - Email Delivery Visibility

## User-Facing Changes

- An owner whose car emails are suppressed or bounced now sees a dismissable
  notice on Account Settings naming the affected addresses, the reason, and
  links to fix it. ([#1899](https://github.com/elan-registry/registry/issues/1899))

## Issues Resolved

- [#1899](https://github.com/elan-registry/registry/issues/1899) — Added an
  email-paused notice to account.php for owners with suppressed or bounced car
  emails. The same change fixes the Uncloak button on account.php, which
  always failed its CSRF check. It also drops the unused `country` table. Run
  `composer migrate` on deploy.
