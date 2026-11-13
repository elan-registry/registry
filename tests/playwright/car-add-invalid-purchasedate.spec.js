// An add-car post with an out-of-range purchase date (#2655): save.php must
// answer 422, name the purchase date, and create no car.
//
// The case posts with page.request and needs a logged-in account. It skips
// without E2E_DEV_ADMIN_*. CarActionsSaveWiringTest checks only that the guard
// sits between addCar() and mvTmpImages().

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

const SAVE_URL = 'app/api/cars/save.php';
const CHASSIS_URL = 'app/api/cars/chassis-availability.php';

/**
 * Parse a save.php body. Docker prints PHP warnings before the JSON.
 *
 * @param {import('@playwright/test').APIResponse} response
 * @returns {Promise<any>}
 */
async function readJson(response) {
    const text = await response.text();
    const start = text.indexOf('{');
    expect(start, `response must hold a JSON object, got: ${text.slice(0, 200)}`).toBeGreaterThanOrEqual(0);
    return JSON.parse(text.slice(start));
}

test.describe('Add car with an invalid purchase date (#2655)', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(
            !process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD,
            'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests'
        );
        await ensureLoggedIn(page);
    });

    test('addCar returns 422, names the purchase date, and creates no car', async ({ page }) => {
        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });
        expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');
        const csrf = await page.locator('#csrf').inputValue();

        // The model list loads after the owner picks a year. Take the first real model.
        await page.locator('#year').selectOption('1965');
        await page.waitForFunction(
            () => Array.from(document.querySelectorAll('#model option')).some(o => o.value.includes('|')),
            { timeout: 15000 }
        );
        const model = await page.evaluate(
            () => Array.from(document.querySelectorAll('#model option')).find(o => o.value.includes('|')).value
        );
        const year = '1965';
        const chassis = 'E2E' + String(Date.now()).slice(-9);

        const response = await page.request.post(SAVE_URL, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            multipart: {
                action: 'addCar',
                csrf,
                car_id: '',
                year,
                model,
                chassis,
                chassis_override: '1',
                color: 'Red',
                engine: '',
                purchasedate: '1900-01-01',
                solddate: '',
                comments: 'e2e #2655 invalid purchase date',
                filenames: ''
            }
        });

        expect(response.status()).toBe(422);
        const json = await readJson(response);
        expect(json.success).toBe(false);
        expect(Array.isArray(json.errors.general), 'errors.general must be a list').toBe(true);
        expect(json.errors.general.join(' ')).toContain('Purchase date');
        expect(json.message, 'must not report the car as saved').not.toContain('Car saved');

        // No car row: the chassis must still be free.
        const check = await page.request.post(CHASSIS_URL, {
            form: { csrf, command: 'chassis_check', year, model, chassis }
        });
        expect(check.status()).toBe(200);
        const checkJson = await readJson(check);
        expect(checkJson.success).toBe(true);
        expect(checkJson.available, 'rejected addCar must not create a car').toBe(true);
    });
});
