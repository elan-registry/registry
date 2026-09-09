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
- **Test-env Playwright auth setup no longer hangs on Turnstile** ([#2014](https://github.com/elan-registry/registry/issues/2014)): `scripts/playwright-auth-setup-test.js` previously hung indefinitely (`page.waitForLoadState('networkidle')`) whenever a real Cloudflare Turnstile challenge blocked automated login. It now waits directly on the login form and fails fast with an actionable message if Turnstile is present — an automated browser cannot solve it, so this environment now needs Turnstile disabled before running the script (see `docs/testing/PLAYWRIGHT_E2E.md`).
- **Three Playwright tests that passed without executing their assertions now run for real** ([#1949](https://github.com/elan-registry/registry/issues/1949)): `ui-consistency.spec.js`'s DataTables responsiveness check was gated on a DataTables 1.x class name the project no longer ships (now waits for the actual current markup unconditionally). `functionality.spec.js`'s car-edit-form and chassis-validation tests both ran unauthenticated and never reached genuine edit mode — replaced with two real tests in a new file, `tests/playwright/e2e/car-edit-workflow.spec.js`, that authenticate, discover an owned car, and exercise the real year→model and chassis-validation flows (Local/Dev only for now; Test/Production enrollment deferred to #2045).

## Issues Resolved

- WIP: [#1648](https://github.com/elan-registry/registry/issues/1648) — test: no positive assertion that pdf-viewer.php's success branch renders the iframe with the correct document src
- WIP: [#1773](https://github.com/elan-registry/registry/issues/1773) — test: add Playwright HTTP coverage for process-user-details.php admin endpoint
- [#1935](https://github.com/elan-registry/registry/issues/1935) — test: Playwright auth harness has no failure detection — stale storageState runs anonymous, bad local creds hang on a generic timeout
- [#1949](https://github.com/elan-registry/registry/issues/1949) — test: Playwright tests that passed without running their assertions (stale DataTables selector, dead accordion markup, unauthenticated chassis-validation test) now run for real
- WIP: [#1950](https://github.com/elan-registry/registry/issues/1950) — test: guarded Playwright assertions silently stop testing when a DOM/JS contract moves
- [#2014](https://github.com/elan-registry/registry/issues/2014) — test-env Playwright auth setup script no longer hangs on Turnstile (car-edit-owner-refresh Test/Production enrollment itself moved to #2045, backlog — not part of this release)
- WIP: [#2044](https://github.com/elan-registry/registry/issues/2044) — spike: is a CI-runnable integration suite achievable without a UserSpice install in CI?
