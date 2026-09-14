// tests/playwright/chassis-availability-error.test.js
//
// Regression tests for issue #754: chassis availability check silently discarded errors.
//
// Fix: .catch() in checkChassisAvailability() now shows #chassis_check_error,
// enables #color and #engine, and logs the error. .then() clears the banner on success.
//
// What these tests verify:
//   - #chassis_check_error appears when the availability check fails (network abort)
//   - #color and #engine are enabled even when the check fails (non-blocking per spec)
//   - #chassis_check_error clears when a subsequent check succeeds
//
// The chassis blur handler calls validateChassis.php then (if valid) check-chassis.php.
// Both endpoints are intercepted with page.route() so no MAMP DB row is needed.
// Because checkChassisAvailability() lives inside a jQuery closure, we can't call
// it directly. The year field is driven via jQuery in page.evaluate(); the model
// select and chassis field are driven via real Playwright locator interactions
// (selectOption()/fill()/focus()) so the app's own change/blur handlers fire
// through genuine browser events rather than synthetic jQuery triggers — see
// triggerChassisBlur() below for why that distinction matters (#2071).
//
// Requires local MAMP. Default: http://localhost:9999/ElanRegistry/Registry/ — override with PLAYWRIGHT_BASE_URL, see docs/development/ENVIRONMENT.md

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

const VALIDATE_CHASSIS_URL = '**/app/api/cars/chassis-validate.php';
const CHECK_CHASSIS_URL    = '**/app/api/cars/chassis-availability.php';

const VALID_RESPONSE = JSON.stringify({ success: true, valid: true, error_reason: '' });
const AVAILABLE_RESPONSE = JSON.stringify({ success: true, taken: false, available: true });

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

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
 * Prepare the chassis field for a blur-triggered availability check:
 *   1. Trigger the year change handler so validYear is set. This kicks off
 *      an async ModelLoader.populateModelDropdown() call (real models.php
 *      request) that clears and repopulates #model — any option injected
 *      before this settles gets wiped, so we must wait for the real options
 *      to land before selecting one.
 *   2. Wait for #model to be populated with a real option, then select it
 *      via Playwright's selectOption() (fires a real 'change' event) so
 *      validModel is set and chassis is enabled.
 *   3. Fill the chassis field via Playwright (real focus + input, unlike
 *      jQuery .val()) then blur by focusing elsewhere — jQuery's .blur()
 *      handler only fires on a genuine focus/blur transition, and
 *      Playwright's locator.blur() is a no-op on an element that was never
 *      actually focused, which the previous version of this helper hit
 *      silently (see #2071).
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

    // Select the first real model option through Playwright so the native
    // 'change' event fires the app's own listener, setting validModel to a
    // value the app itself produced and enabling #chassis.
    const firstRealValue = await page.locator('#model option:not([value=""])').first().getAttribute('value');
    await page.locator('#model').selectOption(firstRealValue);

    // Fill (real focus + input) then blur by moving focus elsewhere, so the
    // app's jQuery blur handler actually fires.
    await page.locator('#chassis').fill('1234');
    await page.locator('#model').focus();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

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

        // #chassis_check_error starts life as d-none (edit.php) and every failure
        // path in the chain also leaves it d-none, so a bare toBeHidden() here
        // would pass even if the chain stalled before reaching the success
        // handler that's actually supposed to clear it. Wait for the mocked
        // availability response to actually be consumed first, so a stalled
        // chain fails loudly instead of resting on the pre-existing hidden state.
        const availabilityResponse = page.waitForResponse(
            (r) => r.url().includes('chassis-availability.php') && r.status() === 200
        );
        await triggerChassisBlur(page);
        await availabilityResponse;

        await expect(page.locator('#chassis_check_error')).toBeHidden({ timeout: 5000 });
    });

});
