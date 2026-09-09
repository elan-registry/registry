const path = require('path');
const { test } = require('@playwright/test');
const { assertAuthStillValid } = require('./auth-staleness-check');
const { resolveTierConfig } = require('./auth-staleness-tier');

// E2E_AUTH_TIER is set by playwright.config.test.js / playwright.config.prod.js
// before this file is collected. resolveTierConfig throws on an unset/misspelled
// tier rather than defaulting to prod.
//
// This file is hardcoded to the admin role; its sibling auth-staleness.setup.js
// is hardcoded to non-admin. Two files rather than a role parameter so each has
// its own fixed Playwright project.
const { authFile, setupScriptPath } = resolveTierConfig(
  process.env.E2E_AUTH_TIER,
  'admin',
  path.join(__dirname, '..', '.auth')
);

test('saved auth session is still valid (admin)', async ({ browser, baseURL }) => {
  await assertAuthStillValid(browser, { authFile, setupScriptPath, baseURL });
});
