# Elan Registry Dev Environment Release Notes

**Release Date:** TBD
**Type:** Developer environment. No tag and no deploy of its own: it merges to `main` and ships
with the next versioned release. Two changes affect production (see below).

A developer does all Registry work in a Docker checkout and can trust every
local check's result; MAMP is no longer needed.

## Issues Resolved

- [#2116](https://github.com/elan-registry/registry/issues/2116) — Proved a Docker Compose LAMP
  stack (PHP 8.4, MySQL 8.0, phpMyAdmin) can replace MAMP for the full toolchain.
- [#2117](https://github.com/elan-registry/registry/issues/2117) — Evaluated mailtrap-local for
  offline email testing.
- [#2118](https://github.com/elan-registry/registry/issues/2118) — Evaluated mock-brevo for offline
  email testing and chose it for the Docker stack.
- [#2120](https://github.com/elan-registry/registry/issues/2120) — Rolled the Docker stack out with
  Traefik label routing and a DB grant fix.
- [#2127](https://github.com/elan-registry/registry/issues/2127) — Added a mock-brevo service so the
  Brevo email code path runs fully offline.
- [#2134](https://github.com/elan-registry/registry/issues/2134) — All three PHPUnit configs set
  an explicit 512M memory limit, so test runs no longer die at PHP's 128MB default.
- [#2159](https://github.com/elan-registry/registry/issues/2159) — The activity-chart
  Playwright test no longer fails every September on "Sept".
- [#2160](https://github.com/elan-registry/registry/issues/2160) — The blocking pre-push integration
  gate runs in about 20 seconds instead of five minutes, skips live-network tests, and catches gated
  files that were renamed away.
- [#2161](https://github.com/elan-registry/registry/issues/2161) — Cleaned up `tests/integration/`,
  moving, merging or deleting low-signal tests and adding a `PassThroughDatabase` test double.
- [#2166](https://github.com/elan-registry/registry/issues/2166) — Integration tests no longer leak
  `php -S` servers, and the next run cleans up a crashed run's leftover servers and Brevo
  `override.php` stub.
- [#2168](https://github.com/elan-registry/registry/issues/2168) — The integration suite no longer
  calls live geocoding services during the pre-push gate.
- [#2171](https://github.com/elan-registry/registry/issues/2171) — The pre-push gate runs the
  integration suite inside the Docker app container when the checkout uses Docker.
- [#2172](https://github.com/elan-registry/registry/issues/2172) — `scripts/refresh-local-db.sh`
  loads production data into the Docker database.
- [#2173](https://github.com/elan-registry/registry/issues/2173) — Each checkout runs its own Docker
  stack on its own ports, with a landing page linking the site, phpMyAdmin and the mock Brevo inbox.
- [#2175](https://github.com/elan-registry/registry/issues/2175) — Documented which tool reads each
  env file and fixed a provisioning guard that checked the wrong one.
- [#2178](https://github.com/elan-registry/registry/issues/2178) — mock-brevo is pinned to the
  maintained fork `ghcr.io/unibrain1/mock-brevo:1.2.0`, and `EMAIL_SYSTEM.md` states that the app's
  send path does not use `BREVO_API_HOST` until [#2184](https://github.com/elan-registry/registry/issues/2184).
- [#2180](https://github.com/elan-registry/registry/issues/2180) — MAMP is retired for the Registry:
  Docker is the only supported local environment, and local cron is an opt-in `cron` Compose service.
- [#2199](https://github.com/elan-registry/registry/issues/2199) — The UserSpice audit, helper-lookup
  and page-scaffold skills are now committed under `.claude/skills/`.
- [#2222](https://github.com/elan-registry/registry/issues/2222) — The review-gate scripts no longer
  report a clean review as blocked, and `verify-ci-review.sh` reports "could not verify" as that, not
  as a finding.
- [#2223](https://github.com/elan-registry/registry/issues/2223) — The CI review gate in
  `claude-code-review.yml` now fails on an unresolved Blocking heading and on any grep error, and a
  hook test keeps its patterns and logic the same as `check-blocking-findings.sh`.
- [#2225](https://github.com/elan-registry/registry/issues/2225) — `verify-ci-review.sh` and
  `render-deploy-sheet.sh` no longer miss a match on a large PR or release diff, and each reports
  "could not verify" when it cannot read its input.
- [#2228](https://github.com/elan-registry/registry/issues/2228) — Local URLs keep the browser's host
  and port (`http://localhost:8001`), also in cron emails, and `getBaseUrl()` no longer adds `:80`
  behind a TLS proxy such as `cloudflared`.
- [#2245](https://github.com/elan-registry/registry/issues/2245) — The workflow scripts no longer
  report a failed git or gh step as success, `run-verification-suite.sh` runs integration tests in
  the Docker app container, and CI now runs every hook test.

## Production-affecting changes

The next versioned release must list these and check them on test and prod.

- [#2228](https://github.com/elan-registry/registry/issues/2228) — `server_globals.php` and
  `getBaseUrl()` add a port only when the request uses a non-default port and has no
  X-Forwarded-Proto of `http` or `https`. Behind Cloudflare, canonical, `og:url`, sitemap and emailed links have no `:80` or `:443`.
- [#2212](https://github.com/elan-registry/registry/pull/2212) — `.htaccess` blocks `/.git/` at the
  origin with a 403.
- [#2121](https://github.com/elan-registry/registry/pull/2121) — `.htaccess` also denies
  `docker-compose*.yml` files.

## Tooling

- [#2209](https://github.com/elan-registry/registry/pull/2209) — Slimmer workflow commands, new helper
  scripts, hooks and path-scoped rules. `USERSPICE_FUNCTIONS.md` is replaced by the
  `userspice-helper-lookup` skill.
- [#2200](https://github.com/elan-registry/registry/pull/2200) — Docker stack updates.
- [#2170](https://github.com/elan-registry/registry/pull/2170) — MAMP-to-Docker migration guide.
- [#2213](https://github.com/elan-registry/registry/pull/2213) — The Brevo webhook spike script
  registers webhooks with bearer auth.

## Developer Actions

- Set `DB_HOST=db` and `DB_PORT=3306` in `.env` and in `.env.test.local` (see `.env.example` and
  `.env.test.local.sample`).
- Start the stack (`docker compose up -d`) before a push that runs the pre-push integration gate.
  The gate blocks the push when the app container is not running.
- In a second checkout, set its own host ports in its `.env` (`ENVIRONMENT.md`, "Docker Dev
  Environment").
- Remove an old MAMP `PLAYWRIGHT_BASE_URL` (for example `http://localhost:9999/...`) from
  `.env.local` (#2180).

## Retrospective

- **Shipped but not needed:** nothing.
- **What we learned about the audience:** gates must fail closed. The review and test gates did
  most of the work for the agents, and each silent pass (#2222, #2223, #2225) cost more than a
  noisy failure would have.
- **Signal we ignored:** #2157 asked for integration tests in CI. We merged it into the #2044
  spike, which was right, because the earlier try (#1746) failed.
