// tests/playwright/contact-owner-page.spec.js
//
// Coverage for app/owner/contact/owner.php (issue #1585).
//
// Before #1585, this page called `$db->findById(...)` — a method that exists
// on the real \DB class but was never part of DatabaseInterface — which would
// have fataled in production. The fix replaced it with
// `$db->get('cars', [...])->results()` guarded by explicit `=== false` checks,
// redirecting to '/' on any failure (get() returning false) or an empty result
// (no matching car/owner row).
//
// Neither of the other Playwright tests that reference this page
// (mobile-responsive.spec.js, navigation.spec.js) pass a `car_id` query param,
// so the DB code path this fix touches never actually executes there. This
// file exercises it directly: the golden path (a real car_id renders the
// contact form) and the empty($carResults) redirect path (a nonexistent
// car_id never renders the form).
//
// Requires local MAMP. Default: http://localhost:9999/ElanRegistry/Registry/ — override with PLAYWRIGHT_BASE_URL, see docs/development/ENVIRONMENT.md

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');
const { CAR_ID_STANDARD, CAR_ID_NONEXISTENT } = require('./fixtures.js');

test.describe('Contact owner page — DatabaseInterface migration (#1585)', () => {

  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
  });

  test('valid car_id renders the contact-owner form', async ({ page }) => {
    // car_id=1 is used as a stable local fixture elsewhere in this suite
    // (car-edit-text-save.spec.js, csp-validation.spec.js, ui-consistency.spec.js).
    await page.goto(`app/owner/contact/owner.php?car_id=${CAR_ID_STANDARD}`, { waitUntil: 'domcontentloaded' });

    const currentUrl = page.url();
    if (currentUrl.includes('login') || currentUrl.includes('Please Log In')) {
      test.skip('Session not established locally — skipping contact-owner form assertion');
      return;
    }

    // Deliberately not skipped on a redirect to '/': that is exactly the failure
    // mode of the bug this file exists to catch (a call to a \DB-only method, or
    // the === false guards tripping), so it must fail this test, not skip it.
    expect(currentUrl).toContain('contact/owner.php');

    // The golden path this fix must preserve: get('cars', ...)->results() and
    // get('users', ...)->results() both succeeded and the form rendered.
    await expect(page.locator('h2:has-text("Contact Owner")')).toBeVisible();
    await expect(page.locator('textarea[name="message"]')).toBeVisible();
    await expect(page.locator('input[name="car_id"]')).toHaveValue(String(CAR_ID_STANDARD));
    await expect(page.locator('input[name="to_user_id"]')).toHaveCount(1);
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('nonexistent car_id never renders the contact form', async ({ page }) => {
    // No car with this id can exist — exercises the empty($carResults) guard,
    // which redirects to '/' rather than rendering the form.
    await page.goto(`app/owner/contact/owner.php?car_id=${CAR_ID_NONEXISTENT}`, { waitUntil: 'domcontentloaded' });

    const currentUrl = page.url();
    if (currentUrl.includes('login') || currentUrl.includes('Please Log In')) {
      test.skip('Session not established locally — skipping car_id redirect assertion');
      return;
    }

    expect(currentUrl).not.toContain('contact/owner.php');
    await expect(page.locator('textarea[name="message"]')).toHaveCount(0);
  });

});
