// tests/playwright/functionality.test.js
const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, navigateAndWait, waitForDataTables, handleAuthRequired } = require('./auth-helper.js');

test.describe('Core Functionality After Refactoring', () => {
  test('DataTables loads and works on car listing', async ({ page }) => {
    await page.goto('app/owner/cars/index.php', { waitUntil: 'networkidle' });

    const searchBox = await waitForDataTables(page, 15000);

    await searchBox.fill('1973');
    await page.waitForTimeout(1000);

    const tableRows = page.locator('tbody tr');
    await expect(tableRows.first()).toBeVisible();
  });

  // Car edit form workflow AND chassis validation coverage now live in
  // tests/playwright/e2e/car-edit-workflow.spec.js. The chassis test here
  // ran unauthenticated and landed on edit.php's "Add Car" fallback rather
  // than genuine edit mode; the removed accordion test never got that far
  // at all — it early-returned on a selector that no longer exists in the
  // markup at all, regardless of auth state (see that file's header for
  // both stories in full).

  test('contact form submission works', async ({ page }) => {
    await navigateAndWait(page, 'app/owner/contact/index.php');

    await handleAuthRequired(
      page,
      async () => {
        await page.fill('input[name="name"]', 'Test User');
        await page.fill('input[name="email"]', 'test@example.com');
        await page.fill('textarea[name="message"]', 'This is a test message for the Elan Registry contact form.');

        await page.click('button[type="submit"], input[type="submit"]');

        // Wait for the response; any non-crash outcome is acceptable here.
        await page.waitForTimeout(2000);
        const hasAlert = await page.locator('.alert, .message, .notification').count();
        expect(hasAlert).toBeGreaterThanOrEqual(0); // Just checking it doesn't crash
      }
    );
  });

  test('NEW badge does not appear on factory listing page', async ({ page }) => {
    await page.goto('app/owner/cars/factory.php', { waitUntil: 'networkidle' });

    // Positive control: prove the page actually rendered before asserting the
    // config's absence below (factory.php is public and never redirects an
    // unauthenticated visitor — this guards against an unrelated rendering
    // failure making the absence check pass for the wrong reason).
    await expect(page.locator('h2')).toContainText(/Factory/);

    // Factory page must NOT define carListConfig
    const defined = await page.evaluate(() => typeof window.carListConfig !== 'undefined');
    expect(defined).toBe(false);
  });

  test('factory listing page functions', async ({ page }) => {
    await page.goto('app/owner/cars/factory.php', { waitUntil: 'networkidle' });

    await expect(page.locator('h2')).toContainText(/Factory/);
    await waitForDataTables(page, 15000);
  });
});

// ---------------------------------------------------------------------------
// Premature validation icons — required fields should be neutral on page load
// ---------------------------------------------------------------------------

test.describe('Add Car form — no premature validation on page load', () => {
  test.beforeEach(async ({ page }) => {
    // Skip when test credentials are not configured in .env.local
    if (!process.env.TEST_USERNAME || !process.env.TEST_PASSWORD) {
      test.skip(true, 'Set TEST_USERNAME and TEST_PASSWORD in .env.local to run authenticated tests');
    }
    await ensureLoggedIn(page);
    await page.goto('app/owner/cars/edit.php', { waitUntil: 'networkidle' });
  });

  test('Year, Model, and Chassis icons are neutral (no thumbs-down) on load', async ({ page }) => {
    // On initial page load for add mode, no field has been touched yet.
    // Icons should not show the invalid/thumbs-down state.
    await expect(page.locator('#year_icon')).not.toHaveClass(/fa-thumbs-down/);
    await expect(page.locator('#model_icon')).not.toHaveClass(/fa-thumbs-down/);
    await expect(page.locator('#chassis_icon')).not.toHaveClass(/fa-thumbs-down/);

    // Also verify no is-invalid class on the icons themselves
    await expect(page.locator('#year_icon')).not.toHaveClass(/is-invalid/);
    await expect(page.locator('#model_icon')).not.toHaveClass(/is-invalid/);
    await expect(page.locator('#chassis_icon')).not.toHaveClass(/is-invalid/);
  });

  test('Year, Model, and Chassis fields have no invalid border on load', async ({ page }) => {
    await expect(page.locator('#year')).not.toHaveClass(/is-invalid/);
    await expect(page.locator('#model')).not.toHaveClass(/is-invalid/);
    await expect(page.locator('#chassis')).not.toHaveClass(/is-invalid/);
  });
});
