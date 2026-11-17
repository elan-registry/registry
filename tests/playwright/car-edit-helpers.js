// Shared helpers for the car edit form specs (edit.php).

const { expect } = require('@playwright/test');
const { CAR_ID_WITH_HISTORY } = require('./fixtures.js');

// 1x1 PNG. FilePond accepts it as a new (non-LOCAL) file.
const TINY_PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64'
);

/**
 * Build a small JPEG in the browser. It is larger than TINY_PNG, so it passes
 * the form's minimum photo size.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<Buffer>}
 */
async function makePhoto(page) {
    const base64 = await page.evaluate(async () => {
        const canvas = document.createElement('canvas');
        canvas.width = 320;
        canvas.height = 240;
        const ctx = canvas.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 320, 240);
        gradient.addColorStop(0, '#1d4e89');
        gradient.addColorStop(1, '#f6c85f');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, 320, 240);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        const bytes = new Uint8Array(await blob.arrayBuffer());
        let binary = '';
        bytes.forEach((b) => { binary += String.fromCharCode(b); });
        return btoa(binary);
    });
    return Buffer.from(base64, 'base64');
}

/**
 * Submit the hidden Edit-mode form (action=updateCar, car_id, csrf) from the
 * page that is open now. This POST puts edit.php in real update mode (#1846).
 * A GET ?car_id link also does (#2608), but these specs test the POST path.
 * The CSRF token is read from the current page.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number|string} carId
 * @returns {Promise<void>} Resolves when the new page has editCarConfig
 */
async function postUpdateCar(page, carId) {
    const csrf = await page.locator('#csrf').inputValue();
    expect(csrf, 'edit.php must render a #csrf hidden field to obtain a token from').toBeTruthy();

    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.evaluate(({ csrf, carId }) => {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.pathname;
            const fields = { csrf, action: 'updateCar', car_id: String(carId) };
            for (const [name, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }, { csrf, carId }),
    ]);

    // A bare evaluate() right after the POST can hit "Execution context was destroyed".
    await page.waitForFunction(() => typeof window.editCarConfig !== 'undefined', { timeout: 15000 });
}

/**
 * Open edit.php in Add mode and wait for the page-ready handler.
 */
async function openAddMode(page) {
    await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });
    expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');
    await page.waitForFunction(
        () => typeof window.FilePond !== 'undefined' && document.querySelector('.filepond--root') !== null,
        { timeout: 15000 }
    );
}

/**
 * Open edit.php in Edit mode for a car through the real POST.
 */
async function openEditMode(page, carId) {
    await openAddMode(page);
    await postUpdateCar(page, carId);
    expect(
        await page.evaluate(() => window.editCarConfig?.isUpdate === true),
        'POST with action=updateCar must render edit.php in real update mode'
    ).toBe(true);
}

/**
 * Route save.php so each real save POST is answered by the mock. Photo calls
 * (fetchImages, removeImages) pass through to the server.
 *
 * @param {import('@playwright/test').Page} page
 * @param {(call: {number: number, csrf: string|null, model: string|null}) => ({status: number, body: object}|'pass')} respond
 *   Decides the reply to the Nth save POST ('pass' sends it to the real server)
 * @returns {Promise<{calls: Array<{number: number, csrf: string|null, model: string|null}>}>}
 */
async function routeSave(page, respond) {
    const state = { calls: [] };
    await page.route('**/app/api/cars/save.php', async (route, request) => {
        const postData = request.postData() || '';
        if (request.method() !== 'POST' || postData.includes('fetchImages') || postData.includes('removeImages')) {
            await route.fallback();
            return;
        }
        const field = (name) => {
            const m = postData.match(new RegExp('name="' + name + '"\\r\\n\\r\\n([^\\r]*)'));
            return m ? m[1] : null;
        };
        const call = { number: state.calls.length + 1, csrf: field('csrf'), model: field('model') };
        state.calls.push(call);
        const reply = respond(call);
        if (reply === 'pass') {
            await route.fallback();
            return;
        }
        await route.fulfill({
            status: reply.status,
            contentType: 'application/json',
            body: JSON.stringify(reply.body),
        });
    });
    return state;
}

/**
 * Fail models.php with a 500 until the test sets `state.fail = false`;
 * after that, requests reach the real server.
 */
async function routeModels(page) {
    const state = { fail: true };
    await page.route('**/app/api/cars/models.php', async (route) => {
        if (state.fail) {
            await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false }) });
        } else {
            await route.fallback();
        }
    });
    return state;
}

/**
 * Try to close the page the way a user leaving it would, and report whether
 * the browser asked first. A dialog is dismissed, so the page stays open.
 *
 * @returns {Promise<{dialogs: string[], closed: boolean}>}
 */
async function attemptLeave(page) {
    const dialogs = [];
    page.on('dialog', (dialog) => {
        dialogs.push(dialog.type());
        dialog.dismiss();
    });
    const outcome = new Promise((resolve) => {
        page.once('dialog', () => setTimeout(() => resolve('dialog'), 300));
        page.once('close', () => resolve('closed'));
    });
    await page.close({ runBeforeUnload: true });
    const result = await outcome;
    return { dialogs, closed: result === 'closed' };
}

// Chromium shows a beforeunload prompt only after a user gesture. Without one,
// a "no dialog" result would prove nothing.
async function giveUserGesture(page) {
    await page.locator('body').click({ position: { x: 2, y: 2 } });
}

const SAVE_OK = { status: 200, body: { success: true, cardetails: { id: CAR_ID_WITH_HISTORY } } };

module.exports = { TINY_PNG, makePhoto, SAVE_OK, attemptLeave, giveUserGesture, postUpdateCar, openAddMode, openEditMode, routeSave, routeModels };
