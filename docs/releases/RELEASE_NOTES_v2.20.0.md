# Elan Registry v2.20.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - Dialog & Notification Modernization

## Required Actions After Deployment

None.

## User-Facing Changes

### Improvements

- **Styled Confirmation Dialogs** ([#571](https://github.com/unibrain1/elanregistry/issues/571)):
  Destructive admin actions (car merge, FIX scripts, schema maintenance) now use consistent
  Bootstrap 5 confirmation modals instead of native browser confirm() dialogs.
- **Inline Validation Feedback** ([#572](https://github.com/unibrain1/elanregistry/issues/572)):
  Admin form validation messages (car merge, API key, email settings, owner management) now
  display as styled notifications instead of blocking browser alerts.
- **Consistent Error and Status Messaging** ([#573](https://github.com/unibrain1/elanregistry/issues/573)):
  Remaining native browser dialogs replaced with application notification system; feature stubs
  and error messages use consistent UI patterns.

## Technical Changes

- **Path boundary guard on unlink() calls** ([#634](https://github.com/unibrain1/elanregistry/issues/634)):
  Added `realpath()` verification before all `unlink()` calls in backup and cache operations
  to ensure deleted files are confirmed within their intended directory, resolving Semgrep
  `php.lang.security.unlink-use.unlink-use` findings.

- **confirm() → #confirmationModal** ([#571](https://github.com/unibrain1/elanregistry/issues/571)):
  Three confirm() calls in tab-manage_cars.php and tab-system.php converted to the existing
  confirmationModal pattern.
- **alert() → showNotification()** ([#572](https://github.com/unibrain1/elanregistry/issues/572)):
  Six alert() calls in admin tabs converted to showNotification() from manage-consolidated.js;
  showNotification() hardened against XSS by escaping message before HTML interpolation.
- **Remaining dialog cleanup** ([#573](https://github.com/unibrain1/elanregistry/issues/573)):
  Feature stub alerts, error alert, and backup prompt() evaluated and standardized.
- **Dependency update: datatables.net 1.10.23 → 1.13.11**
  ([#775](https://github.com/unibrain1/elanregistry/pull/775)):
  Bumps datatables.net and datatables.net-bs4 to 1.13.11 (security fix).

## Issues Resolved

- [#571](https://github.com/unibrain1/elanregistry/issues/571) —
  Convert native confirm() dialogs to app modal for destructive actions
- [#572](https://github.com/unibrain1/elanregistry/issues/572) —
  Convert validation alert() dialogs to app notification system
- [#573](https://github.com/unibrain1/elanregistry/issues/573) —
  Convert remaining native dialogs and improve error messaging
- [#634](https://github.com/unibrain1/elanregistry/issues/634) —
  Add path boundary guard to unlink() calls in backup operations
- [#775](https://github.com/unibrain1/elanregistry/pull/775) —
  chore(deps): bump datatables.net and datatables.net-bs4

## Summary

4 issues and 1 dependency update resolved; replaces all native browser dialogs across the
admin interface with Bootstrap 5 modals and the app notification system, updates
datatables.net to 1.13.11, and hardens file deletion with path boundary guards.
