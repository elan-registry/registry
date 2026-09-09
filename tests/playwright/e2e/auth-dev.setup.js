const fs = require('fs');
const { test } = require('@playwright/test');
const { login } = require('../auth-helper');

const authFile = 'tests/playwright/.auth/user-dev.json';

test('authenticate', async ({ page }) => {
  if (!process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD) {
    // See tests/playwright/e2e/auth.setup.js for why this removal must happen
    // before test.skip — Playwright's `dependencies: ['setup']` does not skip
    // the dependent `logged-in` project just because this setup test skipped.
    fs.rmSync(authFile, { force: true });
    test.skip(true, 'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests');
  }

  await login(page, process.env.E2E_DEV_ADMIN_USERNAME, process.env.E2E_DEV_ADMIN_PASSWORD);

  await page.context().storageState({ path: authFile });
});
