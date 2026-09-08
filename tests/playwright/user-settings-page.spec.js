// tests/playwright/user-settings-page.spec.js
//
// Coverage for usersc/user_settings.php (issue #1253).
//
// This is the ElanRegistry-customized version of the user settings page —
// not users/user_settings.php, the upstream UserSpice version. It differs
// from upstream by adding a Location Picker widget and using a literal
// "Update your user settings" heading (upstream drives its heading off a
// language string and includes a forceReauth() step-up that could redirect
// a fresh test session).
//
// Requires local MAMP. Default: http://localhost:9999/ElanRegistry/Registry/ — override with PLAYWRIGHT_BASE_URL, see docs/development/ENVIRONMENT.md

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

test.describe('User settings page (customized) — #1253', () => {

  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
  });

  test('renders the settings form with expected fields', async ({ page }) => {
    await page.goto('usersc/user_settings.php', { waitUntil: 'domcontentloaded' });

    const currentUrl = page.url();
    if (currentUrl.includes('login') || currentUrl.includes('Please Log In')) {
      test.skip('Session not established locally — skipping user settings assertions');
      return;
    }

    expect(currentUrl).toContain('user_settings.php');

    await expect(page.locator('h1:has-text("Update your user settings")')).toBeVisible();

    // Form fields — id-based selectors match usersc/user_settings.php's rendered markup.
    await expect(page.locator('#username')).toBeVisible();
    await expect(page.locator('#fname')).toBeVisible();
    await expect(page.locator('#lname')).toBeVisible();
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#confemail')).toBeVisible();

    await expect(page.locator('input[type="submit"]')).toBeVisible();

    // Distinguishing feature vs. the upstream users/user_settings.php page.
    await expect(page.locator('#location-picker-settings')).toBeVisible();
  });

});
