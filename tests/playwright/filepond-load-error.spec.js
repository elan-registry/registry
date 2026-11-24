// Regression tests for issue #1031: fetchImages API-level failures during
// edit-mode hydration (a success:false response or a network rejection) show a
// warning banner and disable submit. Network rejections are logged via
// console.error.
//
// Strategy: page.route() intercepts the edit.php HTML response to inject a
// fake car_id (no real local car required), then mocks the fetchImages API to
// fail.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

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
        test.skip(url.includes('login') || bodyText.includes('Please Log In'), 'Authenticated session required');

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
        test.skip(url.includes('login') || bodyText.includes('Please Log In'), 'Authenticated session required');

        await expect(page.locator('#message .alert-warning')).toBeVisible({ timeout: 8000 });
        await expect(page.locator('#submit')).toBeDisabled();
        await expect.poll(
            () => consoleErrors.some(e => e.includes('[edit.php] fetchImages API failure')),
            { timeout: 3000 }
        ).toBe(true);
    });

});
