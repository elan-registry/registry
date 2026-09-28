# Elan Registry Dev Environment Release Notes

**Release Date:** TBD
**Type:** Internal - Developer Environment (no user-facing changes; not tagged or versioned)

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
- WIP: [#1993](https://github.com/elan-registry/registry/issues/1993) — Freshly provisioned test
  schemas get one collation matching dev and production.
- [#2134](https://github.com/elan-registry/registry/issues/2134) — All three PHPUnit configs set
  an explicit 512M memory limit, so test runs no longer die at PHP's 128MB default.
- [#2159](https://github.com/elan-registry/registry/issues/2159) — The activity-chart
  Playwright test no longer fails every September on "Sept".
- [#2160](https://github.com/elan-registry/registry/issues/2160) — The blocking pre-push integration
  gate runs in about 20 seconds instead of five minutes, skips live-network tests, and catches gated
  files that were renamed away.
- [#2161](https://github.com/elan-registry/registry/issues/2161) — Cleaned up `tests/integration/`,
  moving, merging or deleting low-signal tests and adding a `PassThroughDatabase` test double.
- WIP: [#2166](https://github.com/elan-registry/registry/issues/2166) — Integration tests' `php -S`
  servers no longer leak (two per container run), and a crashed run's leftover servers and Brevo
  `override.php` stub are cleaned up by the next run instead of silently changing dev email routing.
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
  maintained fork `ghcr.io/unibrain1/mock-brevo:1.2.0` (English UI; event report, block list,
  Brevo-style webhooks; mock webhooks send `BREVO_WEBHOOK_TOKEN`). `EMAIL_SYSTEM.md` now states that the
  app's send path does not use `BREVO_API_HOST` until [#2184](https://github.com/elan-registry/registry/issues/2184).
- [#2180](https://github.com/elan-registry/registry/issues/2180) — MAMP is retired for the
  Registry. Docker is now the only supported local environment. Local cron is an opt-in `cron`
  Compose service (`docker compose --profile cron up -d`) that runs with `cron_ip` set to `off`.
  Also lifted the `.htaccess`-skip gate on Playwright's `not-logged-in` suite.
  Remove an old MAMP `PLAYWRIGHT_BASE_URL` (for example `http://localhost:9999/...`) from `.env.local`.
- [#2222](https://github.com/elan-registry/registry/issues/2222) — The review-gate scripts no longer
  report a clean review as blocked, and `verify-ci-review.sh` reports "could not verify" as that, not
  as a finding.
