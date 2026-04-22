# Elan Registry v2.17.3 Release Notes

**Release Date:** 2026-04-22
**Type:** Patch Release - Email System Hardening & Documentation

## Required Actions After Deployment

None

## User-Facing Changes

### Bug Fixes

- **Registration email logging** ([#639](https://github.com/unibrain1/elanregistry/issues/639)): Verification email delivery failures during registration are now detected and logged.
- **Admin contact error messages** ([#651](https://github.com/unibrain1/elanregistry/issues/651)): Error messages shown to admins no longer expose internal system details.
- **Car edit error messages** ([#658](https://github.com/unibrain1/elanregistry/issues/658)): Validation and operation errors in the car edit form now show safe user messages instead of raw exception details.
- **Admin car reassignment error messages** ([#659](https://github.com/unibrain1/elanregistry/issues/659)): Transfer failure errors in the admin panel no longer expose raw exception details to users.
- **Admin audit trail integrity** ([#669](https://github.com/unibrain1/elanregistry/issues/669)): Hardcoded user ID fallback removed from admin management page; invalid sessions now redirect to login and log a security event instead of proceeding with a fabricated user ID.
- **Email settings verification logging** ([#657](https://github.com/unibrain1/elanregistry/issues/657)): Failed verification email sends in user settings are now logged for administrator review.
- **Admin settings XSS protection** ([#677](https://github.com/unibrain1/elanregistry/issues/677)): CDN setting values in the admin settings tab are now HTML-escaped on output, preventing stored XSS from crafted setting values.
- **Admin settings error message safety** ([#678](https://github.com/unibrain1/elanregistry/issues/678)): Exception details no longer exposed in admin settings alerts; safe messages shown to admins with full details retained in system logs.
- **Admin contact email validation** ([#679](https://github.com/unibrain1/elanregistry/issues/679)): The `target_email` parameter in admin-to-owner contact is now validated for proper email format before use as a recipient address.

## Technical Changes

- **Transfer notification exception logging** ([#655](https://github.com/unibrain1/elanregistry/issues/655)): All four catch blocks in `transfer_email_notifications.php` now include exception class, file, and line number.
- **Transfer admin alert partial failure** ([#656](https://github.com/unibrain1/elanregistry/issues/656)): Partial delivery failures to admin recipients now logged under error category with sent/failed counts.
- **Modernize send-feedback.php** ([#600](https://github.com/unibrain1/elanregistry/issues/600)): Add PHPDoc to `cleanString()` documenting its defense-in-depth rationale; fix hardcoded logger user ID to use authenticated user; extend static scan tests to contact endpoint files.
- **Configurable admin email addresses** ([#368](https://github.com/unibrain1/elanregistry/issues/368)): Replace hardcoded `admin@elanregistry.org` in the verification email template with `getFeedbackEmail()`; add `elan_feedback_email` to settings auto-creation so fresh installs receive the correct default.
- **email_body() empty return guard** ([#650](https://github.com/unibrain1/elanregistry/issues/650)): Check `email_body()` return value before calling `email()` in `process-admin-contact.php` so template failures are distinguishable from Brevo delivery failures in logs.
- **Brevo email system documentation** ([#354](https://github.com/unibrain1/elanregistry/issues/354)): New `docs/development/EMAIL_SYSTEM.md` covering plugin setup, configuration, environment setup, and troubleshooting.

## Issues Resolved

- [#354](https://github.com/unibrain1/elanregistry/issues/354) — Document Brevo/Sendinblue configuration
- [#368](https://github.com/unibrain1/elanregistry/issues/368) — Use settings for admin email
- [#600](https://github.com/unibrain1/elanregistry/issues/600) — tech debt: modernize app/contact/send-feedback.php
- [#639](https://github.com/unibrain1/elanregistry/issues/639) — bug: registration verification email delivery failure is never detected or logged
- [#650](https://github.com/unibrain1/elanregistry/issues/650) — bug: email_body() empty return not checked before email() call in process-admin-contact.php
- [#651](https://github.com/unibrain1/elanregistry/issues/651) — bug: AdminContactException catch uses technical message in user-facing error
- [#655](https://github.com/unibrain1/elanregistry/issues/655) — bug: transfer_email_notifications.php catch(Exception) loses exception class and location in logs
- [#656](https://github.com/unibrain1/elanregistry/issues/656) — bug: sendTransferRequestAdminAlert() partial delivery failure logged under success category
- [#657](https://github.com/unibrain1/elanregistry/issues/657) — bug: user_settings.php verify-email failure has no logger() call
- [#658](https://github.com/unibrain1/elanregistry/issues/658) — bug: catch blocks in edit.php use getMessage() in user-facing errors
- [#659](https://github.com/unibrain1/elanregistry/issues/659) — bug: manage-consolidated.php catch(Exception) exposes getMessage() in user-facing error
- [#669](https://github.com/unibrain1/elanregistry/issues/669) — bug: hardcoded $currentUserId = 1 fallback corrupts audit trail in manage-consolidated.php
- [#677](https://github.com/unibrain1/elanregistry/issues/677) — bug: CDN setting values output without htmlspecialchars() in tab-settings.php (stored XSS)
- [#678](https://github.com/unibrain1/elanregistry/issues/678) — bug: catch blocks in tab-settings.php expose getMessage() in user-facing admin alerts
- [#679](https://github.com/unibrain1/elanregistry/issues/679) — bug: target_email POST parameter used as email recipient without format validation in process-admin-contact.php

## Summary

15 issues resolved across email system hardening (improved exception logging, failure detection, and configurable admin email addresses), user-facing error message safety (replacing raw exception messages with safe user messages), security fixes (stored XSS prevention, input validation), and new Brevo plugin documentation. #652 closed without code change — the affected catch block is in the Brevo plugin's own files (`usersc/plugins/sendinblue/`), which are third-party and not tracked in this repository.
