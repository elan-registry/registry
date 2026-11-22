// playwright.config.dev.js
require('dotenv').config({ path: '.env.local' });
const { defineConfig, devices } = require('@playwright/test');
const path = require('path');

// Distinct from playwright.config.js's user.json — this config runs against
// the local Docker site, not the deployed site, so sharing that file would
// let a dev run silently overwrite the Local storageState. Test/Prod use their
// own user-<tier>-admin.json files, populated by
// scripts/playwright-auth-setup.js (see docs/testing/PLAYWRIGHT_E2E.md), so
// there's no collision with those either.
const authFile = path.join(__dirname, 'tests/playwright/.auth/user-dev.json');
const hasCredentials = !!(process.env.E2E_DEV_ADMIN_USERNAME && process.env.E2E_DEV_ADMIN_PASSWORD);

/**
 * @see https://playwright.dev/docs/test-configuration
 */
module.exports = defineConfig({
  testDir: './tests/playwright/e2e',
  /* Provisions the shared PLAYWRIGHT_BASE_URL fallback used by `use.baseURL` below. */
  globalSetup: require.resolve('./tests/playwright/global-setup.js'),
  timeout: 80000,
  /* Run tests in files in parallel */
  fullyParallel: true,
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  /* Retry on CI only */
  retries: process.env.CI ? 2 : 0,
  /* Opt out of parallel tests on CI. */
  workers: process.env.CI ? 1 : undefined,
  /* Reporter to use. See https://playwright.dev/docs/test-reporters */
  reporter: [['html', { outputFolder: 'playwright-report-e2e-dev' }]],
  /* Shared settings for all the projects below. See https://playwright.dev/docs/api/class-testoptions. */
  use: {
    /* Base URL to use in actions like `await page.goto('/')`. */
    baseURL: require('./tests/playwright/base-url.js'),

    /* Collect trace when retrying the failed test. See https://playwright.dev/docs/trace-viewer */
    trace: 'retain-on-failure',

    /* Take screenshot on failure */
    screenshot: 'only-on-failure',

    /* Record video on failure — cheap locally, and useful given #2011's
       local-login flake. */
    video: 'retain-on-failure',
  },

  /* Configure projects for major browsers */
  projects: [
    {
      name: 'not-logged-in',
      testMatch: /.*not-logged-in\.spec\.js/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      // Exact-filename allowlist, the same convention that playwright.config.js
      // uses for its own 'setup' project. A loose pattern would also match the
      // other *.setup.js files in this directory.
      name: 'setup',
      testMatch: /(?:^|\/)auth-dev\.setup\.js$/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      // Kept in sync with playwright.config.js's own 'admin' alternation
      // (car-edit-owner-refresh included) so Dev is a strict superset of what
      // Local covers for authenticated e2e specs.
      name: 'admin',
      testMatch: /(?:^|\/)(admin|factory-registry-link|car-edit-owner-refresh|car-edit-workflow)\.spec\.js$/,
      dependencies: ['setup'],
      use: {
        ...devices['Desktop Chrome'],
        ...(hasCredentials ? { storageState: authFile } : {}),
      },
    },
  ],
});
