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
  `EmailTemplateTest.php` with 36 new test methods covering all migrated templates.

## Issues Resolved

- [#324](https://github.com/unibrain1/elanregistry/issues/324) — Migrate existing email functionality to centralized EmailTemplate system
- [#597](https://github.com/unibrain1/elanregistry/issues/597) — fix: improve email template HTML compatibility for Outlook and Gmail mobile

## Summary

2 issues resolved, fixing email rendering across major clients and migrating
all registry emails to a consistent centralized template system.
