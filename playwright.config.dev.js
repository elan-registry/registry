// playwright.config.dev.js
require('dotenv').config({ path: '.env.local' });
const { defineConfig, devices } = require('@playwright/test');
const path = require('path');

const authFile = path.join(__dirname, 'tests/playwright/.auth/user.json');
const authFileNonAdmin = path.join(__dirname, 'tests/playwright/.auth/user-dev-non-admin.json');
const hasCredentials = !!(process.env.TEST_USERNAME && process.env.TEST_PASSWORD);
const hasCredentialsNonAdmin = !!(process.env.TEST_USERNAME2 && process.env.TEST_PASSWORD2);

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
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:9999/ElanRegistry/Registry/',

    /* Collect trace when retrying the failed test. See https://playwright.dev/docs/trace-viewer */
    trace: 'retain-on-failure',

    /* Take screenshot on failure */
    screenshot: 'only-on-failure',
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
      // and 'logged-in' projects (which had to be tightened for the same reason
      // when auth-non-admin.setup.js was added, see playwright.config.js).
      name: 'setup',
      testMatch: /(?:^|\/)auth\.setup\.js$/,
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
      name: 'logged-in',
      testMatch: /(?:^|\/)(logged-in|factory-registry-link)\.spec\.js$/,
      dependencies: ['setup'],
      use: {
        ...devices['Desktop Chrome'],
        ...(hasCredentials ? { storageState: authFile } : {}),
      },
    },
    {
      name: 'logged-in-non-admin',
      // No spec targets this project yet — infra-only for #1858. A future
      // spec opts in by checking testInfo.project.name === 'logged-in-non-admin'
      // and adding its filename to this testMatch (mirrors the allowlist
      // convention on the 'logged-in' project above).
      testMatch: /(?:^|\/)__none__\.spec\.js$/,
      dependencies: ['setup-non-admin'],
      use: {
        ...devices['Desktop Chrome'],
        ...(hasCredentialsNonAdmin ? { storageState: authFileNonAdmin } : {}),
      },
    },
  ],
});
