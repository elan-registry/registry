const path = require('path');
const { test } = require('@playwright/test');
const { assertAuthStillValid } = require('./auth-staleness-check');
const { resolveTierConfig } = require('./auth-staleness-tier');

// E2E_AUTH_TIER is set by playwright.config.test.js / playwright.config.prod.js
// before this file is collected. resolveTierConfig throws on an unset/misspelled
// tier rather than defaulting to prod.
//
// resolveTierConfig always returns the admin files: admin is the only role
// with a Test/Prod project.
const { authFile, setupScriptPath } = resolveTierConfig(
  process.env.E2E_AUTH_TIER,
  path.join(__dirname, '..', '.auth')
);

test('saved auth session is still valid (admin)', async ({ browser, baseURL }) => {
  await assertAuthStillValid(browser, { authFile, setupScriptPath, baseURL });
});
