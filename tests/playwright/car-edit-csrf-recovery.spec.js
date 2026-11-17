// Car form save recovery from a stale CSRF token or a lost session (#1455).
// A 403 triggers one token refresh and one more send. A 401 asks the owner to
// log in again. A 403 for another reason is not retried. Real save.php answers
// the failing calls. The call that must succeed and the token refresh replies
// that fail on purpose are mocked, so no real car is changed.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');
const { CAR_ID_WITH_HISTORY } = require('./fixtures.js');
const { makePhoto, SAVE_OK, openEditMode, routeSave } = require('./car-edit-helpers.js');

const LOGGED_OUT = 'You are logged out. Log in again in a new tab, then select Update Car again.';
const OUT_OF_DATE = 'This page is out of date. Your changes are still on this page. Select Update Car again.';
const TYPED_COMMENTS = 'typed before the token went stale';

/** Open Edit mode, wait for Save, and type a comment that must survive. */
async function openReadyEditPage(page) {
    await openEditMode(page, CAR_ID_WITH_HISTORY);
    await expect(page.locator('#submit')).toBeEnabled({ timeout: 15000 });
    await page.locator('#comments').fill(TYPED_COMMENTS);
}

/** Record each csrf-token.php request on the page. */
function countTokenRefreshes(page) {
    const state = { count: 0 };
    page.on('request', (request) => {
        if (request.url().includes('app/api/shared/csrf-token.php')) {
            state.count += 1;
        }
    });
    return state;
}

async function expectNoCsrfWording(page) {
    const text = await page.locator('body').innerText();
    expect(text, 'no visible text may mention CSRF').not.toMatch(/csrf/i);
}

test.describe('Car form save: CSRF and session recovery (#1455)', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(
            !process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD,
            'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests'
        );
        await ensureLoggedIn(page);
    });

    test('a stale token gets a real 403, one refresh, and one more send that succeeds', async ({ page }) => {
        const refreshes = countTokenRefreshes(page);
        const saves = await routeSave(page, (call) => (call.number === 1 ? 'pass' : SAVE_OK));
        await openReadyEditPage(page);
        await page.locator('#csrf').evaluate((el) => { el.value = 'stale'; });
        // The unsaved-changes guard must also turn off when the save succeeds
        // on the retry, not only on the first send.
        const dialogs = [];
        page.on('dialog', (dialog) => {
            dialogs.push(dialog.type());
            dialog.accept();
        });

        await page.locator('#submit').click();
        await page.waitForURL(new RegExp(`app/owner/cars/details\\.php\\?car_id=${CAR_ID_WITH_HISTORY}(?:&|$)`), { timeout: 15000 });

        expect(dialogs, 'a save that succeeds on the retry leaves without a dialog').toEqual([]);
        expect(saves.calls.map((c) => c.csrf)[0], 'the first send carries the stale token').toBe('stale');
        expect(saves.calls, 'one send, one retry').toHaveLength(2);
        expect(saves.calls[1].csrf, 'the retry carries the refreshed token').not.toBe('stale');
        expect(saves.calls[1].csrf).toBeTruthy();
        expect(refreshes.count).toBe(1);
    });

    test('a real 401 shows the logged-out message, keeps the fields, and asks for no token', async ({ page, context }) => {
        const refreshes = countTokenRefreshes(page);
        const saves = await routeSave(page, () => 'pass');
        await openReadyEditPage(page);
        await context.clearCookies();

        await page.locator('#submit').click();

        await expect(page.locator('#message')).toContainText(LOGGED_OUT);
        expect(saves.calls, 'one send, no retry').toHaveLength(1);
        expect(refreshes.count, 'a 401 must not call csrf-token.php').toBe(0);
        await expect(page.locator('#comments')).toHaveValue(TYPED_COMMENTS);
        await expect(page.locator('#submit'), 'Save works again').toBeEnabled();
        await expectNoCsrfWording(page);
    });

    test('after a new login in another tab, Save again refreshes the token and succeeds', async ({ page, context }) => {
        const saves = await routeSave(page, (call) => (call.number <= 2 ? 'pass' : SAVE_OK));
        await openReadyEditPage(page);
        await context.clearCookies();
        await page.locator('#submit').click();
        await expect(page.locator('#message')).toContainText(LOGGED_OUT);

        const otherTab = await context.newPage();
        await ensureLoggedIn(otherTab);
        await otherTab.close();

        await page.locator('#submit').click();
        await page.waitForURL(new RegExp(`app/owner/cars/details\\.php\\?car_id=${CAR_ID_WITH_HISTORY}(?:&|$)`), { timeout: 15000 });

        // Send 1: 401. Send 2: real 403 for the old token. Send 3: new token, mocked success.
        expect(saves.calls).toHaveLength(3);
        expect(saves.calls[2].csrf).not.toBe(saves.calls[1].csrf);
    });

    test('a token that still fails is not retried again', async ({ page }) => {
        await page.route('**/app/api/shared/csrf-token.php', (route) =>
            route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, csrf: 'still-bad' }) })
        );
        const saves = await routeSave(page, () => 'pass');
        await openReadyEditPage(page);
        await page.locator('#csrf').evaluate((el) => { el.value = 'stale'; });

        await page.locator('#submit').click();

        await expect(page.locator('#message')).toContainText(OUT_OF_DATE);
        await expect(page.locator('#message')).toContainText('copy your comments, then reload the page');
        expect(saves.calls.map((c) => c.csrf)).toEqual(['stale', 'still-bad']);
        await expect(page.locator('#comments')).toHaveValue(TYPED_COMMENTS);
        await expect(page.locator('#submit')).toBeEnabled();
        await expectNoCsrfWording(page);
    });

    test('a refresh that returns 401 shows the logged-out message after one send', async ({ page }) => {
        await page.route('**/app/api/shared/csrf-token.php', (route) =>
            route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'Login required' }) })
        );
        const saves = await routeSave(page, () => 'pass');
        await openReadyEditPage(page);
        await page.locator('#csrf').evaluate((el) => { el.value = 'stale'; });

        await page.locator('#submit').click();

        await expect(page.locator('#message')).toContainText(LOGGED_OUT);
        expect(saves.calls).toHaveLength(1);
        await expectNoCsrfWording(page);
    });

    test('a refresh that fails with a server error shows the out-of-date message after one send', async ({ page }) => {
        await page.route('**/app/api/shared/csrf-token.php', (route) =>
            route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false }) })
        );
        const saves = await routeSave(page, () => 'pass');
        await openReadyEditPage(page);
        await page.locator('#csrf').evaluate((el) => { el.value = 'stale'; });

        await page.locator('#submit').click();

        await expect(page.locator('#message')).toContainText(OUT_OF_DATE);
        expect(saves.calls).toHaveLength(1);
        await expectNoCsrfWording(page);
    });

    test('other statuses keep their own handling: 413 still shows "Submission failed."', async ({ page }) => {
        const saves = await routeSave(page, () => ({ status: 413, body: { success: false } }));
        await openReadyEditPage(page);

        await page.locator('#submit').click();

        await expect(page.locator('#message')).toContainText('Submission failed.');
        expect(saves.calls).toHaveLength(1);
    });

    test('a permission 403 is not retried', async ({ page }) => {
        const refreshes = countTokenRefreshes(page);
        const saves = await routeSave(page, () => ({ status: 403, body: { success: false, message: 'Unauthorized' } }));
        await openReadyEditPage(page);

        await page.locator('#submit').click();

        await expect(page.locator('#message')).toContainText('Submission failed.');
        await expect(page.locator('#message')).not.toContainText('out of date');
        expect(saves.calls, 'one send, no retry').toHaveLength(1);
        expect(refreshes.count, 'a permission 403 must not call csrf-token.php').toBe(0);
        await expect(page.locator('#comments')).toHaveValue(TYPED_COMMENTS);
    });

    test('a retry after a token 403 still carries the new photo', async ({ page }) => {
        const filesPerSave = [];
        const saves = await routeSave(page, (call) => (call.number === 1 ? 'pass' : SAVE_OK));
        // Registered after routeSave, so it runs first and passes each call on.
        await page.route('**/app/api/cars/save.php', async (route, request) => {
            const data = request.postData() || '';
            if (request.method() === 'POST' && !data.includes('fetchImages') && !data.includes('removeImages')) {
                const buf = request.postDataBuffer();
                // Latin1 keeps the byte count of the binary part intact for the length check.
                const text = buf ? buf.toString('latin1') : '';
                const parts = text.split(/--[-\w]+\r\n/).filter((part) => part.includes('name="file[]"'));
                filesPerSave.push(parts.map((part) => {
                    const [head, ...rest] = part.split('\r\n\r\n');
                    return { head, size: rest.join('\r\n\r\n').replace(/\r\n(--[-\w]+--)?\r?\n?$/, '').length };
                }));
            }
            await route.fallback();
        });
        await openReadyEditPage(page);
        await page.locator('input.filepond--browser').setInputFiles({ name: 'retry.jpg', mimeType: 'image/jpeg', buffer: await makePhoto(page) });
        await page.waitForFunction(() => {
            const pond = window.FilePond.find(document.querySelector('.filepond--root'));
            return pond.getFiles().some((f) => f.origin !== window.FilePond.FileOrigin.LOCAL && f.status === window.FilePond.FileStatus.IDLE);
        }, null, { timeout: 15000 });
        await page.locator('#csrf').evaluate((el) => { el.value = 'stale'; });

        await page.locator('#submit').click();
        await page.waitForURL(new RegExp(`app/owner/cars/details\\.php\\?car_id=${CAR_ID_WITH_HISTORY}(?:&|$)`), { timeout: 15000 });

        expect(saves.calls, 'one send, one retry').toHaveLength(2);
        expect(filesPerSave).toHaveLength(2);
        const retryFiles = filesPerSave[1];
        const photo = retryFiles.find((f) => f.head.includes('filename="retry.jpg"'));
        expect(photo, 'the retry body has a file[] part for retry.jpg, not only the empty blob').toBeDefined();
        expect(photo.size, 'the photo part is not empty').toBeGreaterThan(0);
    });
});
