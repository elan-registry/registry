# Elan Registry v2.25.3 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - App/ Directory Reorganization — Phase 1

## Required Actions After Deployment

Run the Rename Admin Pages fix script first to update the UserSpice pages table:

```
https://elanregistry.org/app/admin/scripts/fix/08-Rename-Admin-Pages.php
```

Run the Fix Page Permissions maintenance script after deployment to reclassify
any page permission rows that reference the new `app/api/` and renamed admin
paths:

```
https://elanregistry.org/app/admin/scripts/maintenance/21-Fix-Page-Permissions.php
```

## User-Facing Changes

No user-facing changes in this release. All restructuring is internal with no
public URL changes.

## Admin-Facing Changes

### Improvements

- **Admin page navigation** ([#1039](https://github.com/unibrain1/elanregistry/issues/1039)): Admin dashboard, maintenance, and design system pages renamed for clarity; design system page now linked from admin navigation.

## Technical Changes

- Extract transfer email notifications into `TransferEmailService` class with injectable DB and mailer
  dependencies, enabling unit testing without a live database or email server (#1030)
- Migrate 7 app-domain email templates from `usersc/views/` to `app/views/email/`, completing the
  `usersc/` boundary (framework customization only); two templates renamed for clarity (#1035)
- Split `app/action/getDataTables.php` into three dedicated endpoints under `app/api/cars/`:
  `list.php` (cars DataTable), `factory-list.php` (factory DataTable), `chassis-lookup.php`
  (chassis-to-car-ID lookup); adds `JSON_THROW_ON_ERROR` to all JSON encoding (#1036)
- Move 9 remaining AJAX endpoints from `app/cars/actions/`, `app/action/`, and `app/reports/api/`
  into `app/api/cars/` and `app/api/shared/` with resource-named filenames; delete emptied
  source directories; upgrade transfer-request security token to `random_bytes(32)` (#1037)
- Convert `app/contact/send-feedback.php` and `app/contact/send-owner-email.php` from
  full-page HTML redirect handlers to Pattern A JSON endpoints under `app/api/contact/`;
  wire both contact forms to submit via `ElanRegistryAPI` for inline success/error display
  with no page reload; sender identity read from trusted session, not POST hidden fields (#1038)
- Add `08-Rename-Admin-Pages.php` fix script to update UserSpice `pages` table URL
  registrations for the three renamed admin pages; run after deployment (#1039)
- Replace 3 deprecated `$.ajax()` calls in admin settings tab with `ElanRegistryAPI`;
  add `process-admin-settings.php` Pattern A endpoint with explicit field allowlist and
  admin-only (level 2) auth guard; fixes security issues in upstream parser (#528)
- Update `PagePermissionClassifier` to move `design-system.php` from Admin-only to
  Editor-level; remove unnecessary `securePage()` from public `statistics.php` API
  endpoint; update fix script PHPDoc and on-screen alert to reflect current `app/api/`
  and renamed admin page structure (#1059)

## Issues Resolved

- [#528](https://github.com/unibrain1/elanregistry/issues/528) — Migrate remaining admin settings $.ajax() calls to ElanRegistryAPI
- [#932](https://github.com/unibrain1/elanregistry/issues/932) — refactor: migrate manage-consolidated.php car deletion path to CarAdministrationService
- [#968](https://github.com/unibrain1/elanregistry/issues/968) — refactor: migrate remaining $.ajax() calls to ElanRegistryAPI
- [#1029](https://github.com/unibrain1/elanregistry/issues/1029) — Move transfer email notifications out of usersc/ into app/
- [#1030](https://github.com/unibrain1/elanregistry/issues/1030) — Extract transfer email functions into TransferEmailService class
- [#1033](https://github.com/unibrain1/elanregistry/issues/1033) — refactor: inline _edit_car partial fragments into edit.php
- [#1034](https://github.com/unibrain1/elanregistry/issues/1034) — refactor: move car page partials from usersc/includes/partials/ to app/views/cars/
- [#1035](https://github.com/unibrain1/elanregistry/issues/1035) — refactor: migrate app-domain email templates from usersc/views/ to app/views/email/
- [#1036](https://github.com/unibrain1/elanregistry/issues/1036) — refactor: split getDataTables.php into three dedicated API endpoints
- [#1037](https://github.com/unibrain1/elanregistry/issues/1037) — refactor: move action endpoints to app/api/ with resource-named filenames
- [#1038](https://github.com/unibrain1/elanregistry/issues/1038) — refactor: rewrite contact form handlers as JSON API endpoints
- [#1039](https://github.com/unibrain1/elanregistry/issues/1039) — refactor: rename admin pages and update UserSpice page registrations
- [#1059](https://github.com/unibrain1/elanregistry/issues/1059) — refactor: update 21-Fix-Page-Permissions to handle app/api/ and renamed admin pages
