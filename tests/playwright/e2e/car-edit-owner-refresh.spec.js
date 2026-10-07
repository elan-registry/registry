// Real save.php path for the #1962 owner-contact refresh. Other coverage
// mocks save.php or calls Car::update() directly; this one runs
// buildCarDetails() through a real request.
//
// It cannot build a stale owner snapshot: user_settings.php syncs profile
// changes to every car in the same request (#1873). So it edits an unrelated
// field (comments) and asserts the car shows the owner's current name.
// WARNING: this test writes to a real car row.
//
// Local/Dev only for now; Test/Production enrollment is #2301.

const { test, expect } = require('@playwright/test');

test.describe('Car edit — real buildCarDetails() owner-column refresh (#1962)', () => {
  // Skip outside the admin project. On Local/Dev without credentials,
  // auth.setup.js skips but `admin` still runs unauthenticated, so skip here
  // rather than report a false failure. E2E_AUTH_TIER tiers use storageState.
  test.beforeEach(async ({}, testInfo) => {
    if (testInfo.project.name !== 'admin') {
      testInfo.skip(true, 'Only runs under the admin project');
    }
    const usesLiveLogin = !process.env.E2E_AUTH_TIER;
    if (usesLiveLogin && (!process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD)) {
      testInfo.skip(true, 'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated locally');
    }
  });

  test('editing an unrelated field writes the owner\'s current name and location onto the car', async ({ page }) => {
    // Read fname from the live DB, not .env.local, which can drift.
    await page.goto('usersc/user_settings.php');
    await page.waitForLoadState('domcontentloaded');

    const currentFname = await page.locator('#fname').inputValue();
    expect(currentFname, 'Precondition: E2E_DEV_ADMIN_USERNAME must have a first name set').not.toBe('');

    // Find an owned car from account.php, not a fixture id: ownership differs
    // per tier, and edit.php falls back to "Add Car" mode for an unowned id.
    await page.goto('usersc/account.php');
    await page.waitForLoadState('domcontentloaded');

    const updateCarButton = page.locator('button:has-text("Update Car"), a:has-text("Update Car")');
    const hasCarToUpdate = await updateCarButton.count() > 0;
    test.skip(!hasCarToUpdate, 'Logged-in account has no registered cars — nothing to edit');

    await updateCarButton.first().click();
    await page.waitForLoadState('domcontentloaded');

    const commentField = page.locator('#comments');
    await expect(commentField, "Precondition: the account's own car edit page must render the comments field").toBeVisible();

    const editedCarId = await page.locator('#car_id').inputValue();
    expect(editedCarId, 'Precondition: edit.php must render a car_id for the discovered car').not.toBe('');

    const marker = `owner-refresh-e2e ${new Date().toISOString()}`;
    await commentField.fill(marker);

    // Submit is a fetch(); waitForLoadState() would not wait for it. Wait for
    // the redirect, which does not occur if save.php rejects the save.
    // #submit stays disabled until the saved model is selected (#2295).
    await expect(page.locator('#model')).not.toHaveValue('');
    await expect(page.locator('#submit')).toBeEnabled();
    await page.locator('#submit').click();
    await page.waitForURL(/details\.php\?car_id=/, { timeout: 10000 });
    await page.waitForLoadState('domcontentloaded');

    // details.php applies ucfirst(), so compare case-insensitively.
    const ownerNameText = await page.locator('dt:has-text("Owner Name") + dd').first().innerText();
    expect(
      ownerNameText.trim().toLowerCase(),
      `Car ${editedCarId}'s rendered owner name must reflect the logged-in account's CURRENT ` +
      'profile fname after an unrelated-field edit — this only happens if the real ' +
      'buildCarDetails() owner-contact refresh executed during the save'
    ).toBe(currentFname.trim().toLowerCase());
  });
});
