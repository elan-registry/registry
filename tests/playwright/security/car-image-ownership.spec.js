const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('../auth-helper.js');

/**
 * E2E regression tests for the ownership guard on car image AJAX endpoints.
 *
 * The endpoints under test live in `app/api/cars/save.php`:
 * - action=fetchImages: returns the list of uploaded images for a car
 * - action=removeImages: deletes a single uploaded image from a car
 *
 * Both endpoints enforce ownership: a user may only operate on a car they
 * own, unless they hold admin permissions (group 2 or 3). When the guard
 * trips, the endpoint responds with HTTP 403 and a Pattern A JSON body
 * (`{success: false, message: "Unauthorized", ...}`).
 *
 * The endpoints also require a valid CSRF token. When the CSRF check fails,
 * `token_error.php` is included — it outputs HTML and calls `die()`. Because
 * no `http_response_code()` call is made in this path, the response retains
 * the default HTTP 200 status. The body is HTML, NOT a `{success: true}` JSON
 * envelope. Tests for this path assert that the response is not a successful
 * JSON envelope rather than asserting a specific status code.
 *
 * @group security
 * @group ownership
 */

const ACTIONS_ENDPOINT = 'app/api/cars/save.php';
const FORM_PAGE = 'app/owner/cars/edit.php';
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

test.describe('Car Image Endpoints — Ownership Guard', () => {
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

    test('fetchImages: returns 403 JSON', async ({ page }) => {
      const response = await page.request.post(ACTIONS_ENDPOINT, {
        form: { action: 'fetchImages', carID: String(NONEXISTENT_CAR_ID), csrf },
      });
      expect(response.status()).toBe(403);
      expect(await response.json()).toMatchObject({ success: false });
    });

    test('removeImages: returns 403 JSON', async ({ page }) => {
      const response = await page.request.post(ACTIONS_ENDPOINT, {
        form: { action: 'removeImages', carID: String(NONEXISTENT_CAR_ID), file: 'test.jpg', csrf },
      });
      expect(response.status()).toBe(403);
      expect(await response.json()).toMatchObject({ success: false });
    });
  });

  // No CSRF token → token_error.php returns HTML (HTTP 200), not a success JSON envelope.
  for (const { action, extra } of [
    { action: 'fetchImages', extra: {} },
    { action: 'removeImages', extra: { file: 'test.jpg' } },
  ]) {
    test(`${action}: request without CSRF is rejected`, async ({ page }) => {
      const response = await page.request.post(ACTIONS_ENDPOINT, {
        form: { action, carID: String(NONEXISTENT_CAR_ID), csrf: '', ...extra },
      });
      const text = await response.text();
      expect(text.length, 'Response body must not be empty — server may have failed silently').toBeGreaterThan(0);
      let body = null;
      try {
        body = JSON.parse(text);
      } catch {
        // HTML response (token_error.php) — not JSON, which is fine.
      }
      if (body && typeof body === 'object' && 'success' in body) {
        expect(body.success).not.toBe(true);
      } else {
        expect(text).not.toMatch(/"success"\s*:\s*true/);
      }
    });
  }

});
