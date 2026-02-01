# Elan Registry v2.14.0 Release Notes

**Release Date:** TBD
**Type:** Minor Release - Data Quality & Validation

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

None required for changes included so far.

## 👤 User-Facing Changes

### Improvements

- **Car History Table Modernization** ([#322](https://github.com/unibrain1/elanregistry/issues/322)): Converted the car history table
  on the details page from a static HTML table to AJAX-loaded DataTables with
  server-side processing. Changed fields are now highlighted for easier visual
  comparison between history records.

### Bug Fixes

- **Toast Notification Fix** ([#536](https://github.com/unibrain1/elanregistry/issues/536)): Toasts repositioned to top-right with unified styling and proper z-index

## 🔧 Technical Changes

### Code Quality

- **History Table AJAX Loading** ([#322](https://github.com/unibrain1/elanregistry/issues/322)): Replaced inline static history table
  with `car_details.js` DataTables AJAX endpoint (`app/cars/actions/history.php`).
  Extracted field-difference highlighting into a reusable
  `highlightDifferences.js` module.
- **PHP Linter Fix**: Fixed false positive from PHP linter on the JavaScript
  `initMap` function embedded in `details.php`.

- **Class Consolidation** ([#529](https://github.com/unibrain1/elanregistry/issues/529)): Namespaced exception classes, consolidated
  class file locations, and removed duplicates.
- **Unified Toast System** ([#536](https://github.com/unibrain1/elanregistry/issues/536)): BS4-compatible toast overrides with `pre_footer.php` hook; `NotificationHelper` delegates to UserSpice toasts. Tagged `@todo #234` for BS5 removal.

## 📋 Milestone Issues

### Closed

- [#322](https://github.com/unibrain1/elanregistry/issues/322) — ENHANCEMENT: Fix car history table date ordering and highlight changed fields
- [#369](https://github.com/unibrain1/elanregistry/issues/369) — FIX: Add strict types and specific exceptions to edit.php (already resolved)
- [#529](https://github.com/unibrain1/elanregistry/issues/529) — Consolidate class file locations and remove duplicates
- [#536](https://github.com/unibrain1/elanregistry/issues/536) — Toast notifications overlap page heading and UI elements

### Open

- [#10](https://github.com/unibrain1/elanregistry/issues/10) — User Add and Account Update - Data Validation
- [#298](https://github.com/unibrain1/elanregistry/issues/298) — Data Quality: Normalize color field values
- [#325](https://github.com/unibrain1/elanregistry/issues/325) — Update contact form email to use configurable admin emails
- [#330](https://github.com/unibrain1/elanregistry/issues/330) — Coding standards cleanup for transfer system files
- [#354](https://github.com/unibrain1/elanregistry/issues/354) — Document Brevo/Sendinblue configuration
- [#368](https://github.com/unibrain1/elanregistry/issues/368) — Use settings for admin email
- [#377](https://github.com/unibrain1/elanregistry/issues/377) — Configure ImgBot to exclude user content and test artifacts
- [#390](https://github.com/unibrain1/elanregistry/issues/390) — Implement custom reply-to email addresses in SendInBlue plugin
- [#467](https://github.com/unibrain1/elanregistry/issues/467) — Improve location terminology for international inclusivity
- [#514](https://github.com/unibrain1/elanregistry/issues/514) — Remove deprecated imagedestroy() call in Resize.php
- [#543](https://github.com/unibrain1/elanregistry/issues/543) — Create apple-touch-icon assets
