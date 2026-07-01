# Elan Registry v2.25.3 Release Notes

**Release Date:** 2026-06-30
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

### Improvements

- **Contact form inline feedback** ([#1038](https://github.com/unibrain1/elanregistry/issues/1038)):
  Both the owner feedback form and the owner-contact form now display success/error
  messages inline without a full page reload. Submission errors surface immediately in
  the form instead of navigating away.

## Admin-Facing Changes

### Improvements

- **Admin page navigation** ([#1039](https://github.com/unibrain1/elanregistry/issues/1039)): Admin dashboard, maintenance, and design system pages renamed for clarity; design system page now linked from admin navigation.

## Technical Changes

- Inline `_edit_car_1.php`, `_edit_car_2.php`, `_edit_car_3.php` partial fragments into
  `app/cars/edit.php` to remove indirection that complicated maintenance (#1033)
- Move car page partials (hero actions, factory data card, vehicle info card) from
  `usersc/includes/partials/` into `app/views/cars/` to align with the `usersc/` =
  framework-only boundary (#1034)
- Move `transfer_email_notifications.php` from `usersc/includes/` into `app/` as a
  prerequisite for the service extraction (#1029)
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
  rewire `app/contact/form.php` (feedback) and `app/contact/owner.php` (owner contact) to
  submit via `ElanRegistryAPI` for inline success/error display with no page reload;
  sender identity read from trusted session, not POST hidden fields (#1038)
- Add `08-Rename-Admin-Pages.php` fix script to update UserSpice `pages` table URL
  registrations for the three renamed admin pages; run after deployment (#1039)
- Replace 3 deprecated `$.ajax()` calls in admin settings tab with `ElanRegistryAPI`;
  add a Pattern A endpoint (`app/api/admin/process-settings.php`) with explicit field allowlist and
  admin-only (level 2) auth guard; fixes security issues in upstream parser (#528)
- Update `PagePermissionClassifier` to move `design-system.php` from Admin-only to
  Editor-level; remove unnecessary `securePage()` from public `statistics.php` API
  endpoint; update fix script PHPDoc and on-screen alert to reflect current `app/api/`
  and renamed admin page structure (#1059)
- Migrate `$.ajax()` calls in admin owner management tab and remaining admin tabs to
  `ElanRegistryAPI` Pattern A client (#968)
- Migrate `manage-consolidated.php` car deletion path to `CarAdministrationService` for
  consistent service-layer ownership of admin-driven deletion (#932)
- Relocate `process-admin-settings.php` from `app/admin/includes/` to
  `app/api/admin/process-settings.php`, completing the `app/api/` consolidation (#1099)

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
- [#1099](https://github.com/unibrain1/elanregistry/issues/1099) — refactor: relocate process-admin-settings.php to app/api/admin/ to complete app/api/ consolidation
