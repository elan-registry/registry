// playwright.config.js
require('dotenv').config({ path: '.env.local' });
const { defineConfig, devices } = require('@playwright/test');
const path = require('path');

// storageState paths are resolved once at config-load time, before any
// project runs — so if TEST_USERNAME/TEST_PASSWORD are unset, auth.setup.js
// will skip (leaving no file) and every `logged-in` test would fail with
// ENOENT instead of skipping. Checking the credentials directly here (not
// just file existence, which can't self-heal on a fresh checkout — the file
// legitimately doesn't exist yet until `setup` first runs successfully)
// lets the project include storageState only when auth.setup.js is actually
// going to produce or already has produced it.
const authFile = path.join(__dirname, 'tests/playwright/.auth/user.json');
const hasCredentials = !!(process.env.TEST_USERNAME && process.env.TEST_PASSWORD);

/**
 * @see https://playwright.dev/docs/test-configuration
 */
module.exports = defineConfig({
  testDir: './tests/playwright',
  /* Run tests in files in parallel */
  fullyParallel: true,
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  /* Retry on CI only */
  retries: process.env.CI ? 2 : 0,
  /* Opt out of parallel tests on CI. */
  workers: process.env.CI ? 1 : undefined,
  /* Reporter to use. See https://playwright.dev/docs/test-reporters */
  reporter: 'html',
  /* Shared settings for all the projects below. See https://playwright.dev/docs/api/class-testoptions. */
  use: {
    /* Base URL to use in actions like `await page.goto('/')`. */
    /* Trailing slash is required — goto('') resolves to baseURL; without it the path collapses. */
    baseURL: 'http://localhost:9999/ElanRegistry/Registry/',

    /* Collect trace when retrying the failed test. See https://playwright.dev/docs/trace-viewer */
    trace: 'on-first-retry',
    
    /* Take screenshot on failure */
    screenshot: 'only-on-failure',
    
    /* Record video on failure */
    video: 'retain-on-failure',
  },

  /* Configure projects for major browsers */
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
      testIgnore: ['**/e2e/**', '**/mobile-responsive.spec.js'],
    },
    {
      name: 'Mobile Chrome',
      use: { ...devices['iPhone SE'] },
      testMatch: '**/mobile-responsive.spec.js',
      testIgnore: '**/e2e/**',
    },
    {
      name: 'setup',
      testMatch: /(?:^|\/)e2e\/.*\.setup\.js$/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      // NOTE: this is an exact-filename allowlist, not a directory/suffix
      // match — deliberately narrow so it can't accidentally also match
      // not-logged-in.spec.js (see #1781's regex-anchoring fix). The
      // trade-off: any NEW spec file added under e2e/ that needs an
      // authenticated session must be added to this alternation explicitly,
      // or it will be silently unreachable (chromium/Mobile Chrome both
      // testIgnore e2e/** entirely) — the same failure mode #1781 exists to
      // fix. When adding such a file, add it here too.
      name: 'logged-in',
      testMatch: /(?:^|\/)(logged-in|factory-registry-link|car-edit-owner-refresh)\.spec\.js$/,
      dependencies: ['setup'],
      use: {
        ...devices['Desktop Chrome'],
        // Only set storageState when credentials are actually configured —
        // otherwise auth.setup.js will skip (no file produced) and every
        // test here would fail on ENOENT instead of running unauthenticated
        // long enough to hit real (expected) failures. Without credentials,
        // these tests still execute against an anonymous session rather
        // than being skipped individually — acceptable here since the
        // project-level goal is "don't hard-fail on missing local setup,"
        // not "every test must self-skip."
        ...(hasCredentials ? { storageState: authFile } : {}),
      },
    },
  ],

  /* Run your local dev server before starting the tests */
  // webServer: {
  //   command: 'npm run start',
  //   url: 'http://127.0.0.1:3000',
  //   reuseExistingServer: !process.env.CI,
  // },
});