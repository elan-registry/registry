# Elan Registry Test Infra Cleanup Release Notes

**Release Date:** TBD
**Type:** Patch Release - Test Infrastructure Reliability

## Required Actions After Deployment

None.

## User-Facing Changes

None — this milestone is developer-facing test infrastructure only.

## Admin-Facing Changes

None.

## Developer-Facing Changes

Changes to the local/CI test harness so a green test run actually reflects reality.

- **Playwright auth harness failure detection** ([#1935](https://github.com/elan-registry/registry/issues/1935)): the local `login()` helper now fails fast with a diagnostic message (embedding the real UserSpice error toast text) instead of hanging on a generic 15-second timeout with no signal as to why. Test/Production e2e runs now get a `check-auth` pre-flight project that detects a stale or missing saved session and fails loudly — pointing at the relevant `scripts/playwright-auth-1password[-test].sh` script — instead of silently running the `logged-in` project's tests anonymously.

## Issues Resolved

- WIP: [#1648](https://github.com/elan-registry/registry/issues/1648) — test: no positive assertion that pdf-viewer.php's success branch renders the iframe with the correct document src
- WIP: [#1773](https://github.com/elan-registry/registry/issues/1773) — test: add Playwright HTTP coverage for process-user-details.php admin endpoint
- WIP: [#1789](https://github.com/elan-registry/registry/issues/1789) — test: process-transfer-approve.php has no real Playwright success-path coverage (needs disposable non-admin-owned car fixture)
- [#1935](https://github.com/elan-registry/registry/issues/1935) — test: Playwright auth harness has no failure detection — stale storageState runs anonymous, bad local creds hang on a generic timeout
- WIP: [#2014](https://github.com/elan-registry/registry/issues/2014) — car-edit-owner-refresh.spec.js never runs against Test/Production CI, only Local/Dev
- WIP: [#2028](https://github.com/elan-registry/registry/issues/2028) — tech-debt: pre-push integration gate always runs the full suite, even for small/scoped diffs
