// An add-car post with an out-of-range purchase date (#2655): save.php must
// answer 422, name the purchase date, and create no car. When the post also
// holds a photo, the 422 must also remove that photo from userimages/temp/.
//
// The case posts with page.request and needs a logged-in account. It skips
// without E2E_DEV_ADMIN_*. CarActionsSaveWiringTest scans the save.php source:
// the guard sits between addCar() and mvTmpImages(), sends 422, and writes no
// FileError row. This case checks the live response.

const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

const SAVE_URL = 'app/api/cars/save.php';
const CHASSIS_URL = 'app/api/cars/chassis-availability.php';
const REPO_ROOT = path.join(__dirname, '..', '..');
// Docker bind-mounts the repo, so the host sees the app's upload temp directory.
const TEMP_IMAGE_DIR = path.join(REPO_ROOT, 'userimages', 'temp');

/**
 * Write a real JPEG with the PHP test helper. save.php rejects dummy bytes.
 *
 * @param {string} file Absolute path of the JPEG to write
 */
function writeTestJpeg(file) {
    execFileSync('php', [
        '-r',
        'require $argv[1]; \\Tests\\Support\\TestJpeg::write($argv[2], 400, 300);',
        path.join(REPO_ROOT, 'vendor', 'autoload.php'),
        file
    ], { cwd: REPO_ROOT, stdio: ['ignore', 'pipe', 'pipe'] });
}

/**
 * List the files in userimages/temp/. A missing directory has no files.
 *
 * @returns {string[]}
 */
function tempImageFiles() {
    return fs.existsSync(TEMP_IMAGE_DIR) ? fs.readdirSync(TEMP_IMAGE_DIR) : [];
}

/**
 * Open the add-car form and read the CSRF token and the first real model.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<{csrf: string, year: string, model: string}>}
 */
async function readAddCarForm(page) {
    await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });
    expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');
    const csrf = await page.locator('#csrf').inputValue();

    // The model list loads after the owner picks a year. Take the first real model.
    const year = '1965';
    await page.locator('#year').selectOption(year);
    await page.waitForFunction(
        () => Array.from(document.querySelectorAll('#model option')).some(o => o.value.includes('|')),
        { timeout: 15000 }
    );
    const model = await page.evaluate(
        () => Array.from(document.querySelectorAll('#model option')).find(o => o.value.includes('|')).value
    );
    return { csrf, year, model };
}

/**
 * Assert that save.php created no car: the chassis must still be free.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{csrf: string, year: string, model: string}} form
 * @param {string} chassis
 */
async function expectChassisFree(page, form, chassis) {
    const check = await page.request.post(CHASSIS_URL, {
        form: { csrf: form.csrf, command: 'chassis_check', year: form.year, model: form.model, chassis }
    });
    expect(check.status()).toBe(200);
    const checkJson = await readJson(check);
    expect(checkJson.success).toBe(true);
    expect(checkJson.available, 'rejected addCar must not create a car').toBe(true);
}

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
        const form = await readAddCarForm(page);
        const chassis = 'E2E' + String(Date.now()).slice(-9);

        const response = await page.request.post(SAVE_URL, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            multipart: {
                action: 'addCar',
                csrf: form.csrf,
                car_id: '',
                year: form.year,
                model: form.model,
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

        await expectChassisFree(page, form, chassis);
    });

    test('addCar with a photo returns 422 and leaves no temp file for the photo', async ({ page }) => {
        const form = await readAddCarForm(page);
        const chassis = 'E2E' + String(Date.now()).slice(-9);

        const jpegDir = fs.mkdtempSync(path.join(os.tmpdir(), 'e2e-2655-'));
        const jpegPath = path.join(jpegDir, 'photo.jpg');
        writeTestJpeg(jpegPath);
        const tempBefore = new Set(tempImageFiles());

        let response;
        try {
            response = await page.request.post(SAVE_URL, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                multipart: {
                    action: 'addCar',
                    csrf: form.csrf,
                    car_id: '',
                    year: form.year,
                    model: form.model,
                    chassis,
                    chassis_override: '1',
                    color: 'Red',
                    engine: '',
                    purchasedate: '1900-01-01',
                    solddate: '',
                    comments: 'e2e #2655 invalid purchase date with photo',
                    filenames: 'photo.jpg',
                    'file[]': { name: 'photo.jpg', mimeType: 'image/jpeg', buffer: fs.readFileSync(jpegPath) }
                }
            });
        } finally {
            fs.rmSync(jpegDir, { recursive: true, force: true });
        }

        expect(response.status()).toBe(422);
        const json = await readJson(response);
        expect(json.success).toBe(false);
        expect(json.errors.general.join(' ')).toContain('Purchase date');

        // uploadImages() wrote the photo and its -resized-* copies before addCar()
        // failed. The 422 path must delete them.
        const leftover = tempImageFiles().filter(name => !tempBefore.has(name));
        expect(leftover, 'the rejected addCar must leave no photo in userimages/temp/').toEqual([]);

        await expectChassisFree(page, form, chassis);
    });
});
