// Coverage for usersc/user_settings.php (issue #1253).
//
// This is the ElanRegistry-customized version of the user settings page —
// not users/user_settings.php, the upstream UserSpice version. It differs
// from upstream by adding a Location Picker widget and using a literal
// "Update your user settings" heading (upstream drives its heading off a
// language string and includes a forceReauth() step-up that could redirect
// a fresh test session).

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

test.describe('User settings page (customized) — #1253', () => {

  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
  });

  test('renders the settings form with expected fields', async ({ page }) => {
    await page.goto('usersc/user_settings.php', { waitUntil: 'domcontentloaded' });

    const currentUrl = page.url();
    test.skip(
      currentUrl.includes('login') || currentUrl.includes('Please Log In'),
      'Session not established locally — skipping user settings assertions'
    );

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

  // #1512: the picker's select path on User Settings, which loads the same
  // location-picker.min.js as the join form. The search is stubbed.
  test('selecting a stubbed search result fills the hidden location fields', async ({ page }) => {
    const rome = {
      city: 'Rome', state: 'Lazio', country: 'Italy',
      lat: '41.8933203', lon: '12.4829321', display: 'Rome, Lazio, Italy',
    };
    await page.route('**/app/api/shared/location-search.php', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, results: [rome] }),
      });
    });
    await page.goto('usersc/user_settings.php', { waitUntil: 'domcontentloaded' });
    test.skip(
      page.url().includes('login') || page.url().includes('Please Log In'),
      'Session not established locally — skipping user settings assertions'
    );

    const input = page.locator('#location-picker-settings-input');
    await input.fill('Rome');
    const option = page.locator('#location-picker-settings-results [role="option"]');
    await expect(option).toHaveCount(1);
    await option.click();

    await expect(input).toHaveValue(rome.display);
    await expect(page.locator('#location-picker-settings-lat')).toHaveValue(rome.lat);
    await expect(page.locator('#location-picker-settings-lon')).toHaveValue(rome.lon);
    await expect(page.locator('#location-picker-settings-city')).toHaveValue(rome.city);
    await expect(page.locator('#location-picker-settings-country')).toHaveValue(rome.country);
  });

});
