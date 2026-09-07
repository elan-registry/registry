// tests/playwright/e2e/auth-non-admin.setup.js

const fs = require('fs');
const { test } = require('@playwright/test');
const { login } = require('../auth-helper');

const authFile = 'tests/playwright/.auth/user-dev-non-admin.json';

test('authenticate non-admin', async ({ page }) => {
  if (!process.env.TEST_USERNAME2 || !process.env.TEST_PASSWORD2) {
    // Remove any stale storageState from a prior run before skipping —
    // Playwright's `dependencies: ['setup-non-admin']` does not skip the
    // dependent `logged-in-non-admin` project just because this setup test
    // skipped, so without this that project would silently load a leftover
    // auth file and run under a possibly-expired session instead of skipping.
    fs.rmSync(authFile, { force: true });
    test.skip(true, 'Set TEST_USERNAME2 and TEST_PASSWORD2 in .env.local to run non-admin authenticated tests');
  }

  await login(page, process.env.TEST_USERNAME2, process.env.TEST_PASSWORD2);

  await page.context().storageState({ path: authFile });
});
