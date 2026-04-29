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
  All 18 app pages audited and fixed for 375px mobile viewports — DataTables columns collapse to
  expandable child rows on phones, edit forms stack correctly, admin tabs scroll horizontally,
  chart containers shrink at mobile breakpoint. No horizontal overflow on any page.
- **Homepage "How are we doing?" chart** ([#620](https://github.com/unibrain1/elanregistry/issues/620)):
  Replaced statistics table with Bootstrap 5 progress bars showing registered vs. produced counts
  per series. Intro text now shows live car count and years since the registry started (Jan 2003).
- **Factory page improvements** ([#620](https://github.com/unibrain1/elanregistry/issues/620)):
  Hid the internal Record # column; Registry Link column is now sortable.
- **Photo uploads on Add/Edit Car form** ([#741](https://github.com/unibrain1/elanregistry/issues/741)):
  Now use FilePond with improved drag-to-reorder, thumbnail previews, EXIF orientation
  correction for mobile photos, and client-side file type/size validation with clear error messages.
- **Photo upload layout on mobile** ([#743](https://github.com/unibrain1/elanregistry/issues/743)):
  Constraints list and FilePond widget now stack full-width on all screen sizes; reorder hint updated to "Tap and hold to reorder • Drag on desktop".

- **Add/Edit Car form redesigned as accordion** ([#745](https://github.com/unibrain1/elanregistry/issues/745)):
  The 4-step wizard (sequential Next/Back navigation) has been replaced with a vertical Bootstrap 5
  accordion. All three content sections are accessible without navigating forward or backward, making
  non-linear editing and returning to a previous section much easier — especially on mobile. The submit
  button is now a sticky footer element always visible regardless of scroll position. Form validation
  errors auto-expand the section containing the first error and scroll to the error message, eliminating
  the vestigial "Step 4 Results" screen.

### Bug Fixes

- **Add/Edit Car form mobile layout** ([#744](https://github.com/unibrain1/elanregistry/issues/744)):
  Fixed four mobile CSS regressions: buttons now use flexbox (`d-flex justify-content-between`)
  instead of `float: right` so they never overlap on narrow screens; input wrappers updated to
  `col-12 col-sm-9` so fields stack correctly at 375 px; comments textarea reduced from 10 to 4
  rows; progress bar step labels enlarged from 12 px to 14 px on screens ≤ 575 px.

## Technical Changes

- **Self-hosted Frontend Libraries** ([#405](https://github.com/unibrain1/elanregistry/issues/405)):
  Replaced database-driven CDN configuration with source-controlled, versioned local files.
  Enables Dependabot security alerts for JS/CSS dependencies.
- **BS4 → BS5 Class Migration** ([#619](https://github.com/unibrain1/elanregistry/issues/619)):
  Migrated all Bootstrap 4 classes and data attributes across 35 app files to Bootstrap 5 equivalents.
- **Customizer Template Alignment** ([#620](https://github.com/unibrain1/elanregistry/issues/620)):
  Custom navigation renamed to `file_nav_custom.php` to survive Spice Shaker template upgrades.
  Bootstrap 5 now loaded from `cdnjs.cloudflare.com` via the upstream template (SRI-hashed).
  Application version now displayed on the admin dashboard.
- **Minified JS/CSS assets** ([#737](https://github.com/unibrain1/elanregistry/issues/737)):
  All first-party JS and CSS files are now minified with esbuild. `.min.js`/`.min.css` files are
  committed to git and served in production. A pre-commit hook auto-rebuilds minified files when
  source files change. `npm run build` regenerates all 12 minified assets on demand.
- **FilePond image upload library** ([#741](https://github.com/unibrain1/elanregistry/issues/741)):
  Replaced Dropzone 5.7.6 + jQuery UI Sortable with FilePond 4.x + six plugins
  (image-exif-orientation, file-validate-type, file-validate-size, image-preview,
  image-resize, image-transform); removed dropzone and jquery-ui npm dependencies.
  Client-side image resize (to `elan_image_display_max_size`) before upload.
  Fixed XSS vulnerability in edit car form validation error display (escapeHtml applied to server error messages).
  Added `basename()` normalization to server-side `removeImages` file parameter.
- **Add/Edit Car form accordion refactor** ([#745](https://github.com/unibrain1/elanregistry/issues/745)):
  Replaced 4-fieldset jQuery wizard with Bootstrap 5 accordion. Removed step animation JS (~80 lines),
  progress bar HTML, Next/Previous buttons. New JS: section completion tracking (green checkmark after
  any field interaction), skip buttons for optional sections (Details & Comments, Photos), error
  auto-expand pointing to the panel containing the first server-side validation error.
  Fixed pre-existing stored XSS: added `htmlspecialchars()` to 6 `value=` attributes in
  `_edit_car_1.php` and `_edit_car_2.php` (chassis, color, engine, purchasedate, solddate, website).
  Updated 4 Playwright tests to use accordion selectors.
- **Playwright regression tests for Bootstrap 5 JS API** ([#732](https://github.com/unibrain1/elanregistry/issues/732)):
  Added `tests/playwright/bs5-migration.test.js` covering Flatpickr date picker initialization
  on the edit car form, Statistics page `ElanRegistryAPI` availability and tab AJAX lazy-loading,
  and the navigation dropdown `firstChild` patch in `footer.php` (no TypeError on outside-click).
  New `npm run playwright:bs5` script for targeted execution.
- **StatisticsApiTest integration tests** ([#740](https://github.com/unibrain1/elanregistry/issues/740)):
  Removed `try/catch (Throwable)` anti-pattern that was converting assertion failures into silent
  skips; corrected asserted strings to match the actual `LogCategories` constants and `error.message`
  JS pattern used in the source files.

## Issues Resolved

- [#405](https://github.com/unibrain1/elanregistry/issues/405) — Replace DB-driven CDN config with source-controlled self-hosted libraries
- [#618](https://github.com/unibrain1/elanregistry/issues/618) — Rebase ElanRegistry template on Bootstrap 5.3 (Customizer pattern)
- [#619](https://github.com/unibrain1/elanregistry/issues/619) — Migrate Bootstrap 4 classes and data attributes across all app pages
- [#620](https://github.com/unibrain1/elanregistry/issues/620) — Mobile responsiveness audit and remediation — all app pages
- [#737](https://github.com/unibrain1/elanregistry/issues/737) — Minify first-party JS and CSS files to reduce payload size
- [#741](https://github.com/unibrain1/elanregistry/issues/741) — Migrate photo upload from Dropzone to FilePond with improved UX and security fixes
- [#743](https://github.com/unibrain1/elanregistry/issues/743) — Fix Step 3 photo upload layout for mobile
- [#744](https://github.com/unibrain1/elanregistry/issues/744) — Fix mobile CSS regressions in add/edit car form
- [#745](https://github.com/unibrain1/elanregistry/issues/745) — Replace 4-step wizard with vertical accordion for add/edit car form
- [#732](https://github.com/unibrain1/elanregistry/issues/732) — Add Playwright regression tests for Bootstrap 5 JS API migration critical paths
- [#740](https://github.com/unibrain1/elanregistry/issues/740) — Fix silently-skipping integration tests in StatisticsApiTest

## Summary

11 issues resolved: full Bootstrap 5 migration (self-hosted libraries, template rebase, class migration,
mobile responsiveness), Playwright regression tests for BS5 JS API patterns, first-party JS/CSS minification
with esbuild, FilePond image upload library migration with mobile layout fix, add/edit car form redesigned
as Bootstrap 5 accordion with sticky submit footer (replacing 4-step wizard), add/edit car form mobile CSS
regressions, and StatisticsApiTest integration test fixes.
