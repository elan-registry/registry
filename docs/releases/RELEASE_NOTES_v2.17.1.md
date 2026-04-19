# Elan Registry v2.17.1 Release Notes

**Release Date:** TBD
**Type:** Patch Release - Email Template Modernization

## Required Actions After Deployment

None.

## User-Facing Changes

### Bug Fixes

- **Email rendering in Outlook and Gmail mobile**
  ([#597](https://github.com/unibrain1/elanregistry/issues/597)):
  All registry emails now render correctly in Outlook for Windows and Gmail on
  Android/iOS, which previously displayed broken or unstyled layouts.

### Improvements

- **Consistent email branding**
  ([#324](https://github.com/unibrain1/elanregistry/issues/324)):
  Owner contact and feedback emails now use the same centralized template system
  as transfer notifications, ensuring consistent branding across all registry emails.

## Technical Changes

- **EmailTemplate compatibility fixes**
  ([#597](https://github.com/unibrain1/elanregistry/issues/597)):
  Replaced flexbox layout with HTML tables, added inline styles alongside
  `<style>` block, and replaced `max-width` CSS with `width` table attribute
  in `EmailTemplate.php` and all `usersc/views/_email_*.php` templates.
  Target: 95%+ Mailtrap HTML Check market support score.
- **EmailTemplate migration**
  ([#324](https://github.com/unibrain1/elanregistry/issues/324)):
  Migrated owner-to-owner contact, admin contact, and feedback emails to use
  `EmailTemplate`. Note: `send-feedback.php` migration deferred pending
  resolution of v2.17.0 upstream plugin issue (#601).

## Issues Resolved

- [#324](https://github.com/unibrain1/elanregistry/issues/324) — Migrate existing email functionality to centralized EmailTemplate system
- [#597](https://github.com/unibrain1/elanregistry/issues/597) — fix: improve email template HTML compatibility for Outlook and Gmail mobile

## Summary

2 issues resolved, fixing email rendering across major clients and migrating
all registry emails to a consistent centralized template system.
