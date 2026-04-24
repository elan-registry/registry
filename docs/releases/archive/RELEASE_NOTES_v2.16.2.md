# Elan Registry v2.16.2 Release Notes

**Release Date:** 2026-03-10
**Type:** Patch Release - Bug fixes, security hardening, email fixes, and documentation

## REQUIRED ACTIONS AFTER DEPLOYMENT

Update UserSpice and its plugins via the UserSpice admin panel or by
uploading updated files:

1. Update **UserSpice** to **v6.05**
2. Update **Customizer** plugin to **v2.0.0**
3. Update **Hooker** plugin to **v2.1.0**
4. Update **reCAPTCHA** plugin to **v2.0.1**
5. Update **Brevo** plugin to **v1.4.0**

## User-Facing Changes

### Bug Fixes

- **Email Reply-To Name**: Corrected the reply-to display name on outbound
  emails — previously showed the sender's name; now correctly shows the
  reply-to address.

- **Email Template Variables**: Fixed custom email templates showing blank
  fields (name, account ID, comments) after UserSpice v6.05 update. Added
  variable whitelist override covering all custom email templates.

- **Feedback Email Reply-To**: Feedback emails now set the reply-to header
  to the sender's email address, so admins can reply directly to the person
  who submitted the feedback.

## Technical Changes

- **Security: Web-Exposed Directory Protection**: Added `.htaccess` deny-all
  to `database/` and `docs/development/` to block HTTP access to SQL schema
  files and developer docs. Tightened `scripts/.htaccess` to deny all access.

- **Brevo Plugin: Reply-To Options**: Added per-call `reply` and `reply_name`
  option support to `sendinblue()` for overriding the reply-to address at
  call time. Removed stray debug log statement from the email send path.

- **Email Variable Whitelist**: Added `$email_field_whitelist` global in
  `custom_functions.php` to override UserSpice v6.05's restrictive variable
  whitelist in `email_body()`. Covers feedback, contact, transfer, and admin
  email template variables.

- **Feedback Email: Direct Brevo Call**: Feedback form calls `sendinblue()`
  directly when available (test/prod) to correctly pass reply-to options,
  with PHPMailer fallback on dev.

- **Chore: Remove `bump-version.sh`**: Script deleted; `VERSION` is managed
  by the server post-receive hook. Tag creation documented as plain
  `git tag vX.Y.Z`.

- **Chore: Consolidate ADR location**: Moved `docs/adr/` to
  `docs/development/adr/` to consolidate all developer documentation.

- **Dependency: `minimatch` 3.1.2 → 3.1.5**: Security patch for Node
  dependency used in test tooling.

- **Docs: ADRs 001–013**: Added Architecture Decision Records documenting
  13 key technical decisions made during project development.

- **Chore: Claude Code Tooling**: Added `security-reviewer` agent and
  `/security-review` skill for OWASP security audits of changed files.
  Updated CLAUDE.md with agent/skill reference tables.

- **Chore: Remove Duplicate Apple Touch Icon**: Removed duplicate
  `apple-touch-icon.png` from working directory root and cleaned up
  directory clutter.

- **Docs: VERSION File Clarification**: Clarified that the `VERSION` file
  is managed exclusively by the server post-receive hook, not manually.

## Summary

Patch release with 3 bug fixes (email reply-to, template variables, feedback
reply-to), security hardening for web-exposed directories, a dependency
security patch, and documentation improvements including 13 Architecture
Decision Records.
