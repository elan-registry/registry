// tests/playwright/footer-version.test.js
const { test, expect } = require('@playwright/test');
const { navigateAndWait } = require('./auth-helper.js');

/**
 * Smoke test for issue #876 — confirm the public footer displays a short
 * version tag (e.g. "v2.24.0") visible to anonymous visitors.
 *
 * The version is injected by usersc/includes/footer.php into the #footer
 * link row alongside the Privacy Policy link. We assert a semver-shaped
 * string is present in the footer DOM after page load.
 */
test.describe('Public footer version display', () => {
  test('homepage footer contains a version tag', async ({ page }) => {
    await navigateAndWait(page, '/index.php');
    await page.waitForLoadState('networkidle');

    const footer = page.locator('#footer');
    await expect(footer).toBeVisible({ timeout: 10000 });
    await expect(footer).toContainText(/v\d+\.\d+\.\d+/, { timeout: 10000 });
  });
});
