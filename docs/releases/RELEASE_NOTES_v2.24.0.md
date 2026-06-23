# Elan Registry v2.24.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - UX Polish & Visual Consistency

## Required Actions After Deployment

### Activate updated Brevo email override on each server

Verify Brevo plugin settings.  Disable and reactive from the settings page. 

After activating, send a test feedback email from the site and confirm the
reply-to name appears correctly in the received message.

## User-Facing Changes

### New Features

- **Application version in footer** ([#876](https://github.com/unibrain1/elanregistry/issues/876)): Current version tag now visible in the public footer for at-a-glance deployment confirmation.
- **Breadcrumb navigation** ([#767](https://github.com/unibrain1/elanregistry/issues/767)): Reference sub-pages, Car Stories, and Guides now include breadcrumb trails for easier orientation.
- **Identification guide photo lightbox** ([#833](https://github.com/unibrain1/elanregistry/issues/833)): Photos in the identification guide open in a full-screen lightbox for closer inspection.
- **Identification guide table of contents** ([#835](https://github.com/unibrain1/elanregistry/issues/835)): In-page ToC with jump links added to the identification guide.

### Improvements

- **Lotus-green color system** ([#757](https://github.com/unibrain1/elanregistry/issues/757)): Site-wide CSS custom property color system with Lotus green as primary — consistent brand color across all pages.
- **Navigation active state and Register CTA** ([#758](https://github.com/unibrain1/elanregistry/issues/758)): Active nav item now visually indicated; Register button promoted as a CTA for unauthenticated visitors.
- **Car details and homepage mobile UX** ([#760](https://github.com/unibrain1/elanregistry/issues/760)): Lotus-green hero banner on car detail pages; "Log in to contact owner" promoted to a visible outline button linking to login; photo renders above spec table on mobile on both the car detail page and the homepage.
- **Statistics overview** ([#761](https://github.com/unibrain1/elanregistry/issues/761)): Timeline and Registration Activity charts moved above the stat tiles so they are immediately visible on a 1920×1080 display without scrolling. Stat tiles migrated to the `er-stat-tile` component (dark background, Lotus Yellow accent) for visual consistency with the color system from #757; all four metrics now use a unified appearance.
- **Statistics chart color consistency** ([#762](https://github.com/unibrain1/elanregistry/issues/762)): All single-metric bar charts now use Lotus green (`--er-primary`) for a unified appearance across Geographic and Production tabs. Country doughnut legend capped at top 10 + "Other" to reduce clutter. Color Trends by Year chart gains hover-to-highlight so individual color lines are easy to follow. "Verified Cars" removed from the Data Quality radar (its axis was always sparse and unused). Red and blue color name variants standardized to consistent hex values in the color-chip mapping.
- **Car list refinements** ([#763](https://github.com/unibrain1/elanregistry/issues/763)): Series
  filter pills for quick browsing by model; Date Added column hidden by default (togglable);
  placeholder icon for cars without photos; DataTables upgraded to 2.3.8 with Bootstrap 5 styling.
- **Homepage button prominence** ([#764](https://github.com/unibrain1/elanregistry/issues/764)):
  Log In / Sign Up button prominence swapped; Important Resources moved to Reference Library.
- **Content page card header colors** ([#765](https://github.com/unibrain1/elanregistry/issues/765)):
  Reference, Stories, Guides, and Chassis Validation pages use consistent card header colors.
  Reference Library "Chassis Validation Rules" card button standardized to "Browse"; redundant
  intro line removed from the Guides page header.
- **Factory records unverified warning** ([#766](https://github.com/unibrain1/elanregistry/issues/766)):
  Amber alert for unverified factory records; introductory context paragraph added.
- **Paint colors page** ([#769](https://github.com/unibrain1/elanregistry/issues/769)):
  Larger colour swatches, more prominent filter tabs, Car Stories second CTA added.
- **Add/Edit Car form UX fixes** ([#772](https://github.com/unibrain1/elanregistry/issues/772)):
  Required fields (Year, Model, Chassis) no longer show a red invalid indicator before the user
  has interacted with them. Purchase Date and Sold Date fields replaced with native date pickers
  (no more manual YYYY-MM-DD entry). "I no longer own this car" checkbox given clear visual
  separation from the date field. Website field icon corrected to a globe.
- **Identification guide two-column layout** ([#834](https://github.com/unibrain1/elanregistry/issues/834)):
  Every car-model entry is now its own card with a Lotus-green model header
  and image-left / specs-right two-column body. Previously photoless models
  display the standard Elan placeholder image so the layout stays consistent
  end-to-end.

### Bug Fixes

- **WCAG AA contrast fix for L2 card header muted text** ([#892](https://github.com/unibrain1/elanregistry/issues/892)): `.text-muted` inside `.card-header-er-l2` now renders at `rgba(0,0,0,0.60)` (~4.8:1 contrast) instead of `#6c757d` (~3.3:1), meeting the WCAG AA minimum of 4.5:1 on the pale green `#e8f0ed` background.

- **26R Race cars now appear under the cyan 26R map filter**
  ([#849](https://github.com/unibrain1/elanregistry/issues/849)): Race cars on the statistics map
  were silently falling into the S1/S2 filter buckets, leaving the cyan 26R filter empty. The map
  now classifies markers by variant first so all Race cars appear in the 26R bucket. The
  misclassified `26R|Race|26` reference row that was the underlying cause has been removed from
  seed data.

## Admin-Facing Changes

- **One-time fix script: 26R Race model correction**
  ([#849](https://github.com/unibrain1/elanregistry/issues/849)): New script
  `04-Correct-26R-Race-Model-Classification.php` under Admin → Maintenance. Run once on each
  deployed environment after upgrading to v2.24.0 — migrates any car previously entered against
  the misclassified `26R|Race|26` model to the correct S2 Race classification and removes the bad
  reference row. Idempotent: a second run reports zero changes.

## Technical Changes

- **UserSpice 6.1.0 upgrade** ([#874](https://github.com/unibrain1/elanregistry/issues/874)):
  Framework upgraded to UserSpice 6.1.0; navigation.php upstream bug resolved (#734).
- **Brevo plugin `reply_name` forwarding** ([#812](https://github.com/unibrain1/elanregistry/issues/812)):
  Brevo plugin v1.6.0 now correctly forwards `reply_name` to the API. Stale workaround comments
  removed from `send-feedback.php` and `send-owner-email.php`. Requires activating the updated
  `override.RENAME.php` → `override.php` on each server (see Required Actions).
- **MarkdownParser replaced with league/commonmark** ([#815](https://github.com/unibrain1/elanregistry/issues/815)):
  Custom regex-based MarkdownParser (430 lines) replaced with league/commonmark 2.8.2. Gains GFM tables, heading permalink anchors (prerequisite for #768), blockquote and nested list support. Improved sanitization with `html_input: 'strip'` and `allow_unsafe_links: false`; external links now open in new tab with `rel=noopener noreferrer`. No user-facing functional change.
- **ESLint no-implicit-globals fixed in statistics.js** ([#871](https://github.com/unibrain1/elanregistry/issues/871)):
  `renderMapErrorUI` and `initMarkerFilter` converted from top-level `function` declarations to
  `const` arrow functions, eliminating the two ESLint `no-implicit-globals` warnings.

## Issues Resolved

- [#734](https://github.com/unibrain1/elanregistry/issues/734) — Stop tracking navigation.php when upstream bug is fixed
- [#812](https://github.com/unibrain1/elanregistry/issues/812) — Track upstream fix: Brevo plugin override.php signature mismatch (bugs.userspice.com/2334)
- [#757](https://github.com/unibrain1/elanregistry/issues/757) — ux: establish CSS custom property color system with Lotus green as primary
- [#758](https://github.com/unibrain1/elanregistry/issues/758) — ux: nav active state indicator and Register as CTA button
- [#760](https://github.com/unibrain1/elanregistry/issues/760) — ux: car details hero banner — Lotus green, prominent contact owner button, mobile photo-first
- [#761](https://github.com/unibrain1/elanregistry/issues/761) — ux: statistics overview — move timeline charts above fold, unify stat card colors
- [#762](https://github.com/unibrain1/elanregistry/issues/762) — ux: statistics chart color consistency across Geographic, Production, and Colors tabs
- [#763](https://github.com/unibrain1/elanregistry/issues/763) — ux: car list — reduce default columns, series filter pills, placeholder images
- [#764](https://github.com/unibrain1/elanregistry/issues/764) — ux: homepage — swap Log In/Sign Up button prominence; move Important Resources to Reference Library
- [#765](https://github.com/unibrain1/elanregistry/issues/765) — ux: content page card header color consistency — Reference, Stories, Guides, Chassis Validation
- [#766](https://github.com/unibrain1/elanregistry/issues/766) — ux: factory records — amber alert for unverified warning; add introductory context
- [#767](https://github.com/unibrain1/elanregistry/issues/767) — ux: add breadcrumb navigation to Reference sub-pages, Car Stories, and Guides
- [#769](https://github.com/unibrain1/elanregistry/issues/769) — ux: paint colors — larger swatches, more prominent filter tabs, Car Stories second CTA
- [#772](https://github.com/unibrain1/elanregistry/issues/772) — UX: Fix Add/Edit Car form — premature validation icons, Purchase Date input, checkbox layout
- [#812](https://github.com/unibrain1/elanregistry/issues/812) — Track upstream fix: Brevo plugin override.php signature mismatch (bugs.userspice.com/2334)
- [#815](https://github.com/unibrain1/elanregistry/issues/815) — refactor: replace custom MarkdownParser with league/commonmark
- [#833](https://github.com/unibrain1/elanregistry/issues/833) — ux: identification guide — photo lightbox
- [#834](https://github.com/unibrain1/elanregistry/issues/834) — ux: identification guide — two-column layout modernization
- [#835](https://github.com/unibrain1/elanregistry/issues/835) — ux: identification guide — in-page table of contents
- [#849](https://github.com/unibrain1/elanregistry/issues/849) — fix: correct misclassified 26R Race entry in car_models and update statistics map filter
- [#871](https://github.com/unibrain1/elanregistry/issues/871) — lint: fix ESLint no-implicit-globals warnings in app/assets/js/statistics.js
- [#874](https://github.com/unibrain1/elanregistry/issues/874) — Upgrade to Userspice 6.1.0
- [#876](https://github.com/unibrain1/elanregistry/issues/876) — feat: display application version in public footer
- [#892](https://github.com/unibrain1/elanregistry/issues/892) — ux: fix text-muted contrast in L2 card headers (WCAG AA)
