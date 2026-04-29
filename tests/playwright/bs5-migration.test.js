// tests/playwright/bs5-migration.test.js
//
// Behavioral regression tests for Bootstrap 5 JS API migration (PR #730).
// Covers: Flatpickr date pickers, Statistics page ElanRegistryAPI availability,
// and the nav dropdown firstChild patch in footer.php.
//
// Requires local MAMP at http://localhost:9999/elan_registry

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, navigateAndWait } = require('./auth-helper.js');

// ---------------------------------------------------------------------------
// Area 1: Flatpickr date pickers (app/cars/edit.php, Car Details section)
// ---------------------------------------------------------------------------

test.describe('BS5 Migration — Flatpickr date pickers', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
    await page.goto('/app/cars/edit.php', { waitUntil: 'networkidle' });
    // Date fields are in Section 1 (Car Details) which is open by default — no toggle needed
    await expect(page.locator('#section1')).toBeVisible({ timeout: 5000 });
  });

  test('purchase date field opens flatpickr calendar', async ({ page }) => {
    await page.locator('#purchasedate').click();
    await expect(page.locator('.flatpickr-calendar')).toBeVisible();
  });

  test('purchase date stores value in YYYY-MM-DD format', async ({ page }) => {
    await page.locator('#purchasedate').fill('2000-06-15');
    await page.locator('#purchasedate').blur();
    await expect(page.locator('#purchasedate')).toHaveValue('2000-06-15');
  });

  test('sold date accepts manual keyboard entry in YYYY-MM-DD format', async ({ page }) => {
    await page.locator('#solddate').fill('2010-03-22');
    await page.locator('#solddate').blur();
    await expect(page.locator('#solddate')).toHaveValue('2010-03-22');
  });
});

// ---------------------------------------------------------------------------
// Area 2: Statistics page — ElanRegistryAPI availability & tab lazy-loading
// Regression for the missing html_footer.php bug fixed in #619.
// ---------------------------------------------------------------------------

test.describe('BS5 Migration — Statistics page', () => {
  test('page loads without JS errors', async ({ page }) => {
    await ensureLoggedIn(page);

    // Attach listener after login so login-page errors don't pollute the array
    const pageErrors = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);

    expect(pageErrors, `Unexpected JS errors: ${pageErrors.join('\n')}`).toHaveLength(0);
  });

  test('ElanRegistryAPI is defined on statistics page', async ({ page }) => {
    await ensureLoggedIn(page);
    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');

    const isDefined = await page.evaluate(() => typeof window.ElanRegistryAPI !== 'undefined');
    expect(isDefined, 'ElanRegistryAPI should be defined — html_footer.php must be included').toBe(true);
  });

  test('geographic tab triggers AJAX lazy load successfully', async ({ page }) => {
    await ensureLoggedIn(page);
    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');

    // Set up response listener before clicking to avoid race condition
    const responsePromise = page.waitForResponse(
      (r) => r.url().includes('statistics-data.php') && r.url().includes('tab=geographic'),
      { timeout: 15000 }
    );

    await page.locator('#geographic-tab').click();
    const response = await responsePromise;

    expect(response.status()).toBe(200);

    // Content must be rendered with data, not an error alert
    await expect(page.locator('#geographic-content')).not.toBeEmpty();
    await expect(page.locator('#geographic-content .alert-danger')).toHaveCount(0);
  });

  test('production tab triggers AJAX lazy load successfully', async ({ page }) => {
    await ensureLoggedIn(page);
    await page.goto('/app/reports/statistics.php');
    await page.waitForLoadState('networkidle');

    const responsePromise = page.waitForResponse(
      (r) => r.url().includes('statistics-data.php') && r.url().includes('tab=production'),
      { timeout: 15000 }
    );

    await page.locator('#production-tab').click();
    const response = await responsePromise;

    expect(response.status()).toBe(200);

    await expect(page.locator('#production-content')).not.toBeEmpty();
    await expect(page.locator('#production-content .alert-danger')).toHaveCount(0);
  });
});

// ---------------------------------------------------------------------------
// Area 3: Navigation dropdown close-on-outside-click
// Regression for the users/js/menu.js firstChild patch in footer.php (#729).
// Without the patch, clicking outside throws: "TypeError: Cannot read properties
// of undefined (reading 'click')" via open.firstChild.click().
// ---------------------------------------------------------------------------

test.describe('BS5 Migration — Navigation dropdown', () => {
  test('clicking outside an open dropdown closes it without TypeError', async ({ page }) => {
    const typeErrors = [];
    page.on('pageerror', (err) => {
      if (err.message.includes('TypeError')) {
        typeErrors.push(err.message);
      }
    });

    await page.goto('/');
    await page.waitForLoadState('networkidle');

    // Confirm a dropdown trigger is present before interacting
    // (nav uses .sub-toggle, not Bootstrap's .dropdown-toggle)
    const dropdownToggle = page.locator('.us_menu .sub-toggle').first();
    await expect(dropdownToggle).toBeVisible({ timeout: 5000 });

    await dropdownToggle.click();
    await expect(page.locator('.us_sub-menu.show')).toBeVisible({ timeout: 3000 });

    // Click far outside the menu to trigger the outside-click handler
    await page.mouse.click(10, 10);
    await page.waitForTimeout(500);

    // Dropdown should be closed — .open class removed by footer.php patch
    await expect(page.locator('.us_menu .dropdown.open')).toHaveCount(0);

    // No TypeError from menu.js firstChild.click() on a text node
    expect(typeErrors, `TypeErrors thrown: ${typeErrors.join('\n')}`).toHaveLength(0);
  });
});
