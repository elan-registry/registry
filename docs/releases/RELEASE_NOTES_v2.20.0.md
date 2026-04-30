# Elan Registry v2.20.0 Release Notes

**Release Date:** April 30, 2026
**Type:** Minor Release - Dialog & Notification Modernization

## Required Actions After Deployment

None.

## Admin-Facing Changes

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

- **`getBaseUrl()` fallback logging** ([#641](https://github.com/unibrain1/elanregistry/issues/641)):
  Warning logged when `getBaseUrl()` falls back to the hardcoded production URL on database
  failure, surfacing silent misconfiguration.
- **DataTables dependency update** ([#775](https://github.com/unibrain1/elanregistry/pull/775)):
  `datatables.net` and `datatables.net-bs4` bumped to latest versions.

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
