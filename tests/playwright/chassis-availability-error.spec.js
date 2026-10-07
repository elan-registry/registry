// #754: a failed chassis availability check must show #chassis_check_error
// and still enable #color and #engine. Endpoints are mocked with page.route().
// Model and chassis use real Playwright input so the app's handlers fire on
// real browser events (#2071).

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

const VALIDATE_CHASSIS_URL = '**/app/api/cars/chassis-validate.php';
const CHECK_CHASSIS_URL    = '**/app/api/cars/chassis-availability.php';

const VALID_RESPONSE = JSON.stringify({ success: true, valid: true, error_reason: '' });
const AVAILABLE_RESPONSE = JSON.stringify({ success: true, taken: false, available: true });

/**
 * Navigate to the add-car form and return false if the session isn't active.
 */
async function gotoAddCarForm(page) {
    await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });
    const url = page.url();
    if (url.includes('login') || url.includes('Please Log In')) {
        return false;
    }
    return true;
}

/**
 * Prepare the chassis field for a blur-triggered availability check.
 * The year change repopulates #model asynchronously and wipes earlier
 * options, so wait for real options first. Blur by focusing elsewhere:
 * locator.blur() does nothing on an element that never had focus (#2071).
 */
async function triggerChassisBlur(page) {
    await page.evaluate(() => {
        // Set validYear via the year change handler (year select is server-rendered)
        const $year = window.$('#year');
        $year.val($year.find('option[value!=""]').first().val() || '1967').trigger('change');
    });

    // Wait for the async model-load triggered above to finish repopulating #model.
    await page.waitForFunction(() => {
        return window.$('#model').find('option[value!=""]').length > 0;
    }, { timeout: 5000 });

    const firstRealValue = await page.locator('#model option:not([value=""])').first().getAttribute('value');
    await page.locator('#model').selectOption(firstRealValue);

    // Fill (real focus + input) then blur by moving focus elsewhere, so the
    // app's jQuery blur handler actually fires.
    await page.locator('#chassis').fill('1234');
    await page.locator('#model').focus();
}

test.describe('Chassis availability check error feedback (#754)', () => {

    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    test('error banner appears when availability check fails', async ({ page }) => {
        const ready = await gotoAddCarForm(page);
        test.skip(!ready, 'Authenticated session required');

        await page.route(VALIDATE_CHASSIS_URL, (route) =>
            route.fulfill({ status: 200, contentType: 'application/json', body: VALID_RESPONSE })
        );
        await page.route(CHECK_CHASSIS_URL, (route) => route.abort());

        await triggerChassisBlur(page);

        await expect(page.locator('#chassis_check_error')).toBeVisible({ timeout: 5000 });
    });

    test('color and engine fields are enabled when availability check fails', async ({ page }) => {
        const ready = await gotoAddCarForm(page);
        test.skip(!ready, 'Authenticated session required');

        await page.route(VALIDATE_CHASSIS_URL, (route) =>
            route.fulfill({ status: 200, contentType: 'application/json', body: VALID_RESPONSE })
        );
        await page.route(CHECK_CHASSIS_URL, (route) => route.abort());

        await triggerChassisBlur(page);

        await expect(page.locator('#chassis_check_error')).toBeVisible({ timeout: 5000 });
        await expect(page.locator('#color')).toBeEnabled();
        await expect(page.locator('#engine')).toBeEnabled();
    });

    test('error banner clears after a subsequent successful check', async ({ page }) => {
        const ready = await gotoAddCarForm(page);
        test.skip(!ready, 'Authenticated session required');

        // First check: fail — banner appears
        await page.route(VALIDATE_CHASSIS_URL, (route) =>
            route.fulfill({ status: 200, contentType: 'application/json', body: VALID_RESPONSE })
        );
        await page.route(CHECK_CHASSIS_URL, (route) => route.abort());

        await triggerChassisBlur(page);
        await expect(page.locator('#chassis_check_error')).toBeVisible({ timeout: 5000 });

        // Second check: succeed — banner clears
        await page.unroute(CHECK_CHASSIS_URL);
        await page.route(CHECK_CHASSIS_URL, (route) =>
            route.fulfill({ status: 200, contentType: 'application/json', body: AVAILABLE_RESPONSE })
        );

        // The banner starts hidden, so wait for the mocked response first;
        // otherwise a stalled chain would pass toBeHidden().
        const availabilityResponse = page.waitForResponse(
            (r) => r.url().includes('chassis-availability.php') && r.status() === 200
        );
        await triggerChassisBlur(page);
        await availabilityResponse;

        await expect(page.locator('#chassis_check_error')).toBeHidden({ timeout: 5000 });
    });

});
