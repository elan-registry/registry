# Elan Registry v2.20.0 Release Notes

**Release Date:** April 30, 2026
**Type:** Minor Release - Dialog & Notification Modernization

## Required Actions After Deployment

None.

## Admin-Facing Changes

### Bug Fixes

- **Fix Page Permissions: user_settings.php access restored** ([#795](https://github.com/unibrain1/elanregistry/issues/795)):
  `usersc/user_settings.php` was incorrectly classified as a special-case private page with no
  permissions, making it inaccessible to all roles. It is now correctly classified as a
  private owner page (requires User permission), consistent with all other `usersc/*` pages.
- **Fix Page Permissions: maintenance portal restricted to Administrators** ([#797](https://github.com/unibrain1/elanregistry/issues/797)):
  The Fix Page Permissions maintenance script now distinguishes between two admin permission
  tiers. Maintenance portal pages (`manage-maintenance.php`, scripts under
  `app/admin/scripts/`) are set to Administrator-only access. The main admin panel
  (`manage-consolidated.php` and its tabs) retains Administrator + Editor access.
- **Fix stored XSS in admin panel error messages** ([#789](https://github.com/unibrain1/elanregistry/issues/789)):
  The `showMessage()` function in the consolidated admin panel now escapes its message
  parameter before rendering as HTML, preventing stored XSS via server-supplied error
  messages that could contain crafted content from car or owner records.
- **Fix silent cache I/O failures in LocationService** ([#798](https://github.com/unibrain1/elanregistry/issues/798)):
  Cache write, directory-creation, and expired-file-deletion failures in `LocationService`
  were suppressed with `@` operators and never logged. All three paths now check return values
  and log failures via `logger()` with `LOG_CATEGORY_FILE_ERROR` so operational errors are
  visible in the audit trail.

### Improvements

- **Styled Confirmation Dialogs** ([#571](https://github.com/unibrain1/elanregistry/issues/571)):
  Native browser `confirm()` dialogs replaced with Bootstrap 5 modals for destructive admin actions.
- **Inline Validation Feedback** ([#572](https://github.com/unibrain1/elanregistry/issues/572)):
  Admin validation messages now use styled notifications instead of blocking `alert()` dialogs.
- **Consistent Error and Status Messaging** ([#573](https://github.com/unibrain1/elanregistry/issues/573)):
  Remaining native browser dialogs replaced with the app notification system.
- **Admin Scripts Hierarchy** ([#595](https://github.com/unibrain1/elanregistry/issues/595)):
  FIX scripts promoted to a structured two-directory hierarchy under
  `app/admin/scripts/fix/` (one-time migrations) and `app/admin/scripts/maintenance/`
  (repeatable tasks), with a new admin maintenance UI.
- **Role-Based Admin Page Permissions** ([#610](https://github.com/unibrain1/elanregistry/issues/610)):
  Admin panel endpoints now enforce consistent role-based access checks.
- **Backup Path Boundary Guard** ([#634](https://github.com/unibrain1/elanregistry/issues/634)):
  `realpath()` validation added to all `unlink()` calls in backup operations to
  prevent path traversal.
- **Backup health score caveat** ([#785](https://github.com/unibrain1/elanregistry/issues/785)):
  "Estimated" badge shown next to backup health score when enhanced stats are unavailable.
- **Removed unimplemented Owner Cleanup tab** ([#780](https://github.com/unibrain1/elanregistry/issues/780)):
  The Owner Cleanup tab has been removed from the Registry Management admin panel as it was
  never fully implemented.
- **Removed stub buttons from owner sidebar**
  ([#786](https://github.com/unibrain1/elanregistry/issues/786),
  [#787](https://github.com/unibrain1/elanregistry/issues/787),
  [#788](https://github.com/unibrain1/elanregistry/issues/788)):
  The unimplemented 'View All Cars', 'View Full History', and 'Send Email' buttons have been
  removed from the owner sidebar.

## Technical Changes

- **Extract duplicated script-enumeration logic into shared helper** ([#801](https://github.com/unibrain1/elanregistry/issues/801)):
  Identical 21-line directory-scan blocks in `tab-health.php` and `tab-maintenance.php` replaced
  with calls to a new `enumerateScriptFiles(string $directory): array` helper in
  `app/admin/includes/system/script-enumeration.php`. Also eliminates a second near-duplicate
  block in `tab-maintenance.php` for the maintenance scripts directory.

- **Test coverage for security-critical code paths** ([#800](https://github.com/unibrain1/elanregistry/issues/800)):
  Added automated tests for three previously uncovered paths identified in the PR #794 deep review:
  `escapeHtml()` now has a Playwright test with seven XSS vectors (`<script>`, `"`, `'`, `&`, `>`,
  combined attribute injection, and non-string passthrough); `BackupManager::cleanupOldBackups()`
  now has PHPUnit tests for the `realpath()` path-traversal guard (symlink traversal blocked, valid
  old file deleted); and `getBaseUrl()` now has integration tests for the server-globals-absent
  fallback path.

## Issues Resolved

- [#571](https://github.com/unibrain1/elanregistry/issues/571) — Convert native confirm() dialogs to app modal for destructive actions
- [#572](https://github.com/unibrain1/elanregistry/issues/572) — Convert validation alert() dialogs to app notification system
- [#573](https://github.com/unibrain1/elanregistry/issues/573) — Convert remaining native dialogs and improve error messaging
- [#595](https://github.com/unibrain1/elanregistry/issues/595) — Promote active FIX scripts to admin management tools
- [#610](https://github.com/unibrain1/elanregistry/issues/610) — security: audit admin endpoint permission checks for consistency
- [#634](https://github.com/unibrain1/elanregistry/issues/634) — Add path boundary guard to unlink() calls in backup operations
- [#641](https://github.com/unibrain1/elanregistry/issues/641) — improvement: getBaseUrl() silently falls back to production URL on database failure without logging
- [#775](https://github.com/unibrain1/elanregistry/pull/775) — chore(deps): bump datatables.net and datatables.net-bs4
- [#780](https://github.com/unibrain1/elanregistry/issues/780) — tech-debt: remove unimplemented Owner Cleanup tab from admin panel
- [#785](https://github.com/unibrain1/elanregistry/issues/785) — bug(admin): fabricated backup health score shown without caveat when enhanced stats fail
- [#786](https://github.com/unibrain1/elanregistry/issues/786) — Remove stub 'View All Cars' button from owner sidebar
- [#787](https://github.com/unibrain1/elanregistry/issues/787) — Remove stub 'View Full History' button from owner sidebar
- [#788](https://github.com/unibrain1/elanregistry/issues/788) — Remove stub 'Send Email' button from owner sidebar
- [#795](https://github.com/unibrain1/elanregistry/issues/795) — bug: usersc/user_settings.php incorrectly classified as special-case (no permissions)
- [#797](https://github.com/unibrain1/elanregistry/issues/797) — bug: maintenance portal pages not restricted to Administrator-only access
- [#789](https://github.com/unibrain1/elanregistry/issues/789) — Fix stored XSS: escape HTML in admin panel dynamic content
- [#798](https://github.com/unibrain1/elanregistry/issues/798) — tech-debt: silent failures in LocationService and BackupManager swallow errors without logging
- [#800](https://github.com/unibrain1/elanregistry/issues/800) — tech-debt: add missing test coverage for escapeHtml(),
  BackupManager path-traversal guard, and getBaseUrl() fallback
- [#801](https://github.com/unibrain1/elanregistry/issues/801) — tech-debt: extract duplicated script-enumeration logic
  from tab-health.php and tab-maintenance.php
