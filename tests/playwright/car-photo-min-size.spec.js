// Minimum photo size in the car edit form (#1844): FilePond rejects a photo
// under 100 bytes with the label "Photo is empty or damaged", and the submit
// handler stops a save while such an item is in the pond. The tests mock the
// fetchImages request and each save request, so no car data is written. A
// removeImages request goes to the real server. Tests skip without
// E2E_DEV_ADMIN_*.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');
const { CAR_ID_STANDARD } = require('./fixtures.js');

const SAVE_URL = 'app/api/cars/save.php';
const BAD_NAME = 'tiny-1844.png';
const STORED_NAME = 'stored-1844.jpg';
const NOTICE = 'Remove these photos, then save again';

/**
 * A PNG signature followed by padding: 50 bytes, below the 100-byte minimum.
 *
 * @returns {Buffer}
 */
function tinyPng() {
    return Buffer.concat([Buffer.from('89504e470d0a1a0a', 'hex'), Buffer.alloc(42)]);
}

/**
 * Route save.php: fetchImages returns the given images, other reads go to the
 * server, and a save request is counted and answered with a success reply.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Array<object>} images
 * @returns {Promise<{saveCalls: () => number}>}
 */
async function routeSave(page, images) {
    let saveCalls = 0;
    await page.route('**/' + SAVE_URL, async (route, request) => {
        const postData = request.method() === 'POST' ? request.postData() || '' : '';
        if (postData.includes('fetchImages')) {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, images })
            });
            return;
        }
        if (request.method() === 'POST' && !postData.includes('removeImages')) {
            saveCalls += 1;
            // 422 keeps the page on the form, so the test needs no details page.
            await route.fulfill({
                status: 422,
                contentType: 'application/json',
                body: JSON.stringify({ success: false, message: 'mocked save' })
            });
            return;
        }
        await route.fallback();
    });
    return { saveCalls: () => saveCalls };
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function openEditForm(page) {
    await page.goto(`app/owner/cars/edit.php?car_id=${CAR_ID_STANDARD}`, { waitUntil: 'domcontentloaded' });
    expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');
    await page.waitForFunction(
        () => typeof window.FilePond !== 'undefined' && document.querySelector('.filepond--root') !== null,
        { timeout: 15000 }
    );
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function addTinyPhoto(page) {
    await page.locator('.filepond--browser').setInputFiles({
        name: BAD_NAME,
        mimeType: 'image/png',
        buffer: tinyPng()
    });
    await expect(page.locator('.filepond--file-status-main')).toHaveText('Photo is empty or damaged');
}

test.describe('Photo minimum size in the car edit form (#1844)', () => {
    test.beforeEach(async () => {
        test.skip(
            !process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD,
            'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests'
        );
    });

    test('a 50-byte PNG shows the label and sends no save request', async ({ page }) => {
        await ensureLoggedIn(page);
        const counter = await routeSave(page, []);
        await openEditForm(page);

        await addTinyPhoto(page);

        await expect(page.locator('.filepond--file-status-sub')).toContainText('Minimum size');
        // Give a stray request time to leave before the count is read.
        await page.waitForTimeout(500);
        expect(counter.saveCalls(), 'adding the file must not send a save request').toBe(0);
    });

    test('submit with the invalid photo names it, sends no request, and keeps the form', async ({ page }) => {
        await ensureLoggedIn(page);
        const counter = await routeSave(page, []);
        await openEditForm(page);
        await page.waitForFunction(
            () => (document.querySelector('#model') || {}).value.includes('|'),
            { timeout: 15000 }
        );
        const chassisBefore = await page.locator('#chassis').inputValue();
        expect(chassisBefore, 'fixture car must have a chassis').not.toBe('');
        await page.locator('#comments').fill('keep me 1844');
        await addTinyPhoto(page);

        const submitBtn = page.locator('#submit');
        await expect(submitBtn).toBeEnabled({ timeout: 15000 });
        await submitBtn.click();

        const message = page.locator('#message');
        await expect(message).toBeVisible();
        await expect(message).toContainText(NOTICE);
        await expect(message).toContainText(BAD_NAME);
        await page.waitForTimeout(500);
        expect(counter.saveCalls(), 'blocked submit must not send a save request').toBe(0);
        await expect(page.locator('#chassis')).toHaveValue(chassisBefore);
        await expect(page.locator('#comments')).toHaveValue('keep me 1844');
        await expect(submitBtn).toBeEnabled();
    });

    test('removing the invalid photo lets the save request go out', async ({ page }) => {
        await ensureLoggedIn(page);
        const counter = await routeSave(page, []);
        await openEditForm(page);
        await addTinyPhoto(page);

        await page.locator('.filepond--action-remove-item').first().click();
        await expect(page.locator('.filepond--item')).toHaveCount(0);

        const submitBtn = page.locator('#submit');
        await expect(submitBtn).toBeEnabled({ timeout: 15000 });
        await submitBtn.click();

        await expect.poll(() => counter.saveCalls(), { timeout: 20000 }).toBe(1);
        await expect(page.locator('#message')).not.toContainText(NOTICE);
    });

    test('a stored photo that fails to load does not block the submit', async ({ page }) => {
        await ensureLoggedIn(page);
        const counter = await routeSave(page, [{
            path: STORED_NAME,
            basename: STORED_NAME,
            size: 1234,
            type: 'image/jpeg'
        }]);
        await page.route('**/' + STORED_NAME, route => route.abort());
        await openEditForm(page);

        // The item must exist and be in the load-error state, or the test
        // would pass without the case it covers.
        await expect.poll(
            () => page.evaluate(() => {
                const root = document.querySelector('.filepond--root');
                const pond = window.FilePond.find(root);
                return pond.getFiles().map(f => [f.origin === window.FilePond.FileOrigin.LOCAL, f.status === window.FilePond.FileStatus.LOAD_ERROR]);
            }),
            { timeout: 10000 }
        ).toEqual([[true, true]]);

        const submitBtn = page.locator('#submit');
        await expect(submitBtn).toBeEnabled({ timeout: 15000 });
        await submitBtn.click();

        await expect.poll(() => counter.saveCalls(), { timeout: 20000 }).toBe(1);
        await expect(page.locator('#message')).not.toContainText(NOTICE);
    });
});
