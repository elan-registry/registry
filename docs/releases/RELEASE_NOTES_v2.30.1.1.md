# Elan Registry v2.30.1.1 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Production Hotfix

This hotfix patches production for live defects without shipping v2.30.2 and
later. It is built from tag `v2.30.1`, the version now in production. After
release, merge the fixes forward into `main` and the open milestone branches.

## Required Actions After Deployment

None. This release has no database migration, no new page or admin script,
and no new configuration. Deploy the `v2.30.1.1` tag. Do not deploy `main`,
which carries later, unreleased work.

Checks after deploy:

- #2227: check that `logs` gets no new
  `Invalid CSRF token in join-failure-report beacon` rows.
- #2144: open a car page while logged out, and check that the history card
  shows a login prompt, not the table.

## User-Facing Changes

### Improvements

- **Car update history is for members only** ([#2144](https://github.com/elan-registry/registry/issues/2144)): each history row shows a past owner's first name and location, so a visitor who is not logged in now sees a prompt to log in instead of the history table. The rest of the car page is unchanged for every visitor. Members see the history as before.

## Admin-Facing Changes

### Improvements

- **Join-form failure reports are no longer dropped** ([#2227](https://github.com/elan-registry/registry/issues/2227)): the join-form failure beacon no longer needs a CSRF token, so a report is no longer refused when the page's token has gone stale. The beacon's own rate limit now counts requests and takes the token's place as the abuse control (ADR-019, amended with a narrow exception for anonymous diagnostic log writes).

## Issues Resolved

- [#2144](https://github.com/elan-registry/registry/issues/2144) — security: require login for car history — history.php has no auth/CSRF/rate-limit, reverses #1305's "public by design"
- [#2227](https://github.com/elan-registry/registry/issues/2227) — fix: join-form failure beacon is refused 403 when the session's CSRF token turns over
