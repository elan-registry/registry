# Elan Registry v2.19.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - Template Modernization & Bootstrap 5

## Required Actions After Deployment

1. Run the FIX migration script to remove `elan_*_cdn` settings from the database:

   ```bash
   database/fixes/FIX-405-remove-elan-cdn-settings.sql
   ```

2. Verify self-hosted library files are served correctly from `usersc/js/` and `usersc/css/`.

3. Set the active template to `customizer` in the UserSpice admin panel (Settings → Template),
   or run: `UPDATE settings SET value='customizer' WHERE name='template';`

## User-Facing Changes

### Improvements

- **Bootstrap 5 Template** ([#618](https://github.com/unibrain1/elanregistry/issues/618)):
  Updated site template to Bootstrap 5.3 — faster load times, improved accessibility, and modern UI components.
- **Mobile Responsiveness** ([#620](https://github.com/unibrain1/elanregistry/issues/620)):
  All 18 app pages audited and fixed for 375px mobile viewports — no more horizontal scrolling on phones.

## Technical Changes

- **Self-hosted Frontend Libraries** ([#405](https://github.com/unibrain1/elanregistry/issues/405)):
  Replaced database-driven CDN configuration with source-controlled, versioned local files.
  Enables Dependabot security alerts for JS/CSS dependencies.
- **BS4 → BS5 Class Migration** ([#619](https://github.com/unibrain1/elanregistry/issues/619)):
  Migrated all Bootstrap 4 classes and data attributes across 35 app files to Bootstrap 5 equivalents.

## Issues Resolved

- [#405](https://github.com/unibrain1/elanregistry/issues/405) — Replace DB-driven CDN config with source-controlled self-hosted libraries
- [#618](https://github.com/unibrain1/elanregistry/issues/618) — Rebase ElanRegistry template on Bootstrap 5.3 (Customizer pattern)
- [#619](https://github.com/unibrain1/elanregistry/issues/619) — Migrate Bootstrap 4 classes and data attributes across all app pages
- [#620](https://github.com/unibrain1/elanregistry/issues/620) — Mobile responsiveness audit and remediation — all app pages

## Summary

4 issues resolved across the full Bootstrap 5 migration: self-hosted libraries, template rebase, class migration, and mobile responsiveness audit.
