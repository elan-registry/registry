// edit.php loads a car from a GET car_id (#2608). The verification email
// links to edit.php?car_id=N, so this is the path an owner takes from email.
// A denied GET redirects and keeps the session: a crafted link must not log
// a member out. car-edit-missing-car.spec.js covers the POST logout.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, login, logout } = require('./auth-helper.js');
const { CAR_ID_OWNED_BY_ADMIN, CAR_ID_WITH_HISTORY } = require('./fixtures.js');

test.describe('Car edit page — GET car_id (#2608)', () => {

    test.describe('as the owner', () => {
        test.beforeEach(async ({ page }) => {
            // Without credentials login() uses a placeholder account; skip on the real cause.
            test.skip(
                !process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD,
                'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests'
            );
            await ensureLoggedIn(page);
        });

        test('GET with the owner\'s car_id opens the car in update mode', async ({ page }) => {
            await page.goto(`app/owner/cars/edit.php?car_id=${CAR_ID_OWNED_BY_ADMIN}`, { waitUntil: 'domcontentloaded' });

            expect(page.url(), 'edit.php must render for an authenticated session, not redirect').toContain('cars/edit.php');

            await expect(page.locator('#car_id'), '#car_id must hold the requested car').toHaveValue(String(CAR_ID_OWNED_BY_ADMIN));
            await expect(page.locator('#action'), 'A GET car_id must set update mode').toHaveValue('updateCar');
            await expect(page.locator('#car_id_display'), 'The Car ID row shows only in update mode').toBeVisible();
            await expect(page.locator('#car_id_display')).toHaveValue(String(CAR_ID_OWNED_BY_ADMIN));
            await expect(page.locator('.breadcrumb-item.active'), 'The breadcrumb must name the edit page').toHaveText(/Edit Car/);

            // The fixture car belongs to this account, so this is the owner path.
            await expect(
                page.getByText('Administrative Override Active'),
                `CAR_ID_OWNED_BY_ADMIN (${CAR_ID_OWNED_BY_ADMIN}) must be owned by E2E_DEV_ADMIN_USERNAME — see fixtures.js`
            ).toHaveCount(0);
        });

        for (const query of ['?car_id=0', '?car_id=abc', '']) {
            test(`GET edit.php${query || ' with no car_id'} shows the Add Car form`, async ({ page }) => {
                await page.goto(`app/owner/cars/edit.php${query}`, { waitUntil: 'domcontentloaded' });

                expect(page.url(), 'edit.php must render, not redirect').toContain('cars/edit.php');

                await expect(page.locator('#action'), 'No positive car_id must keep the Add Car form').toHaveValue('addCar');
                await expect(page.locator('#car_id')).toHaveValue('');
                await expect(page.locator('#car_id_display'), 'The Car ID row must not show on the Add Car form').toHaveCount(0);
            });
        }
    });

    test('GET of another owner\'s car as a non-admin redirects to the cars index and keeps the session', async ({ page }) => {
        // Without credentials login() uses a placeholder account; skip on the real cause.
        test.skip(
            !process.env.E2E_DEV_NONADMIN_USERNAME || !process.env.E2E_DEV_NONADMIN_PASSWORD,
            'Set E2E_DEV_NONADMIN_USERNAME and E2E_DEV_NONADMIN_PASSWORD in .env.local (non-admin account that owns no cars)'
        );

        await logout(page);
        await login(page, process.env.E2E_DEV_NONADMIN_USERNAME, process.env.E2E_DEV_NONADMIN_PASSWORD);

        // The response body must not hold the form for the other owner's car,
        // even before the redirect runs.
        const response = await page.request.get(`app/owner/cars/edit.php?car_id=${CAR_ID_WITH_HISTORY}`, { maxRedirects: 0 });
        const body = await response.text();
        expect(body, 'A denied GET must not render the edit form').not.toContain('id="editCar"');
        expect(body, 'A denied GET must not render the Car ID row').not.toContain('id="car_id_display"');

        await page.goto(`app/owner/cars/edit.php?car_id=${CAR_ID_WITH_HISTORY}`, { waitUntil: 'domcontentloaded' });
        await page.waitForURL(url => url.toString().includes('cars/index.php'), { timeout: 15000 });
        expect(page.url(), 'A denied GET must redirect to the cars index').toContain('app/owner/cars/index.php');

        await expect(
            page.locator('.us-toast .toast-body', { hasText: 'You can only edit your own cars.' }),
            'The denial message must show after the redirect'
        ).toBeVisible();

        // cars/index.php is public, so check the session on a private page.
        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });
        const postNavUrl = page.url();
        const postNavContent = await page.textContent('body');
        expect(
            postNavUrl.includes('login') || postNavUrl.includes('Please Log In') || postNavContent.includes('Please Log In'),
            'A denied GET must not log the user out — only the POST path does that'
        ).toBe(false);
        await expect(page.locator('#action'), 'The private page must render the Add Car form').toHaveValue('addCar');
    });
});
