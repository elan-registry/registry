// playwright.config.dev.js
require('dotenv').config({ path: '.env.local' });
const { defineConfig, devices } = require('@playwright/test');
const path = require('path');

// Distinct from playwright.config.js's user.json — this config runs against
// local MAMP, not the deployed site, so sharing that file would let a dev
// run silently overwrite the Local storageState. Test/Prod use their own
// user-<tier>-<role>.json files, populated by scripts/playwright-auth-setup.js
// (see docs/testing/PLAYWRIGHT_E2E.md), so there's no collision with those either.
const authFile = path.join(__dirname, 'tests/playwright/.auth/user-dev.json');
const authFileNonAdmin = path.join(__dirname, 'tests/playwright/.auth/user-dev-non-admin.json');
const hasCredentials = !!(process.env.E2E_DEV_ADMIN_USERNAME && process.env.E2E_DEV_ADMIN_PASSWORD);
const hasCredentialsNonAdmin = !!(process.env.E2E_DEV_NONADMIN_USERNAME && process.env.E2E_DEV_NONADMIN_PASSWORD);

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
      // Exact-filename allowlist so this doesn't also match auth-non-admin.setup.js
      // below — same convention playwright.config.js uses for its own 'setup'
      // project (tightened there for the same reason when this file was added,
      // see playwright.config.js).
      name: 'setup',
      testMatch: /(?:^|\/)auth-dev\.setup\.js$/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'setup-non-admin',
      testMatch: /(?:^|\/)auth-non-admin\.setup\.js$/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      // NOTE: when adding a spec that needs the non-admin session, add it to
      // 'logged-in-non-admin's testMatch below too if it should ALSO run here.
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
    {
      name: 'logged-in-non-admin',
      // Add a spec's filename to this testMatch to opt it into the non-admin
      // session (mirrors the allowlist convention on 'admin' above). No
      // npm script runs this project by default until a spec does — see
      // package.json's test:e2e:dev family.
      testMatch: /(?:^|\/)__none__\.spec\.js$/,
      dependencies: ['setup-non-admin'],
      use: {
        ...devices['Desktop Chrome'],
        ...(hasCredentialsNonAdmin ? { storageState: authFileNonAdmin } : {}),
      },
    },
  ],
});
