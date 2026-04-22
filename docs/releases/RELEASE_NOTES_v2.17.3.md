# Elan Registry v2.17.3 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Email System Hardening & Documentation

## Required Actions After Deployment

None

## User-Facing Changes

### Bug Fixes

- **Registration email logging** ([#639](https://github.com/unibrain1/elanregistry/issues/639)): Verification email delivery failures during registration are now detected and logged.
- **Admin contact error messages** ([#651](https://github.com/unibrain1/elanregistry/issues/651)): Error messages shown to admins no longer expose internal system details.
- **Car edit error messages** ([#658](https://github.com/unibrain1/elanregistry/issues/658)): Validation and operation errors in the car edit form now show safe user messages instead of raw exception details.
- **Email settings verification logging** ([#657](https://github.com/unibrain1/elanregistry/issues/657)): Failed verification email sends in user settings are now logged for administrator review.

## Technical Changes

- **Transfer notification exception logging** ([#655](https://github.com/unibrain1/elanregistry/issues/655)): All four catch blocks in `transfer_email_notifications.php` now include exception class, file, and line number.
- **Transfer admin alert partial failure** ([#656](https://github.com/unibrain1/elanregistry/issues/656)): Partial delivery failures to admin recipients now logged under error category with sent/failed counts.
- **Modernize send-feedback.php** ([#600](https://github.com/unibrain1/elanregistry/issues/600)): Add type hints and PHPDoc; move functions to file scope; fix unconditional success log on failure path.
- **Configurable admin email addresses** ([#368](https://github.com/unibrain1/elanregistry/issues/368)): Replace hardcoded admin email address in feedback form and other locations with `$settings->elan_admin_emails`.
- **email_body() empty return guard** ([#650](https://github.com/unibrain1/elanregistry/issues/650)): Check `email_body()` return value before calling `email()` in `process-admin-contact.php`; restore template-not-found logging in `helpers.php`.
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

## Summary

10 issues resolved across email system hardening (improved exception logging, failure detection, and configurable admin email addresses), user-facing error message safety (replacing raw exception messages with safe user messages), and new Brevo plugin documentation. #652 closed without code change — the affected catch block is in the Brevo plugin's own files (`usersc/plugins/sendinblue/`), which are third-party and not tracked in this repository.
