# Elan Registry v2.17.2 Release Notes

**Release Date:** TBD
**Type:** Patch Release - Email System Enhancements

## Required Actions After Deployment

Upgrade the Brevo plugin to v1.5 on each environment before or immediately
after deployment:

1. Download Brevo plugin v1.5 and replace `usersc/plugins/sendinblue/`
2. Verify `override.php` is present and correct
3. Test email delivery via the plugin admin config page

## User-Facing Changes

### Bug Fixes

- **Admin contact email delivery** ([#640](https://github.com/unibrain1/elanregistry/issues/640)):
  Admin quality-issue messages now correctly detect and report delivery failures.

## Technical Changes

- **Brevo plugin v1.5 upgrade**: Replaces plugin files with upstream v1.5, resolving
  the `override.php` signature mismatch that caused silent data loss.
- **override.php signature alignment** ([#601](https://github.com/unibrain1/elanregistry/issues/601)):
  Rewritten to match UserSpice's canonical `email()` signature with correct option
  key translation (`replyTo` → `reply`, etc.).
- **Failure detection fix** ([#601](https://github.com/unibrain1/elanregistry/issues/601)):
  `send-feedback.php` uses `!== true` so Brevo error strings are caught as failures.
- **`function_exists('sendinblue')` removed** ([#601](https://github.com/unibrain1/elanregistry/issues/601)):
  `send-feedback.php` calls `email()` directly, removing environment-specific branching.
- **process-admin-contact.php delivery check** ([#640](https://github.com/unibrain1/elanregistry/issues/640)):
  Changed `if ($result)` to `if ($result === true)` for correct Brevo error detection.

## Issues Resolved

- [#601](https://github.com/unibrain1/elanregistry/issues/601) —
  fix: align Brevo override.php with UserSpice email() signature
- [#640](https://github.com/unibrain1/elanregistry/issues/640) —
  bug: process-admin-contact.php delivery check unreliable with Brevo plugin

## Summary

2 issues resolved. Upgrades Brevo plugin to v1.5 and fixes the override.php signature
mismatch that caused silent email delivery failures, plus unreliable failure detection
in admin contact emails.
