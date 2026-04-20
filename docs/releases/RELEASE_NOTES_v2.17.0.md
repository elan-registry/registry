# Elan Registry v2.17.0 Release Notes

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
  All registry emails — owner-to-owner contact, admin contact, feedback, and
  account verification — now use the same centralized template system as transfer
  notifications, with consistent registry branding and responsive design.
- **Reply-to on owner contact emails**
  ([#324](https://github.com/unibrain1/elanregistry/issues/324)):
  Owners can now reply directly to contact messages in their email client instead
  of copying and pasting the sender's address.

## Technical Changes

- **Semgrep security scanning tooling**:
  Added `scripts/semgrep-dump.sh` to fetch open findings from Semgrep Cloud
  via the Web API (requires 1Password CLI). Added `.semgrepignore` to exclude
  `users/` (UserSpice core), `FIX/`, `docs/stories/`, `vendor/`, `node_modules/`,
  and test fixtures from scans. Triaged and marked 44 existing false positives in
  the Semgrep dashboard. Created issues #633, #634, #635 for confirmed real
  findings. Documented the triage workflow in `QUICK_REFERENCE.md`.
- **FIX/_ARCHIVE cleanup**:
  Deleted 22 completed one-time migration scripts from `FIX/_ARCHIVE/`. Added
  `FIX/_ARCHIVE/README.md` with a full inventory of removed scripts and git
  recovery instructions.
- **EmailTemplate compatibility fixes**
  ([#597](https://github.com/unibrain1/elanregistry/issues/597)):
  Replaced flexbox layout with HTML tables, added inline styles alongside
  `<style>` block, and replaced `max-width` CSS with `width` table attribute
  in `EmailTemplate.php`. Standalone `_email_*.php` views deferred to #324.
  Target: 95%+ Mailtrap HTML Check market support score.
- **EmailTemplate migration complete**
  ([#324](https://github.com/unibrain1/elanregistry/issues/324)):
  Migrated all remaining email templates to the `EmailTemplate` class:
  `_email_contact_owner.php`, `_email_feedback.php`, `_email_admin_contact_owner.php`.
  Created `usersc/views/` override files for UserSpice registration and email-change
  verification emails (`_email_template_verify.php`, `_email_template_verify_new.php`)
  — no UserSpice core files modified. Added `filter_var` guard on reply-to header
  and `htmlspecialchars` escaping of footer text in `EmailTemplate`. Extended
  `EmailTemplateTest.php` with 49 new test methods covering all migrated templates,
  including all four transfer email templates and security regression tests.
- **Security hardening in email send path**:
  Fixed broken reply-to header in `send-owner-email.php` via Brevo plugin workaround
  (upstream signature bug documented in `docs/bugs/`). Added delivery failure logging
  when `sendinblue()` returns an error string. Added `str_starts_with` URL validation
  and scheme-rejection guard for email verification links. Escaped `$baseUrl` and
  `$logoUrl` in `EmailTemplate` footer/header. Added `(string)` casts on integer IDs
  passed to `createDetailRow()` under `strict_types=1`, and `?: time()` fallbacks on
  all `strtotime()` calls in transfer templates.
- **`registrySendEmail()` helper**
  ([#638](https://github.com/unibrain1/elanregistry/issues/638)):
  Added `registrySendEmail()` to `custom_functions.php` to set the To: display name
  on both email transport paths. Brevo path calls `sendinblue()` directly with the
  recipient name as the 4th argument; PHPMailer/SMTP path constructs the message
  directly with `addAddress($to, $toName)`. This eliminates the
  `TO_NO_BRKTS_HTML_ONLY` SpamAssassin rule (score 0.6) and fixes broken delivery
  failure detection in `send-feedback.php` where `!$email_sent` never triggered for
  Brevo errors (`$email_sent !== true` is now used instead). A TODO note referencing
  issue #601 documents what to revisit when the upstream Brevo plugin signature bug
  is resolved.
- **Broken button URLs in transfer emails**:
  Fixed `details.php?car_id=` and `edit.php?car_id=` URLs in
  `_email_transfer_request.php` and `_email_admin_contact_owner.php` that previously
  used wrong parameter names (`detail.php?id=` and `edit.php?id=`).
- **"Message Sent" page conditional on delivery success** in `send-owner-email.php`:
  The success confirmation is now shown only when email delivery succeeds. On
  failure, an error message is displayed and the failure is logged under
  `LOG_CATEGORY_EMAIL_ERROR`.
- **Open-redirect guard now logs security events** in `_email_template_verify_new.php`:
  Added a `logger()` call under `LOG_CATEGORY_SECURITY` when the scheme-rejection
  guard triggers, making security events visible in the admin log.

## Issues Resolved

- [#324](https://github.com/unibrain1/elanregistry/issues/324) — Migrate existing email functionality to centralized EmailTemplate system
- [#597](https://github.com/unibrain1/elanregistry/issues/597) — fix: improve email template HTML compatibility for Outlook and Gmail mobile
- [#638](https://github.com/unibrain1/elanregistry/issues/638) — send-feedback.php delivery failure check dead code

## Summary

3 issues resolved, fixing email rendering across major clients, migrating all
registry emails to a consistent centralized template system, and hardening the
email send path with correct failure detection and spam score reduction.
