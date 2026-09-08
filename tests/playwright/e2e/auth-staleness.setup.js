const path = require('path');
const { test } = require('@playwright/test');
const { assertAuthStillValid } = require('./auth-staleness-check');

// E2E_AUTH_TIER is set by playwright.config.test.js / playwright.config.prod.js
// before this test file is collected — config must set process.env before
// Playwright collects/runs the test file; this coupling is intentional and
// avoids a third near-duplicate setup file per tier.
const tier = process.env.E2E_AUTH_TIER; // 'test' | 'prod'

// Fail fast on an unset/misspelled tier rather than silently falling through
// to 'prod' — this file exists specifically to eliminate silent wrong-mode
// auth behavior, so defaulting to the more sensitive tier here would be
// exactly the failure mode it's meant to prevent.
if (tier !== 'test' && tier !== 'prod') {
  throw new Error(
    `auth-staleness.setup.js requires E2E_AUTH_TIER to be 'test' or 'prod' (got: ${tier ?? 'unset'}). ` +
    `It is set by playwright.config.test.js / playwright.config.prod.js.`
  );
}

const authFile = path.join(
  __dirname, '..', '.auth', tier === 'test' ? 'user-test.json' : 'user.json'
);
const setupScriptPath = tier === 'test'
  ? './scripts/playwright-auth-1password-test.sh'
  : './scripts/playwright-auth-1password.sh';

test('saved auth session is still valid', async ({ browser, baseURL }) => {
  await assertAuthStillValid(browser, { authFile, setupScriptPath, baseURL });
});
