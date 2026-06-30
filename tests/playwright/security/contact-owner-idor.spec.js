const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('../auth-helper.js');

/**
 * Regression guard for the IDOR check on the contact-owner AJAX endpoint.
 *
 * `app/api/contact/send-owner-email.php` must verify that the supplied
 * `to_user_id` actually owns the supplied `car_id` before sending a message.
 * A mismatched pair (a user who does not own the car) must receive HTTP 403
 * JSON rather than leaking the owner's contact details or delivering mail.
 *
 * @group security
 * @group ownership
 */

const ENDPOINT = 'app/api/contact/send-owner-email.php';
const CSRF_FORM_PAGE = 'app/contact/index.php';
const NONEXISTENT_CAR_ID = 999999;
const WRONG_USER_ID = 999999;

async function getCsrfFromForm(page) {
  await page.goto(CSRF_FORM_PAGE, { waitUntil: 'domcontentloaded' });
  try {
    const token = await page.inputValue('input[name="csrf"]', { timeout: 3000 });
    return token || null;
  } catch {
    return null;
  }
}

test.describe('Contact Owner Endpoint — IDOR Guard', () => {
  test.describe('authenticated user, mismatched car/owner', () => {
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

    test('returns 403 JSON when to_user_id does not own car_id', async ({ page }) => {
      const response = await page.request.post(ENDPOINT, {
        form: {
          action: 'send_message',
          to_user_id: String(WRONG_USER_ID),
          car_id: String(NONEXISTENT_CAR_ID),
          message: 'Test message',
          csrf,
        },
      });
      expect(response.status()).toBe(403);
      expect(await response.json()).toMatchObject({ success: false });
    });
  });

  // No CSRF token → token_error.php returns HTML (HTTP 200) or the endpoint
  // returns 403 JSON. Either way it must not be a 500.
  test('request without CSRF is rejected, not a server error', async ({ page }) => {
    const response = await page.request.post(ENDPOINT, {
      form: {
        action: 'send_message',
        to_user_id: String(WRONG_USER_ID),
        car_id: String(NONEXISTENT_CAR_ID),
        message: 'Test message',
        csrf: '',
      },
    });
    expect(response.status()).not.toBe(500);
    const text = await response.text();
    expect(text.length, 'Response body must not be empty').toBeGreaterThan(0);
    let body = null;
    try {
      body = JSON.parse(text);
    } catch {
      // HTML response from token_error.php / securePage redirect — acceptable.
    }
    if (body && typeof body === 'object' && 'success' in body) {
      expect(body.success).not.toBe(true);
    } else {
      expect(text).not.toMatch(/"success"\s*:\s*true/);
    }
  });
});
