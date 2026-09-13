# Elan Registry v2.30.2 Release Notes

**Release Date:** September 7, 2026
**Type:** Minor Release - The go-live gate — no real email sends until this closes. Automatic bounce/delivery detection, webhook reconciliation, and auto-clearing on confirmed email change.

## User-Facing Changes

- The join form's "Use My Current Location" button works again. (#2050)

## Admin-Facing Changes

- New "Verification System" tab on the admin management page shows Brevo/cron health and a site-wide verification feature switch, defaulted **off**. (#1926)
- The tab's "Last reconciliation run" row now reads the job's real status instead of a stale placeholder. (#2054)

## Issues Resolved

- [#1887](https://github.com/elan-registry/registry/issues/1887) — Automatic bounce & delivery-status detection via Brevo webhooks.
- [#1888](https://github.com/elan-registry/registry/issues/1888) — Configure & verify the Brevo webhook.
- [#1889](https://github.com/elan-registry/registry/issues/1889) — Nightly Brevo delivery-event reconciliation job.
- [#1890](https://github.com/elan-registry/registry/issues/1890) — Auto-clear `email_bounced` when the owner confirms an email change.
- [#1923](https://github.com/elan-registry/registry/issues/1923) — Import Brevo's suppression list (blockedContacts) into owner email status.
- [#1924](https://github.com/elan-registry/registry/issues/1924) — Show email bounce/suppression/verification state on the admin user view hook.
- [#1926](https://github.com/elan-registry/registry/issues/1926) — Verification-system feature switch with Brevo prerequisite check and admin warning.
- [#1968](https://github.com/elan-registry/registry/issues/1968) — Raise dev and CI to PHP 8.4 before 8.2 security EOL (test and production remain on 8.2 for now).
- [#2001](https://github.com/elan-registry/registry/issues/2001) — Extract cron transport interval into a shared, discoverable constant.
- [#2004](https://github.com/elan-registry/registry/issues/2004) — Truncate `us_rate_limits` once per integration suite run, fixing a memory-exhaustion crash in local test runs.
- [#2027](https://github.com/elan-registry/registry/issues/2027) — Extract `CronJobGuard` atomic-claim class, scoped to v2.30.2.
- [#2034](https://github.com/elan-registry/registry/issues/2034) — Generic `er_cron_job_runs` table for cron job tracking, replacing per-job settings columns.
- [#2018](https://github.com/elan-registry/registry/issues/2018) — Remove rate limiting from `cars_list`, `factory_list`, `car_history`, and `statistics_request` (deliberate security-posture tradeoff — see ADR-019).
- [#1974](https://github.com/elan-registry/registry/issues/1974) — Stop the cron transport logging every request.
- [#2050](https://github.com/elan-registry/registry/issues/2050) — Fix `Permissions-Policy` header blocking geolocation everywhere, including same-origin, breaking "Use My Current Location."
- [#2054](https://github.com/elan-registry/registry/issues/2054) — Verification tab reads real reconciliation job status instead of a stale placeholder.
- [#2061](https://github.com/elan-registry/registry/issues/2061) — Surface inline run summary on the Brevo event reconciliation admin script.
