const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('../auth-helper.js');

/**
 * Regression guard for the ownership check on the updateCar AJAX endpoint.
 *
 * Before #970, any authenticated user could update any car by POSTing a
 * `car_id` they don't own to `app/api/cars/save.php?action=updateCar`.
 * The guard added in #970 mirrors the existing fetchImages/removeImages pattern:
 * a non-owner receives HTTP 403 JSON; admins (group 2/3) are unaffected.
 *
 * @group security
 * @group ownership
 */

const ACTIONS_ENDPOINT = 'app/api/cars/save.php';
const FORM_PAGE = 'app/cars/edit.php';
const NONEXISTENT_CAR_ID = 999999;

async function getCsrfFromForm(page) {
  await page.goto(FORM_PAGE, { waitUntil: 'domcontentloaded' });
  try {
    const token = await page.inputValue('#csrf', { timeout: 3000 });
    return token || null;
  } catch {
    return null;
  }
}

test.describe('Car Update Endpoint — Ownership Guard', () => {
  test.describe('authenticated user, non-existent car', () => {
    let csrf;

    test.beforeEach(async ({ page }) => {
      if (!process.env.TEST_USERNAME || !process.env.TEST_PASSWORD) {
        test.skip(true, 'Set TEST_USERNAME and TEST_PASSWORD in .env.local to run authenticated tests');
      }
      await ensureLoggedIn(page);
      const token = await getCsrfFromForm(page);
      expect(token, 'CSRF token must be present on the form page after login').toBeTruthy();
      csrf = token;
    });

    test('updateCar: returns 403 JSON for non-existent car_id', async ({ page }) => {
      const response = await page.request.post(ACTIONS_ENDPOINT, {
        form: { action: 'updateCar', car_id: String(NONEXISTENT_CAR_ID), csrf },
      });
      expect(response.status()).toBe(403);
      expect(await response.json()).toMatchObject({ success: false });
    });
  });

  // No CSRF token → token_error.php returns HTML (HTTP 200), not a success JSON envelope.
  test('updateCar: request without CSRF is rejected', async ({ page }) => {
    const response = await page.request.post(ACTIONS_ENDPOINT, {
      form: { action: 'updateCar', car_id: String(NONEXISTENT_CAR_ID), csrf: '' },
    });
    const text = await response.text();
    expect(text.length, 'Response body must not be empty').toBeGreaterThan(0);
    let body = null;
    try {
      body = JSON.parse(text);
    } catch {
      // HTML response from token_error.php — acceptable.
    }
    if (body && typeof body === 'object' && 'success' in body) {
      expect(body.success).not.toBe(true);
    } else {
      expect(text).not.toMatch(/"success"\s*:\s*true/);
    }
  });

});
