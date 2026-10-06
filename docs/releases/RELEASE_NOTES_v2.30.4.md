# Elan Registry v2.30.4 Release Notes

**Release Date:** TBD (started 2026-09-28)
**Type:** Patch Release - Verification System Refresh: Freshness Surfaced

Anyone researching a car can see how fresh its record is: Verified and Sold
badges with dates, a freshness vector on the statistics page, and a car photo
in the verification email.

## User-Facing Changes

- Sold and Verified badges on the account page, cars list, and car details page. ([#1900](https://github.com/elan-registry/registry/issues/1900))
- Verified row with a date on the Vehicle Information card. ([#1897](https://github.com/elan-registry/registry/issues/1897))
- "Verified (12 mo)" figure on the statistics page. ([#1898](https://github.com/elan-registry/registry/issues/1898))
- Removing a photo from your own car keeps its record current. ([#1929](https://github.com/elan-registry/registry/issues/1929))
- The verification email shows a photo of your car, or asks for one. ([#1894](https://github.com/elan-registry/registry/issues/1894))
- Auth emails no longer show a long tracking link as text. ([#2147](https://github.com/elan-registry/registry/issues/2147))
- Maps show a flat world again when you zoom out. ([#2268](https://github.com/elan-registry/registry/issues/2268))

## Admin-Facing Changes

- Admins and editors see an Email on file row when the owner's address is Bounced or Suppressed. ([#1897](https://github.com/elan-registry/registry/issues/1897))

## Issues Resolved

- [#1894](https://github.com/elan-registry/registry/issues/1894) — Photo thumbnail row in the verification email (includes the gap left by #1892).
- [#1897](https://github.com/elan-registry/registry/issues/1897) — Verified row and admin Email on file row. Owner Resume control deferred to [#1895](https://github.com/elan-registry/registry/issues/1895).
- [#1898](https://github.com/elan-registry/registry/issues/1898) — Verified vector on the data-completeness radar.
- [#1900](https://github.com/elan-registry/registry/issues/1900) — Sold and Verified badges, owned by the new `CarBadges` class.
- [#1929](https://github.com/elan-registry/registry/issues/1929) — `CLASSES.md` defines which owner actions reset `owner_last_updated`.
- [#2147](https://github.com/elan-registry/registry/issues/2147) — Auth emails no longer print the Brevo-rewritten link.
- [#2150](https://github.com/elan-registry/registry/issues/2150) — Integration tests for verification links, bounces, and opt-outs (tests only).
- [#2189](https://github.com/elan-registry/registry/issues/2189) — Failed-login log no longer stores the submitted username.
- [#2250](https://github.com/elan-registry/registry/issues/2250) — `render-deploy-sheet.sh` false positives fixed (workflow only).
- [#2259](https://github.com/elan-registry/registry/issues/2259) — Commands and agents use the model each step needs (workflow only).
- [#2268](https://github.com/elan-registry/registry/issues/2268) — `scripts/build.js` sets `projection: 'mercator'`, checked in CI.
- [#2271](https://github.com/elan-registry/registry/issues/2271) — Cleanup-ledger items are checked before the PR is pushed (workflow only).

## Carried from the Dev Environment milestone

That milestone merged to `main` with no release. v2.30.4 is the first tagged
release that ships its production-affecting changes:

- [#2228](https://github.com/elan-registry/registry/issues/2228) — Generated links have no `:80` or `:443` behind Cloudflare.
- [#2212](https://github.com/elan-registry/registry/pull/2212) — `.htaccess` blocks `/.git/` with a 403.
- [#2121](https://github.com/elan-registry/registry/pull/2121) — `.htaccess` denies `docker-compose*.yml`, and `index.php` sends no bytes before its headers.
- [#2123](https://github.com/elan-registry/registry/pull/2123), [#2229](https://github.com/elan-registry/registry/pull/2229) — `.deployignore` keeps `docker/` and `docker-compose.yml` off the server.
- [#2130](https://github.com/elan-registry/registry/pull/2130) — Brevo cron clients honor `BREVO_API_HOST` only when `US_ENVIRONMENT` is `development`.
- [#2232](https://github.com/elan-registry/registry/pull/2232) — `getBaseUrl()` returns `https://elanregistry.org`, not an empty string, with no request.
