# Elan Registry v2.30.4 Release Notes

**Release Date:** TBD (started 2026-09-28)
**Type:** Patch Release - Verification System Refresh: Freshness Surfaced

Anyone researching a car can see how fresh its record is: Verified and Sold
badges with dates, a freshness vector on the statistics page, and engagement
touches on the verification email itself.

## User-Facing Changes

- Removing a photo from your own car now counts as keeping its record current, the same as editing it or uploading a
  photo. ([#1929](https://github.com/elan-registry/registry/issues/1929))
- The statistics page's Data Completeness chart and Quality Metrics list show a "Verified (12 mo)" figure, which is the
  share of cars verified or updated by their owner in the last 12 months. ([#1898](https://github.com/elan-registry/registry/issues/1898))
- Cars show Sold and Verified badges with tooltips on the account page, the cars list, and the car details page.
  ([#1900](https://github.com/elan-registry/registry/issues/1900))
- The Vehicle Information card on the car details page, your account page, and the vericode page has a Verified row
  with a date and a help button. ([#1897](https://github.com/elan-registry/registry/issues/1897))
- The verification email shows a photo of your car with a link to all its photos, or asks you to add one if no photo is
  on file. ([#1894](https://github.com/elan-registry/registry/issues/1894))
- The password reset, registration attempt, and email verification emails no longer show a long link as text, and they
  give a contact address if the button does not work. ([#2147](https://github.com/elan-registry/registry/issues/2147))
- The maps on the statistics page, car details page, and your account page show a flat world again, not a globe,
  when you zoom out. ([#2268](https://github.com/elan-registry/registry/issues/2268))

## Admin-Facing Changes

- On the car details page, admins and editors see an Email on file row with tooltips when the owner's address is
  marked Bounced or Suppressed. ([#1897](https://github.com/elan-registry/registry/issues/1897))

## Issues Resolved

- [#1929](https://github.com/elan-registry/registry/issues/1929) —
  `CLASSES.md` lists which owner actions reset `owner_last_updated` (a car's freshness date), each with a covering
  test.
- [#2189](https://github.com/elan-registry/registry/issues/2189) — The failed-login log no longer stores the submitted username verbatim, which had stored a
  password in plain text.
- [#2259](https://github.com/elan-registry/registry/issues/2259) —
  Workflow commands and agents use the model each step needs, and repeated review work is removed (developer workflow
  only, no site change).
- [#1897](https://github.com/elan-registry/registry/issues/1897) —
  The shared Vehicle Information card has a Verified row and an admin-only Email on file row, and the owner's Resume
  control is deferred to [#1895](https://github.com/elan-registry/registry/issues/1895).
- [#1900](https://github.com/elan-registry/registry/issues/1900) —
  Sold and Verified badges appear on the account page and cars list, with a Sold stamp on the car details page, and
  the new `CarBadges` class owns which badges a car shows.
- [#1898](https://github.com/elan-registry/registry/issues/1898) —
  The statistics page's data-completeness radar chart has a Verified vector, and `verified_cars` now uses the shared
  freshness rule.
- [#1894](https://github.com/elan-registry/registry/issues/1894) —
  The verification email's Photos row shows the primary photo's thumbnail, with a plain count or a highlighted "Not
  yet provided" note when no thumbnail exists (includes the remaining gap from #1892).
- [#2147](https://github.com/elan-registry/registry/issues/2147) —
  The password reset, registration attempt, and email verification emails no longer print the link as text, because
  Brevo rewrites it to a tracking domain and it looked like a phishing link.
- [#2150](https://github.com/elan-registry/registry/issues/2150) —
  Integration tests now connect the verification email links to the landing page and show that a hard bounce or an
  opt-out removes the car from the next verification batch (tests only, no site change).
- [#2250](https://github.com/elan-registry/registry/issues/2250) —
  `render-deploy-sheet.sh` no longer reports false migration, trigger, new-page and admin-script conditions
  (developer workflow only, no site change).
- [#2271](https://github.com/elan-registry/registry/issues/2271) —
  Cleanup-ledger items are checked before the PR is pushed, and `/finish-issue` ticks them with the new
  `scripts/ledger-pr-body-items.sh` and `scripts/ledger-tick-items.sh` (developer workflow only, no site change).
- [#2268](https://github.com/elan-registry/registry/issues/2268) —
  `scripts/build.js` passes `projection: 'mercator'` to `osm()` because `@versatiles/style` v6 made `globe` the
  default, and CI now checks the projection.

## Carried from the Dev Environment milestone

The Dev Environment milestone merged to `main` with no release of its own, and no earlier tag contains its commits.
v2.30.4 is the first versioned release that ships these production-affecting changes.

- [#2228](https://github.com/elan-registry/registry/issues/2228) —
  `server_globals.php` and `getBaseUrl()` add a port only when the request uses a non-default port and has no
  X-Forwarded-Proto of `http` or `https`, so behind Cloudflare, canonical, `og:url`, sitemap and emailed links have
  no `:80` or `:443`.
- [#2212](https://github.com/elan-registry/registry/pull/2212) — `.htaccess` blocks `/.git/` at the origin with a 403 (#2066).
- [#2121](https://github.com/elan-registry/registry/pull/2121) — `.htaccess` also denies `docker-compose*.yml` files, and `index.php` no longer starts with two
  spaces before `<?php`, so the home page sends no stray bytes before its headers.
- [#2123](https://github.com/elan-registry/registry/pull/2123) and [#2229](https://github.com/elan-registry/registry/pull/2229) —
  `.deployignore` keeps `docker/` and `docker-compose.yml` out of the server checkout.
- [#2130](https://github.com/elan-registry/registry/pull/2130) —
  Both Brevo cron clients call `BrevoDevOverride::hostOverride()`, which changes the API host only when
  `US_ENVIRONMENT` is `development` and `BREVO_API_HOST` is set, so test and prod `.env` must set neither.
- [#2232](https://github.com/elan-registry/registry/pull/2232) — With no request (CLI or early boot) and an empty `email.verify_url` setting, `getBaseUrl()` now
  returns `https://elanregistry.org`, not an empty string.

## Retrospective

- **Shipped but not needed:** the workflow work in #2259 (model routing) and #2271 (ledger gate).
  It improved the developer process and gave users nothing.
- **What we learned about the audience:** too early to say. The verification send pipeline is
  still switched off, so no researcher or owner has seen the freshness signals yet.
- **Signal we ignored:** #2149 asked for an enum-backed `SendResult` status. We moved it to the
  cleanup ledger (#2208). That was right, because it has no user impact.
