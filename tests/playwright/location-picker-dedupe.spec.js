// tests/playwright/location-picker-dedupe.spec.js
//
// Regression tests for LocationPicker.filterAndRankResults()'s dedupe key
// (#1400). Exercises the function directly via page.evaluate() with fixed
// mock data — deterministic and fast, no live Photon/Nominatim dependency,
// unlike the integration-level check in tests/playwright/e2e/not-logged-in.spec.js.
//
// Requires the local Docker site. Default: http://localhost:$APP_HOST_PORT/ — see tests/playwright/base-url.js

const { test, expect } = require('@playwright/test');

test.describe('LocationPicker.filterAndRankResults() — dedupe key (#1400)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('users/join.php');
  });

  test('keeps same-named cities in different states as distinct results', async ({ page }) => {
    const result = await page.evaluate(() => {
      const picker = new LocationPicker({ containerId: 'location-picker-registration' });
      return picker.filterAndRankResults([
        { city: 'Springfield', state: 'Ohio', country: 'United States', display: 'Springfield, Ohio, United States' },
        { city: 'Springfield', state: 'Missouri', country: 'United States', display: 'Springfield, Missouri, United States' },
      ]);
    });

    expect(result.length).toBe(2);
  });

  test('collapses true duplicates (same city+state+country, different display text)', async ({ page }) => {
    const result = await page.evaluate(() => {
      const picker = new LocationPicker({ containerId: 'location-picker-registration' });
      return picker.filterAndRankResults([
        { city: 'Springfield', state: 'Ohio', country: 'United States', display: 'Springfield, Ohio, United States' },
        { city: 'Springfield', state: 'Ohio', country: 'United States', display: 'Springfield, OH, USA' },
      ]);
    });

    expect(result.length).toBe(1);
  });

  test('still collapses same-named cities that both lack a state (e.g. some international results)', async ({ page }) => {
    const result = await page.evaluate(() => {
      const picker = new LocationPicker({ containerId: 'location-picker-registration' });
      return picker.filterAndRankResults([
        { city: 'Monaco', state: '', country: 'Monaco', display: 'Monaco' },
        { city: 'Monaco', state: '', country: 'Monaco', display: 'Monaco, Monaco' },
      ]);
    });

    // Confirms the fix doesn't regress the pre-existing dedupe behavior for
    // locations where state is legitimately empty (not just US-specific).
    expect(result.length).toBe(1);
  });
});
