# Elan Registry v2.24.1 Release Notes

**Release Date:** TBD
**Type:** Patch Release — Deployment Hotfixes and Guide Rendering Fix

## Required Actions After Deployment

1. **Register the Transfer FAQ page in the database** — via Admin → Page Manager,
   add a new page entry:

   | Field | Value |
   |---|---|
   | ID | 9017 |
   | Page | `docs/guides/car-transfer-faq.php` |
   | Private | 0 (public) |

2. **Remove the deleted `guide-viewer.php` entry** — in Page Manager, delete
   the entry for `docs/guide-viewer.php` (ID 9002) if it still exists.

## User-Facing Changes

### Improvements

- **Guide pages and privacy policy** ([#911](https://github.com/unibrain1/elanregistry/issues/911)):
  The Transfer FAQ guide and the privacy policy now render correctly after
  v2.24.0 broke them due to a missing server-side PHP dependency. Both are now
  self-contained static pages with no runtime dependencies.

## Admin-Facing Changes

### Improvements

- **Email styling reference** ([#911](https://github.com/unibrain1/elanregistry/issues/911)):
  Email color token → hex mapping and template structure moved to Color Preview
  (Admin → Color Preview → section 13) with corrected current brand colors.

## Issues Resolved

- [#911](https://github.com/unibrain1/elanregistry/issues/911) — Convert markdown guides and privacy policy to static HTML
