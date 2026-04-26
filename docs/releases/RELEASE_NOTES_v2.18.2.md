# Elan Registry v2.18.2 Release Notes

**Release Date:** 2026-04-26
**Type:** Minor Release — Documentation Reorganization

## Required Actions After Deployment

1. **Run SQL patch** to register the renamed viewer pages and remove stale entries:

   ```sql
   -- #712: remove stale pages entries, register renamed viewers
   DELETE FROM pages WHERE page IN (
       'docs/embed.php',
       'docs/view.php',
       'docs/reference-library.php'
   );

   INSERT IGNORE INTO pages (page, title, private, re_auth, core) VALUES
       ('docs/pdf-viewer.php',   NULL, 0, 0, 0),
       ('docs/guide-viewer.php', NULL, 0, 0, 0);
   ```

2. **Run `FIX/21-Fix-Page-Permissions.php`** — analyze proposed changes, review, then execute.
   Confirm `docs/reference/identification-guide.php` appears in the "set public" category
   (live DB has it incorrectly private; FIX/21 will correct this).

3. **Verify page privacy** after FIX/21 runs:

   ```sql
   SELECT page, private FROM pages WHERE page LIKE 'docs/%' ORDER BY page;
   ```

   Expected: all `docs/*` pages `private=0` except `docs/admin/index.php` (`private=1`).

4. Set `navigation_type = 0` in the `settings` table (switches to file-based navigation).

5. Verify `docs/.htaccess` redirects are active (`/docs/reference-library.php` → `/docs/reference/`).

6. Clear Cloudflare cache after deployment to ensure old doc URLs resolve via redirect.

## User-Facing Changes

### Improvements

- **Documentation reorganized by intent**
  ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  Reference materials restructured from format-based (FAQ, Reference Library)
  into purpose-based sections. New structure: "Owner Guides" (`docs/guides/`) for
  how-to documentation and "Technical Reference" (`docs/reference/`) for car
  knowledge and specifications. Technical Reference includes Workshop & Parts
  (workshop manuals, parts lists, engine types) and Technical Articles
  (gearknobs, steering wheels, engine types, super safety, serial numbers).
- **Navigation redesigned**
  ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  Top-level nav reduced from 9 items to 6. "Technical Resources" dropdown
  replaced by "Reference" dropdown; Car Stories promoted to top-level nav item;
  "Feedback" moved into Account dropdown; "Home" link removed (logo links to
  home). "Factory Data" renamed to "Production Records".
- **Documentation hub updated**
  ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  `docs/index.php` redesigned with 3-card layout: Technical Reference, Car
  Stories, and Owner Guides — making documentation structure immediately clear
  to users.
- **Paint Colour Codes page**
  ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  Web colour guide and downloadable PDF merged into a single page with prominent
  PDF download card for official factory paint codes.
- **Technical Articles accessible via navigation**
  ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  New dedicated Technical Reference section in main navigation with easy access
  to gearknobs, steering wheels, engine types, super safety, and serial numbers
  articles.

## Technical Changes

- **PDF assets co-located with their sections**
  ([#715](https://github.com/unibrain1/elanregistry/issues/715)):
  PDFs and companion files moved from flat `docs/assets/` into
  `docs/reference/assets/` (workshop manuals, technical articles, paint codes)
  and `docs/stories/assets/` (car stories). `docs/pdf-viewer.php` updated with an
  allowlisted `subdir` parameter so the viewer resolves the correct path.
  Old direct-download URLs redirect automatically via `docs/.htaccess`.
  ADR-013 (database proxy approach) superseded — file-system co-location is the
  final state.
- **Documentation directory reorganization**
  ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  `docs/faq/` directory split into `docs/guides/` (owner guides), `docs/reference/`
  (technical content), and `docs/admin/` (admin documentation). Image assets moved
  from `docs/faq/screenshots/` to `docs/reference/images/`. Old URLs redirect
  automatically via `.htaccess`.
- **DocumentConfig class updated**
  ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  `usersc/classes/DocumentConfig.php` updated with new `guides`, `reference`,
  and `admin` categories (replaces `faq` and `faq/admin`). Breadcrumb navigation
  in `docs/guide-viewer.php` updated to reflect new structure.
- **Root path array updated**
  ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  `z_us_root.php` path array updated with new directories (`docs/guides/`,
  `docs/reference/`, `docs/admin/`).
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
- [#715](https://github.com/unibrain1/elanregistry/issues/715) —
  chore: co-locate PDF assets with their documentation sections

## Summary

5 issues resolved across navigation refactoring, documentation reorganization,
and asset co-location. The primary change is a restructured docs hierarchy that
organises content by user intent rather than format, paired with a simplified
navigation menu, file-based nav rendering, and PDF assets moved alongside the
pages that reference them.
