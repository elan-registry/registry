// tests/playwright/filepond-load-error.spec.js
//
// Regression tests for issue #755: FilePond image load errors during edit-mode
// hydration caused a full-form lockout (submit disabled, no per-file feedback).
//
// Fix (#755): Per-file .catch() in the hydration chain absorbs individual load
// errors, shows a non-blocking banner, and preserves submit button state. The
// submit handler no longer blocks on LOAD_ERROR (only PROCESSING_ERROR blocks).
//
// Fix (#1031): fetchImages API-level failures (success:false response or network
// rejection) now show the same warning banner and disable submit, and network
// rejections are logged via console.error.
//
// What these tests verify:
//   - Error banner appears when an existing image fails to load (not a full lockout)
//   - Submit button remains enabled after a photo load failure
//   - Removing the failed photo item leaves the form in a submittable state
//   - Warning banner and submit-disable when fetchImages returns success:false
//   - console.error is called and submit is disabled on fetchImages network failure
//
// Strategy: page.route() intercepts the edit.php HTML response to inject a
// fake car_id (no real MAMP car required), mocks the fetchImages API to return
// one fake image path, and aborts the image fetch so FilePond gets a LOAD_ERROR.
//
// Requires local MAMP at http://localhost:9999/elan-registry

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

const FAKE_IMAGE_FILENAME = 'fake-image-filepond-755.jpg';

const FETCH_IMAGES_RESPONSE = JSON.stringify({
    success: true,
    images: [{
        path: FAKE_IMAGE_FILENAME,
        basename: FAKE_IMAGE_FILENAME,
        size: 1234,
        type: 'image/jpeg',
    }]
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Intercept the edit.php HTML response and inject a fake car_id so the
 * fetchImages hydration block fires without needing a real car in the DB.
 * Call before page.goto(); each test sets its own fetchImages API mock.
 */
async function injectFakeCarId(page) {
    await page.route('**/app/owner/cars/edit.php', async (route) => {
        const response = await route.fetch();
        const rawBody = await response.text();
        // Inject a fake car_id into the hidden input so the fetchImages
        // hydration block fires without needing a real car in the database.
        const modifiedBody = rawBody.replace(
            'name="car_id" id="car_id" value=""',
            'name="car_id" id="car_id" value="9999"'
        );
        await route.fulfill({
            status: response.status(),
            contentType: 'text/html; charset=utf-8',
            headers: Object.fromEntries(
                Object.entries(response.headers()).filter(
                    ([k]) => k !== 'content-encoding' && k !== 'content-length'
                )
            ),
            body: modifiedBody,
        });
    });
}

/**
 * Navigate to the edit-car form with a fake car_id injected into the HTML
 * and a mocked fetchImages API response that returns one image entry.
 * The image fetch itself is NOT mocked here — each test provides its own route.
 * Returns false if an authenticated session is not active.
 */
async function gotoEditFormWithFakeImages(page) {
    await injectFakeCarId(page);

    // Mock any fetchImages POST to return one fake image entry (the HTML
    // interceptor ensures only car_id 9999 reaches this point in tests).
    await page.route('**/app/api/cars/save.php', async (route, request) => {
        const post = request.postData() || '';
        if (post.includes('fetchImages')) {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: FETCH_IMAGES_RESPONSE,
            });
        } else {
            await route.continue();
        }
    });

    await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });

    const url = page.url();
    const bodyText = await page.textContent('body').catch(() => '');
    if (url.includes('login') || bodyText.includes('Please Log In')) {
        return false;
    }
    return true;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('FilePond load error recovery (#755)', () => {

    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    test('error banner appears when an existing photo fails to load', async ({ page }) => {
        await page.route('**/' + FAKE_IMAGE_FILENAME, (route) => route.abort());

        const ready = await gotoEditFormWithFakeImages(page);
        if (!ready) {
            test.skip(true, 'Authenticated session required');
        }

        await expect(page.locator('#message .alert-warning')).toBeVisible({ timeout: 8000 });
        await expect(page.locator('#message .alert-warning')).toContainText('could not be loaded');
    });

    test('submit button remains enabled after a photo load failure', async ({ page }) => {
        await page.route('**/' + FAKE_IMAGE_FILENAME, (route) => route.abort());

        const ready = await gotoEditFormWithFakeImages(page);
        if (!ready) {
            test.skip(true, 'Authenticated session required');
        }

        await expect(page.locator('#message .alert-warning')).toBeVisible({ timeout: 8000 });
        await expect(page.locator('#message .alert-warning')).toContainText('could not be loaded');
        await expect(page.locator('#submit')).toBeEnabled();
    });

    test('removing the failed photo item leaves the form submittable', async ({ page }) => {
        await page.route('**/' + FAKE_IMAGE_FILENAME, (route) => route.abort());

        const ready = await gotoEditFormWithFakeImages(page);
        if (!ready) {
            test.skip(true, 'Authenticated session required');
        }

        await expect(page.locator('#message .alert-warning')).toBeVisible({ timeout: 8000 });

        const removeBtn = page.locator('.filepond--action-remove-item').first();
        await expect(removeBtn).toBeVisible({ timeout: 5000 });
        await removeBtn.click();

        await expect(page.locator('#submit')).toBeEnabled();
    });

});

// ---------------------------------------------------------------------------
// fetchImages API-level failure handling (#1031)
// ---------------------------------------------------------------------------

test.describe('fetchImages API failure handling (#1031)', () => {

    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    test('warning banner appears and submit is disabled when fetchImages returns success:false', async ({ page }) => {
        await injectFakeCarId(page);

        await page.route('**/app/api/cars/save.php', async (route, request) => {
            const post = request.postData() || '';
            if (post.includes('fetchImages')) {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({ success: false, message: 'Internal error' }),
                });
            } else {
                await route.continue();
            }
        });

        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });

        const url = page.url();
        const bodyText = await page.textContent('body').catch(() => '');
        if (url.includes('login') || bodyText.includes('Please Log In')) {
            test.skip(true, 'Authenticated session required');
        }

        await expect(page.locator('#message .alert-warning')).toBeVisible({ timeout: 8000 });
        await expect(page.locator('#message .alert-warning')).toContainText('could not be loaded');
        await expect(page.locator('#submit')).toBeDisabled();
    });

    test('console.error is called and submit is disabled when fetchImages network call fails', async ({ page }) => {
        const consoleErrors = [];
        page.on('console', msg => {
            if (msg.type() === 'error') consoleErrors.push(msg.text());
        });

        await injectFakeCarId(page);

        await page.route('**/app/api/cars/save.php', async (route, request) => {
            const post = request.postData() || '';
            if (post.includes('fetchImages')) {
                await route.abort();
            } else {
                await route.continue();
            }
        });

        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });

        const url = page.url();
        const bodyText = await page.textContent('body').catch(() => '');
        if (url.includes('login') || bodyText.includes('Please Log In')) {
            test.skip(true, 'Authenticated session required');
        }

        await expect(page.locator('#message .alert-warning')).toBeVisible({ timeout: 8000 });
        await expect(page.locator('#submit')).toBeDisabled();
        await expect.poll(
            () => consoleErrors.some(e => e.includes('[edit.php] fetchImages API failure')),
            { timeout: 3000 }
        ).toBe(true);
    });

});
