# Elan Registry v2.18.2 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release — Documentation Reorganization

## Required Actions After Deployment

1. Set `navigation_type = 0` in the `settings` table (switches to file-based navigation)
2. Run `FIX/21-Fix-Page-Permissions.php` on staging, review proposed changes, then execute
3. Verify `docs/.htaccess` redirects are active (`/docs/reference-library.php` → `/docs/reference/`)
4. Clear Cloudflare cache after deployment to ensure old doc URLs resolve via redirect

## User-Facing Changes

### Improvements

- **Documentation reorganized by intent**
  ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  Reference materials restructured from format-based (FAQ, Reference Library)
  into purpose-based sections — Workshop & Parts, Technical Articles, and Help
  & Guides — making content easier to find.
- **Simplified navigation**
  ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  Top-level nav reduced from 9 items to 6. Resources dropdown consolidates
  Technical Resources, Reference Library, and Help & Guides. Car Stories
  restored to top-level.
- **Paint Colour Codes page**
  ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  Web colour guide and downloadable PDF merged into a single page.
- **Chassis Validation**
  ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  Now directly accessible from the Resources dropdown (was buried inside
  Reference Library).

## Technical Changes

- **File-based navigation**
  ([#711](https://github.com/unibrain1/elanregistry/issues/711)):
  Completed `nav.php` with full menu structure; switched `navigation_type = 0`
  to eliminate DB query on every page load.
- **Remove dead `$email_act` query**
  ([#711](https://github.com/unibrain1/elanregistry/issues/711)):
  Removed unused email activation query from `navigation.php`.
- **Page permissions updated**
  ([#712](https://github.com/unibrain1/elanregistry/issues/712)):
  FIX/21 executed against new docs structure; admin pages private, public docs
  open.
- **Delete menu-sync.yml**
  ([#633](https://github.com/unibrain1/elanregistry/issues/633)):
  Removed non-functional GitHub Actions workflow with Semgrep shell injection
  findings.

## Issues Resolved

- [#559](https://github.com/unibrain1/elanregistry/issues/559) —
  Reorganize documentation by user intent instead of format
- [#633](https://github.com/unibrain1/elanregistry/issues/633) —
  Remove non-functional menu-sync.yml GitHub Actions workflow
- [#711](https://github.com/unibrain1/elanregistry/issues/711) —
  refactor: complete nav.php and switch navigation_type to file-based
- [#712](https://github.com/unibrain1/elanregistry/issues/712) —
  chore: run FIX/21 page permissions after documentation reorganization

## Summary

4 issues resolved across navigation refactoring and documentation
reorganization. The primary change is a restructured docs hierarchy that
organises content by user intent rather than format, paired with a simplified
navigation menu and a switch to file-based nav rendering.
