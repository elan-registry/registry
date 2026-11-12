// A batch with one bad photo (#1844): save.php checks every file before it
// stores any, answers 422, and names each rejected file with the reason.
//
// The server cases post the batch with page.request, so the check in
// save.php runs without the FilePond minFileSize check in the browser.
// They need an owner login and skip without E2E_DEV_ADMIN_*. The add case
// must not create a car; the update case must not change the photo list.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');
const { CAR_ID_STANDARD } = require('./fixtures.js');

const SAVE_URL = 'app/api/cars/save.php';
const CHASSIS_URL = 'app/api/cars/chassis-availability.php';
const TOO_SMALL_TEXT = 'The photo file is empty or damaged. Choose a different photo.';
const BAD_NAME = 'tiny-bad-1844.png';
const GOOD_NAME = 'good-photo-1844.jpg';

/**
 * Build a real JPEG in the browser.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<Buffer>}
 */
async function makeJpeg(page) {
    const base64 = await page.evaluate(async () => {
        const canvas = document.createElement('canvas');
        canvas.width = 320;
        canvas.height = 240;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#1d4e89';
        ctx.fillRect(0, 0, 320, 240);
        ctx.fillStyle = '#f6c85f';
        ctx.fillRect(80, 60, 160, 120);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        const bytes = new Uint8Array(await blob.arrayBuffer());
        let binary = '';
        for (let i = 0; i < bytes.length; i += 0x8000) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
        }
        return btoa(binary);
    });
    return Buffer.from(base64, 'base64');
}

/**
 * A PNG signature followed by padding: 50 bytes, below the 100-byte minimum.
 *
 * @returns {Buffer}
 */
function tinyPng() {
    const png = Buffer.concat([
        Buffer.from('89504e470d0a1a0a', 'hex'),
        Buffer.alloc(42)
    ]);
    expect(png.length, 'bad photo must be 50 bytes').toBe(50);
    return png;
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

/**
 * Build the multipart body: the given fields plus a valid and an invalid photo.
 *
 * @param {Record<string, string>} fields
 * @param {Buffer} jpeg
 * @returns {FormData}
 */
function batchBody(fields, jpeg) {
    const form = new FormData();
    for (const [key, value] of Object.entries(fields)) {
        form.append(key, value);
    }
    form.append('file[]', new Blob([jpeg], { type: 'image/jpeg' }), GOOD_NAME);
    form.append('file[]', new Blob([tinyPng()], { type: 'image/png' }), BAD_NAME);
    return form;
}

/**
 * Open the edit form and read the CSRF token.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} url
 * @returns {Promise<string>}
 */
async function openForm(page, url) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');
    await page.waitForFunction(
        () => typeof window.FilePond !== 'undefined' && document.querySelector('.filepond--root') !== null,
        { timeout: 15000 }
    );
    return page.locator('#csrf').inputValue();
}

test.describe('Rejected photo batch (#1844)', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(
            !process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD,
            'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests'
        );
        await ensureLoggedIn(page);
    });

    test('addCar names only the bad photo and creates no car', async ({ page }) => {
        const csrf = await openForm(page, 'app/owner/cars/edit.php');
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
        const jpeg = await makeJpeg(page);

        const response = await page.request.post(SAVE_URL, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            multipart: batchBody({
                action: 'addCar',
                csrf,
                car_id: '',
                year,
                model,
                chassis,
                chassis_override: '1',
                color: 'Red',
                engine: '',
                purchasedate: '',
                solddate: '',
                comments: 'e2e #1844 batch test',
                filenames: GOOD_NAME + ',' + BAD_NAME
            }, jpeg)
        });

        expect(response.status()).toBe(422);
        const json = await readJson(response);
        expect(json.success).toBe(false);
        expect(json.message, 'request must reach the upload check, not stop on validation').toBe('Cannot add car: image upload failed');
        expect(Array.isArray(json.errors.general), 'errors.general must be a list').toBe(true);
        expect(json.errors.general).toHaveLength(1);
        expect(json.errors.general[0]).toBe(`File upload rejected for ${BAD_NAME}: ${TOO_SMALL_TEXT}`);
        expect(JSON.stringify(json.errors), 'valid photo name must not be in the errors').not.toContain(GOOD_NAME);

        // No car row: the chassis must still be free.
        const check = await page.request.post(CHASSIS_URL, {
            form: { csrf, command: 'chassis_check', year, model, chassis }
        });
        expect(check.status()).toBe(200);
        const checkJson = await readJson(check);
        expect(checkJson.success).toBe(true);
        expect(checkJson.available, 'rejected addCar must not create a car').toBe(true);
    });

    test('updateCar leaves the photo list unchanged', async ({ page }) => {
        const csrf = await openForm(page, `app/owner/cars/edit.php?car_id=${CAR_ID_STANDARD}`);
        await page.waitForFunction(
            () => (document.querySelector('#model') || {}).value.includes('|'),
            { timeout: 15000 }
        );
        const fields = await page.evaluate(() => {
            const val = id => (document.getElementById(id) || {}).value || '';
            return {
                year: val('year'), model: val('model'), chassis: val('chassis'),
                color: val('color'), engine: val('engine'), comments: val('comments'),
                purchasedate: val('purchasedate'), solddate: val('solddate')
            };
        });
        const jpeg = await makeJpeg(page);

        const listPhotos = async () => {
            const res = await page.request.post(SAVE_URL, {
                form: { csrf, action: 'fetchImages', carID: String(CAR_ID_STANDARD) }
            });
            const json = await readJson(res);
            expect(json.success, 'fetchImages must succeed').toBe(true);
            return json.images.map(i => i.basename);
        };
        const before = await listPhotos();

        const response = await page.request.post(SAVE_URL, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            multipart: batchBody({
                action: 'updateCar',
                csrf,
                car_id: String(CAR_ID_STANDARD),
                ...fields,
                chassis_override: '1',
                filenames: before.join(',')
            }, jpeg)
        });

        expect(response.status()).toBe(422);
        const json = await readJson(response);
        expect(json.message, 'request must reach the upload check, not stop on validation').toBe('Cannot update car: image upload failed');
        expect(json.errors.general).toEqual([`File upload rejected for ${BAD_NAME}: ${TOO_SMALL_TEXT}`]);

        const after = await listPhotos();
        expect(after, 'photo list must not change').toEqual(before);
    });

    test('a 422 with two errors shows two list items and keeps the form values', async ({ page }) => {
        await page.route('**/' + SAVE_URL, async (route, request) => {
            const postData = request.method() === 'POST' ? request.postData() || '' : '';
            if (request.method() === 'POST' && !postData.includes('fetchImages') && !postData.includes('removeImages')) {
                await route.fulfill({
                    status: 422,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        success: false,
                        message: 'Cannot update car: image upload failed',
                        errors: {
                            general: [
                                `File upload rejected for first.png: ${TOO_SMALL_TEXT}`,
                                'File upload rejected for second.png: The file is not a JPEG, PNG or GIF photo. Choose a different photo.'
                            ]
                        }
                    })
                });
                return;
            }
            await route.fallback();
        });

        await openForm(page, `app/owner/cars/edit.php?car_id=${CAR_ID_STANDARD}`);
        await page.waitForFunction(
            () => (document.querySelector('#model') || {}).value.includes('|'),
            { timeout: 15000 }
        );
        const chassisBefore = await page.locator('#chassis').inputValue();
        expect(chassisBefore, 'fixture car must have a chassis').not.toBe('');
        await page.locator('#comments').fill('keep me 1844');
        // Wait for the stored photos to load, so that the count is stable.
        await page.waitForLoadState('networkidle');
        const pondItems = page.locator('.filepond--item');
        const itemsBefore = await pondItems.count();
        expect(itemsBefore, 'fixture car must have a stored photo, or the count check proves nothing').toBeGreaterThan(0);

        const submitBtn = page.locator('#submit');
        await expect(submitBtn).toBeEnabled({ timeout: 15000 });
        await submitBtn.click();

        const items = page.locator('#message li');
        await expect(items).toHaveCount(2);
        await expect(items.nth(0)).toContainText('first.png');
        await expect(items.nth(1)).toContainText('second.png');
        await expect(page.locator('#chassis')).toHaveValue(chassisBefore);
        await expect(page.locator('#comments')).toHaveValue('keep me 1844');
        // The owner does not have to add the photos again.
        await expect(pondItems, 'the 422 must not clear the photo list').toHaveCount(itemsBefore);
        await expect(submitBtn).toBeEnabled();
    });
});
