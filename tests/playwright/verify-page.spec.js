// users/verify.php with no query params renders the error view (#1253).
// The success path is not covered: it needs a user with a valid vericode.

const { test, expect } = require('@playwright/test');

test.describe('Verify page — unverified/error-view path (#1253)', () => {
  test('no query params renders the error view, not a raw PHP error or blank page', async ({ page }) => {
    await page.goto('users/verify.php', { waitUntil: 'domcontentloaded' });

    // VER_FAIL is a language string, so assert non-empty, not exact text.
    const heading = page.locator('h1');
    await expect(heading).toBeVisible();
    await expect(heading).not.toHaveText('');
  });
});
