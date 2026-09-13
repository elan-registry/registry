// tests/playwright/e2e/auth.setup.js

const fs = require('fs');
const { test } = require('@playwright/test');
const { login } = require('../auth-helper');

const authFile = 'tests/playwright/.auth/user.json';

test('authenticate', async ({ page }) => {
  if (!process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD) {
    // Remove any stale storageState from a prior run before skipping —
    // Playwright's `dependencies: ['setup']` does not skip the dependent
    // `logged-in` project just because this setup test skipped, so without
    // this the logged-in project would silently load a leftover auth file
    // and run under a possibly-expired session instead of skipping.
    fs.rmSync(authFile, { force: true });
    test.skip(true, 'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests');
  }

  await login(page, process.env.E2E_DEV_ADMIN_USERNAME, process.env.E2E_DEV_ADMIN_PASSWORD);

  await page.context().storageState({ path: authFile });
});
