const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, login, navigateAndWait, waitForDataTables } = require('./auth-helper.js');

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

  test('contact form rejects a one-character comment with a 422 and an error toast', async ({ page }) => {
    test.skip(
      !process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD,
      'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local: the contact page requires login'
    );
    await login(page);
    await navigateAndWait(page, 'app/owner/contact/index.php');

    // One character passes the HTML required check but fails the server's
    // two-character minimum, so the request reaches validation and no email
    // is sent.
    await page.getByRole('textbox', { name: 'Your Feedback' }).fill('x');

    const responsePromise = page.waitForResponse(
      (response) => response.url().includes('app/api/contact/send-feedback.php')
    );
    await page.getByRole('button', { name: 'Send Feedback' }).click();
    const response = await responsePromise;

    expect(response.status()).toBe(422);
    const body = await response.json();
    expect(body).toHaveProperty('success', false);
    expect(body.errors.comments).toBe('Please enter your feedback.');

    // contact-form.js shows the response message through window.usError().
    await expect(page.locator('.us-toast:has(.us-bar-danger) .toast-body')).toContainText('Validation failed');
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
    if (!process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD) {
      test.skip(true, 'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests');
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
