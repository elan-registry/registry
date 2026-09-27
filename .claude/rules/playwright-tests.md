---
paths:
  - "tests/playwright/**"
  - "playwright.config*"
  - "app/**/*.php"
  - "docs/**/*.php"
  - "app/assets/js/**"
---

# Playwright test maintenance

When you add, move, remove, or rename any page, update tests **in the same PR**:

- **Public pages** → add or update an e2e smoke test in
  `tests/playwright/e2e/not-logged-in.spec.js`.
- **Owner/authenticated pages** → add or update a local Playwright test in
  `tests/playwright/`.
- **Deleted or moved pages** → update any test that uses the old path. A
  stale path can test a 404 without failing.
- **Moved or renamed DOM elements, classes, and JS globals** → update every
  guard that depends on them **in the same PR**. A defensive guard (a bare
  `return` after `test.skip('reason')`, or `if (await x.count() > 0) { assert }`
  with no `else`) can hide a moved class or renamed global, so the test passes
  without an assertion and CI reports no failure (#1949, #1950). Assert
  directly when possible. Use a guard only when the environment requires it
  (missing local credentials or fixture data). Then use the two-argument form
  `test.skip(condition, reason)`, with `reason` set to the cause, not the
  symptom. The `localRules/require-skip-reason` ESLint rule enforces two
  arguments for `test.skip(...)` and `testInfo.skip(...)`.

Local admin e2e tests (`tests/playwright/e2e/admin.spec.js`,
`factory-registry-link.spec.js`) authenticate with
`E2E_DEV_ADMIN_USERNAME`/`E2E_DEV_ADMIN_PASSWORD` in `.env.local`. If those are
unset, the auth setup step skips, but the admin tests still run
unauthenticated: tests that expect a logged-in session fail, and tests of
public pages such as `factory.php` pass.

Run `npm run test:e2e` to check public pages against production
(`playwright.config.prod.js`).
