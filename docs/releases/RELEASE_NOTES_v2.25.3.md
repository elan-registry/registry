# Elan Registry v2.25.3 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - App/ Directory Reorganization — Phase 1

## Required Actions After Deployment

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
