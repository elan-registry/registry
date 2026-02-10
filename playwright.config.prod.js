const { defineConfig, devices } = require("@playwright/test");
const path = require("path");
const fs = require("fs");

// Check if auth file exists
const authFile = path.join(__dirname, "tests/playwright/.auth/user.json");
const hasAuthFile = fs.existsSync(authFile);

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
    // Only include logged-in project if auth file exists
    ...(hasAuthFile
      ? [
          {
            name: "logged-in",
            testMatch: /.*logged-in\.spec\.js/,
            use: {
              ...devices["Desktop Chrome"],
              // Use saved authentication state
              storageState: authFile
            }
          }
        ]
      : [])
  ]
});
