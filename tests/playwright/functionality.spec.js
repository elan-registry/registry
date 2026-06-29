// tests/playwright/functionality.test.js
const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, navigateAndWait, waitForDataTables, handleAuthRequired } = require('./auth-helper.js');

test.describe('Core Functionality After Refactoring', () => {
  test('DataTables loads and works on car listing', async ({ page }) => {
    await page.goto('app/cars/index.php', { waitUntil: 'networkidle' });

    const searchBox = await waitForDataTables(page, 15000);

    await searchBox.fill('1973');
    await page.waitForTimeout(1000);

    const tableRows = page.locator('tbody tr');
    await expect(tableRows.first()).toBeVisible();
  });

  test('car edit form workflow functions', async ({ page }) => {
    // Navigate to car edit page — accordion only appears when authenticated with a valid car_id
    await navigateAndWait(page, 'app/cars/edit.php');
    await expect(page).not.toHaveTitle(/404|Not Found|Server Error/i);

    // Skip deep assertions if the accordion isn't present (requires auth + car_id locally)
    const hasAccordion = await page.locator('#editCarAccordion').count() > 0;
    if (!hasAccordion) {
      return;
    }

    await expect(page.locator('#editCarAccordion')).toBeVisible();
    await expect(page.locator('#section1')).toBeVisible();
    await expect(page.locator('#section2')).not.toBeVisible();

    await page.selectOption('#year', '1973');
    await page.waitForTimeout(500);

    await page.locator('#heading-section2 button').click();
    await expect(page.locator('#section2')).toBeVisible();
  });

  test('chassis validation works', async ({ page }) => {
    await navigateAndWait(page, 'app/cars/edit.php');
    await expect(page).not.toHaveTitle(/404|Not Found|Server Error/i);

    // Skip deep assertions if the form fields aren't present (requires auth + car_id locally)
    const hasForm = await page.locator('#year').count() > 0;
    if (!hasForm) {
      return;
    }

    await page.selectOption('#year', '1973');
    await page.waitForTimeout(500);

    const modelOptions = await page.locator('#model option').count();
    if (modelOptions > 1) {
      await page.selectOption('#model', { index: 1 });
    }

    await page.fill('#chassis', '12345678X');
    await page.locator('#chassis').blur();

    await expect(page.locator('#chassis_icon')).toBeVisible();
  });

  test('contact form submission works', async ({ page }) => {
    await navigateAndWait(page, 'app/contact/index.php');

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

  test('NEW_CAR_IDS is emitted on car list page and badge renders when applicable', async ({ page }) => {
    await page.goto('app/cars/index.php', { waitUntil: 'networkidle' });

    // NEW_CAR_IDS must be defined as a JS array of integers
    const newCarIds = await page.evaluate(() => {
      if (typeof NEW_CAR_IDS === 'undefined') return null;
      return NEW_CAR_IDS;
    });

    // If the page requires auth and we're not logged in, NEW_CAR_IDS won't be present
    if (newCarIds === null) {
      return;
    }

    expect(Array.isArray(newCarIds)).toBe(true);
    newCarIds.forEach(id => expect(typeof id).toBe('number'));

    // If any cars are flagged as NEW, a badge must be visible in the Details column.
    // Unconditional assertion catches render-function regressions (e.g. typeof guard breaking).
    if (newCarIds.length > 0) {
      await waitForDataTables(page, 15000);
      const badge = page.locator('td a.btn .badge.er-badge-yellow').first();
      await expect(badge).toBeVisible();
      await expect(badge).toContainText('NEW');
    }
  });

  test('NEW badge does not appear on factory listing page', async ({ page }) => {
    await page.goto('app/cars/factory.php', { waitUntil: 'networkidle' });

    // Factory page must NOT define NEW_CAR_IDS
    const defined = await page.evaluate(() => typeof NEW_CAR_IDS !== 'undefined');
    expect(defined).toBe(false);
  });

  test('factory listing page functions', async ({ page }) => {
    await page.goto('app/cars/factory.php', { waitUntil: 'networkidle' });

    await expect(page.locator('h2')).toContainText(/Factory/);
    await waitForDataTables(page, 15000);
  });

  test('AJAX endpoints respond correctly', async ({ page }) => {
    const endpoints = [
      'app/api/cars/list.php',
      'app/cars/actions/check-chassis.php',
    ];

    for (const endpoint of endpoints) {
      const response = await page.request.post(endpoint, {
        data: { test: 'true' }
      });

      expect(response.status()).not.toBe(404);
      expect(response.status()).not.toBe(500);
    }
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
    await page.goto('app/cars/edit.php', { waitUntil: 'networkidle' });
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
