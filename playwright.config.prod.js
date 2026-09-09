const { defineConfig, devices } = require("@playwright/test");
const path = require("path");
const fs = require("fs");

// Selects the auth file / setup script tests/playwright/e2e/auth-staleness.setup.js
// and auth-staleness-admin.setup.js check against. Must be set before
// Playwright collects those test files.
process.env.E2E_AUTH_TIER = "prod";

// Check PROD auth files independently per role (#2035) — either can be
// present without the other (e.g. only the admin auth file was
// regenerated), so each gates only its own check-auth/project pair.
const authFileAdmin = path.join(__dirname, "tests/playwright/.auth/user-prod-admin.json");
const authFileNonAdmin = path.join(__dirname, "tests/playwright/.auth/user-prod-nonadmin.json");
const hasAuthFileAdmin = fs.existsSync(authFileAdmin);
const hasAuthFileNonAdmin = fs.existsSync(authFileNonAdmin);

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
    // Only include check-auth-admin/admin projects if the admin auth file exists
    ...(hasAuthFileAdmin
      ? [
          {
            name: "check-auth-admin",
            testMatch: /(?:^|\/)auth-staleness-admin\.setup\.js$/,
            use: { ...devices["Desktop Chrome"] }
          },
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
    // Only include check-auth/logged-in projects if the non-admin auth file exists
    ...(hasAuthFileNonAdmin
      ? [
          {
            name: "check-auth",
            testMatch: /(?:^|\/)auth-staleness\.setup\.js$/,
            use: { ...devices["Desktop Chrome"] }
          },
          {
            name: "logged-in",
            // No spec targets this non-admin tier yet — infrastructure only,
            // same as Dev's logged-in-non-admin project (#2035). Add specs
            // here as they're written.
            testMatch: /(?:^|\/)__none__\.spec\.js$/,
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
