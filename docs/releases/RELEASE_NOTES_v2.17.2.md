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
- **`registrySendEmail()` removed** ([#601](https://github.com/unibrain1/elanregistry/issues/601)):
  The workaround wrapper that bypassed `override.php` is deleted. All callers now use
  `email()` directly with UserSpice canonical option keys (`replyTo`, etc.).
- **Strict failure detection** ([#601](https://github.com/unibrain1/elanregistry/issues/601)):
  `send-feedback.php`, `user_settings.php`, and `transfer_email_notifications.php`
  updated to use strict `!== true` / `=== true` checks (consistent with the rest of
  the codebase).
- **process-admin-contact.php delivery check** ([#640](https://github.com/unibrain1/elanregistry/issues/640)):
  Changed `if ($result)` to `if ($result !== true)` for correct failure detection.

## Issues Resolved

- [#601](https://github.com/unibrain1/elanregistry/issues/601) —
  fix: align Brevo override.php with UserSpice email() signature
- [#640](https://github.com/unibrain1/elanregistry/issues/640) —
  bug: process-admin-contact.php delivery check unreliable with Brevo plugin

## Summary

2 issues resolved. Upgrades Brevo plugin to v1.5, removes the `registrySendEmail()`
workaround, standardizes all email() callers to use strict result checks, and fixes
unreliable failure detection in admin contact emails.
