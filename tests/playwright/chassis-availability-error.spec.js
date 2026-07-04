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
// Because checkChassisAvailability() lives inside a jQuery closure, we drive the
// field via jQuery DOM manipulation in page.evaluate() rather than calling the
// function directly.
//
// Requires local MAMP at http://localhost:9999/elan-registry

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
 *   1. Trigger the year change handler so validYear is set.
 *   2. Insert a synthetic model option and trigger the model change handler
 *      so validModel is set and chassis is enabled.
 *   3. Set the chassis value and trigger blur (which calls validateChassis.php
 *      then check-chassis.php when the mocked validator returns valid).
 */
async function triggerChassisBlur(page) {
    await page.evaluate(() => {
        // Set validYear via the year change handler (year select is server-rendered)
        const $year = window.$('#year');
        $year.val($year.find('option[value!=""]').first().val() || '1967').trigger('change');

        // Insert a synthetic model option and trigger model change → enables chassis
        const $model = window.$('#model');
        $model.prop('disabled', false);
        if (!$model.find('option[value="S1"]').length) {
            $model.append('<option value="S1">S1</option>');
        }
        $model.val('S1').trigger('change');

        // Set chassis value directly (field is now enabled)
        window.$('#chassis').prop('disabled', false).val('1234');
    });

    // Trigger blur via Playwright so the event fires through the normal listener
    await page.locator('#chassis').blur();
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
        if (!ready) {
            test.skip('Authenticated session required');
            return;
        }

        await page.route(VALIDATE_CHASSIS_URL, (route) =>
            route.fulfill({ status: 200, contentType: 'application/json', body: VALID_RESPONSE })
        );
        await page.route(CHECK_CHASSIS_URL, (route) => route.abort());

        await triggerChassisBlur(page);

        await expect(page.locator('#chassis_check_error')).toBeVisible({ timeout: 5000 });
    });

    test('color and engine fields are enabled when availability check fails', async ({ page }) => {
        const ready = await gotoAddCarForm(page);
        if (!ready) {
            test.skip('Authenticated session required');
            return;
        }

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
        if (!ready) {
            test.skip('Authenticated session required');
            return;
        }

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

        await triggerChassisBlur(page);
        await expect(page.locator('#chassis_check_error')).toBeHidden({ timeout: 5000 });
    });

});
