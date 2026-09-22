// Load .env.local first — E2E_TEST_*/E2E_PROD_* credentials live there,
// and any spec collected under this config that reads process.env.E2E_*
// (e.g. car-edit-workflow.spec.js, car-edit-owner-refresh.spec.js) needs
// them present the same way playwright.config.js/.dev.js already do.
require("dotenv").config({ path: ".env.local" });
const { defineConfig, devices } = require("@playwright/test");
const path = require("path");
const fs = require("fs");

// Selects the auth file / setup script tests/playwright/e2e/auth-staleness.setup.js
// and auth-staleness-admin.setup.js check against. Must be set before
// Playwright collects those test files.
process.env.E2E_AUTH_TIER = "test";

// Check TEST environment auth files independently per role (#2035) — either
// can be present without the other (e.g. only the admin auth file was
// regenerated), so each gates only its own check-auth/project pair.
const authFileAdmin = path.join(__dirname, "tests/playwright/.auth/user-test-admin.json");
const authFileNonAdmin = path.join(__dirname, "tests/playwright/.auth/user-test-nonadmin.json");
const hasAuthFileAdmin = fs.existsSync(authFileAdmin);
const hasAuthFileNonAdmin = fs.existsSync(authFileNonAdmin);

module.exports = defineConfig({
  testDir: "./tests/playwright/e2e",
  timeout: 80000,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [["html", { outputFolder: "playwright-report-e2e-test" }]],

  use: {
    baseURL: "https://test.elanregistry.org",
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
      : []),
    // Same reasoning as check-auth-admin above — always registered so a
    // missing non-admin auth file fails loudly instead of being silently
    // unregistered along with its dependent project.
    {
      name: "check-auth",
      testMatch: /(?:^|\/)auth-staleness\.setup\.js$/,
      use: { ...devices["Desktop Chrome"] }
    },
    ...(hasAuthFileNonAdmin
      ? [
          {
            name: "logged-in",
            // ajax-endpoints-non-admin.spec.js covers requireAdminAjax()'s
            // isRegistryAdmin() branch — same as Dev's logged-in-non-admin
            // project (#2035, #2068). Add further specs here as they're written.
            testMatch: /(?:^|\/)ajax-endpoints-non-admin\.spec\.js$/,
            dependencies: ["check-auth"],
            use: {
              ...devices["Desktop Chrome"],
              // Use saved authentication state
              storageState: authFileNonAdmin
            }
          }
        ]
      : [])
  ]
});
