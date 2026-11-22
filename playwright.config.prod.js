// Load .env.local first — E2E_TEST_*/E2E_PROD_* credentials live there,
// and any spec collected under this config that reads process.env.E2E_*
// needs them present the same way playwright.config.js/.dev.js already do.
require("dotenv").config({ path: ".env.local" });
const { defineConfig, devices } = require("@playwright/test");
const path = require("path");
const fs = require("fs");

// Selects the auth file that tests/playwright/e2e/auth-staleness-admin.setup.js
// checks. Must be set before Playwright collects that test file.
process.env.E2E_AUTH_TIER = "prod";

// Only the admin project needs a saved session (#2035). It is registered
// only when its auth file exists.
const authFileAdmin = path.join(__dirname, "tests/playwright/.auth/user-prod-admin.json");
const hasAuthFileAdmin = fs.existsSync(authFileAdmin);

module.exports = defineConfig({
  testDir: "./tests/playwright/e2e",
  timeout: 80000,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [["html", { outputFolder: "playwright-report-e2e" }]],

  use: {
    baseURL: "https://elanregistry.org",
    trace: "retain-on-failure",
    screenshot: "only-on-failure"
  },

  projects: [
    {
      name: "not-logged-in",
      testMatch: /.*not-logged-in\.spec\.js/,
      use: { ...devices["Desktop Chrome"] }
    },
    // check-auth-admin always runs — it's what fails loudly when the admin auth
    // file is missing (assertAuthStillValid's own check). Only its dependent
    // "admin" project (which needs a real storageState to load) is conditional
    // on the file existing; gating check-auth-admin itself on the same
    // condition would silently skip the exact failure it exists to report.
    {
      name: "check-auth-admin",
      testMatch: /(?:^|\/)auth-staleness-admin\.setup\.js$/,
      use: { ...devices["Desktop Chrome"] }
    },
    ...(hasAuthFileAdmin
      ? [
          {
            name: "admin",
            testMatch: /(?:^|\/)(admin|factory-registry-link)\.spec\.js$/,
            dependencies: ["check-auth-admin"],
            use: {
              ...devices["Desktop Chrome"],
              // Use saved authentication state
              storageState: authFileAdmin
            }
          }
        ]
      : [])
  ]
});
