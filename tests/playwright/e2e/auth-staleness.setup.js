const path = require('path');
const { test } = require('@playwright/test');
const { assertAuthStillValid } = require('./auth-staleness-check');
const { resolveTierConfig } = require('./auth-staleness-tier');

// E2E_AUTH_TIER is set by playwright.config.test.js / playwright.config.prod.js
// before this test file is collected — config must set process.env before
// Playwright collects/runs the test file; this coupling is intentional and
// avoids a third near-duplicate setup file per tier. resolveTierConfig throws
// on an unset/misspelled tier rather than defaulting to the prod credentials.
const { authFile, setupScriptPath } = resolveTierConfig(
  process.env.E2E_AUTH_TIER,
  path.join(__dirname, '..', '.auth')
);

test('saved auth session is still valid', async ({ browser, baseURL }) => {
  await assertAuthStillValid(browser, { authFile, setupScriptPath, baseURL });
});
