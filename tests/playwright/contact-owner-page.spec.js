// Coverage for app/owner/contact/owner.php and the car-page contact controls.
//
// #1585: the page once called `$db->findById(...)`, which is not part of
// DatabaseInterface and would have fataled in production. The page now loads
// the car with `CarRepository::findById()` and redirects to '/' when it
// returns null (no matching car).
//
// Neither of the other Playwright tests that reference this page
// (mobile-responsive.spec.js, navigation.spec.js) pass a `car_id` query param,
// so the car lookup never executes there. This file exercises it directly:
// the golden path (a real car_id renders the contact form) and the redirect
// path (a nonexistent car_id never renders the form).
//
// #2505: a car of the noowner system account offers no Contact Owner control,
// and owner.php redirects such a car back to the car page.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, login } = require('./auth-helper.js');
const { CAR_ID_STANDARD, CAR_ID_NONEXISTENT, CAR_ID_NOOWNER, CAR_ID_OWNED_BY_ADMIN } = require('./fixtures.js');

test.describe('Contact owner page — form and admin hero actions (#1585, #2505)', () => {

  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
  });

  test('valid car_id renders the contact-owner form', async ({ page }) => {
    // car_id=1 is used as a stable local fixture elsewhere in this suite
    // (car-edit-text-save.spec.js, csp-validation.spec.js, ui-consistency.spec.js).
    await page.goto(`app/owner/contact/owner.php?car_id=${CAR_ID_STANDARD}`, { waitUntil: 'domcontentloaded' });

    const currentUrl = page.url();
    test.skip(
      currentUrl.includes('login') || currentUrl.includes('Please Log In'),
      'Session not established locally — skipping contact-owner form assertion'
    );

    // Deliberately not skipped on a redirect to '/': that is exactly the failure
    // mode of the bug this file exists to catch (a call to a \DB-only method, or
    // a car lookup that finds nothing), so it must fail this test, not skip it.
    expect(currentUrl).toContain('contact/owner.php');

    // The golden path this fix must preserve: the car and owner lookups both
    // succeeded and the form rendered.
    await expect(page.locator('h2:has-text("Contact Owner")')).toBeVisible();
    await expect(page.locator('textarea[name="message"]')).toBeVisible();
    await expect(page.locator('input[name="car_id"]')).toHaveValue(String(CAR_ID_STANDARD));
    await expect(page.locator('input[name="to_user_id"]')).toHaveCount(1);
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('nonexistent car_id never renders the contact form', async ({ page }) => {
    // No car with this id can exist — exercises the findById() === null guard,
    // which redirects to '/' rather than rendering the form.
    await page.goto(`app/owner/contact/owner.php?car_id=${CAR_ID_NONEXISTENT}`, { waitUntil: 'domcontentloaded' });

    const currentUrl = page.url();
    test.skip(
      currentUrl.includes('login') || currentUrl.includes('Please Log In'),
      'Session not established locally — skipping car_id redirect assertion'
    );

    expect(currentUrl).not.toContain('contact/owner.php');
    await expect(page.locator('textarea[name="message"]')).toHaveCount(0);
  });

  // Cars of the noowner system account used to offer Contact Owner, which
  // ended in a 500 on send (#2505).
  test('noowner car page shows the status line and Admin Edit Car only (admin)', async ({ page }) => {
    await page.goto(`app/owner/cars/details.php?car_id=${CAR_ID_NOOWNER}`, { waitUntil: 'domcontentloaded' });

    await expect(page.getByTestId('hero-no-owner-status')).toBeVisible();
    await expect(page.getByRole('button', { name: /Admin Edit Car/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /Contact the Registry/ })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /Contact Owner/ })).toHaveCount(0);
  });

  // Guard: a status line or a missing Contact Owner on every car would pass the test above.
  test('standard car page shows Contact Owner and no status line (admin)', async ({ page }) => {
    // The admin's own car renders the owner view, which has no Contact Owner.
    test.skip(
      CAR_ID_STANDARD === CAR_ID_OWNED_BY_ADMIN,
      'CAR_ID_STANDARD is the admin-owned car — set CAR_ID_STANDARD in .env.local to a car the E2E admin does not own'
    );
    await page.goto(`app/owner/cars/details.php?car_id=${CAR_ID_STANDARD}`, { waitUntil: 'domcontentloaded' });

    await expect(page.getByRole('button', { name: /Admin Edit Car/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /Contact Owner/ })).toBeVisible();
    await expect(page.getByTestId('hero-no-owner-status')).toHaveCount(0);
  });

  test('contact/owner.php for a noowner car redirects to the car page without a form', async ({ page }) => {
    await page.goto(`app/owner/contact/owner.php?car_id=${CAR_ID_NOOWNER}`, { waitUntil: 'domcontentloaded' });

    expect(page.url()).toContain(`app/owner/cars/details.php?car_id=${CAR_ID_NOOWNER}`);
    // usWarning() queues a UserSpice generic message, which renders as the dark toast.
    await expect(page.locator('.us-toast:has(.us-bar-dark) .toast-body'))
      .toContainText('This car has no current owner. Use the Feedback form');
    await expect(page.locator('textarea[name="message"]')).toHaveCount(0);
  });

});

// The no_owner_detail hero context is for a logged-in member who is neither
// the owner nor an admin (#2505). Playwright gives each test a fresh browser
// context, so this describe logs in as the non-admin user without touching the
// admin session of the describe above.
test.describe('Contact owner — hero actions as a non-admin member (#2505)', () => {

  test.beforeEach(async ({ page }) => {
    test.skip(
      !process.env.E2E_DEV_NONADMIN_USERNAME || !process.env.E2E_DEV_NONADMIN_PASSWORD,
      'E2E_DEV_NONADMIN_USERNAME/E2E_DEV_NONADMIN_PASSWORD are not set in .env.local'
    );
    await login(page, process.env.E2E_DEV_NONADMIN_USERNAME, process.env.E2E_DEV_NONADMIN_PASSWORD);
  });

  test('noowner car page shows the status line and Contact the Registry (member)', async ({ page }) => {
    await page.goto(`app/owner/cars/details.php?car_id=${CAR_ID_NOOWNER}`, { waitUntil: 'domcontentloaded' });

    await expect(page.getByTestId('hero-no-owner-status')).toBeVisible();
    const registryLink = page.getByRole('button', { name: /Contact the Registry/ });
    await expect(registryLink).toBeVisible();
    await expect(registryLink).toHaveAttribute('href', /app\/owner\/contact\/index\.php$/);
    await expect(page.getByRole('button', { name: /Contact Owner/ })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /Admin Edit Car/ })).toHaveCount(0);
  });

  // Guard: a member who saw the registry contact on every car would pass the test above.
  test('standard car page shows Contact Owner and no status line (member)', async ({ page }) => {
    await page.goto(`app/owner/cars/details.php?car_id=${CAR_ID_STANDARD}`, { waitUntil: 'domcontentloaded' });

    await expect(page.getByRole('button', { name: /Contact Owner/ })).toBeVisible();
    await expect(page.getByTestId('hero-no-owner-status')).toHaveCount(0);
  });

});

// A guest gets a login link that leads to Contact Owner, which a noowner car does not have.
test.describe('Contact owner — hero actions as a guest (#2505)', () => {

  test.use({ storageState: { cookies: [], origins: [] } });

  test('noowner car page shows the status line and no login link (guest)', async ({ page }) => {
    await page.goto(`app/owner/cars/details.php?car_id=${CAR_ID_NOOWNER}`, { waitUntil: 'domcontentloaded' });

    await expect(page.getByTestId('hero-no-owner-status')).toBeVisible();
    await expect(page.getByRole('link', { name: /Log in to contact owner/ })).toHaveCount(0);
  });

  // Guard: a page that dropped the login link on every car would pass the test above.
  test('standard car page shows the login link (guest)', async ({ page }) => {
    await page.goto(`app/owner/cars/details.php?car_id=${CAR_ID_STANDARD}`, { waitUntil: 'domcontentloaded' });

    await expect(page.getByRole('link', { name: /Log in to contact owner/ })).toBeVisible();
    await expect(page.getByTestId('hero-no-owner-status')).toHaveCount(0);
  });

});
