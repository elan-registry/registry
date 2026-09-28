# Elan Registry v2.30.1.1 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Production Hotfix

This hotfix patches production for live defects without shipping v2.30.2 and
later. It is built from tag `v2.30.1`, the version now in production. After
release, merge the fixes forward into `main` and the open milestone branches.

## Required Actions After Deployment

[To be completed as issues are implemented. Expected: run
`21-Fix-Page-Permissions.php` only if a new page or admin script is added.]

## User-Facing Changes

### Improvements

[To be completed as issues are implemented.]

## Issues Resolved

- WIP: [#2144](https://github.com/elan-registry/registry/issues/2144) — security: require login for car history — history.php has no auth/CSRF/rate-limit, reverses #1305's "public by design"
- WIP: [#2227](https://github.com/elan-registry/registry/issues/2227) — fix: join-form failure beacon is refused 403 when the session's CSRF token turns over
