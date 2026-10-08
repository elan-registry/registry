// edit.php updateCarDetails(): missing-car path (#1313) and the adjacent
// ownership-violation logout branch. security/car-update-ownership.spec.js
// covers save.php, a different code path.
//
// updateCarDetails() runs only on a POST with action=updateCar, so the tests
// POST directly. The ownership test needs a non-admin account
// (E2E_DEV_NONADMIN_USERNAME, owns no cars): admins bypass the logout branch.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, login, logout } = require('./auth-helper.js');
const { CAR_ID_NONEXISTENT, CAR_ID_WITH_HISTORY } = require('./fixtures.js');

test.describe('Car edit page — missing car (#1313)', () => {

    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    test('nonexistent car_id shows a not-found message, redirects to cars index, and does not log the user out', async ({ page }) => {
        // Token::check() validates against the session, so any page's token works.
        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });

        const initialUrl = page.url();
        test.skip(
            initialUrl.includes('login') || initialUrl.includes('Please Log In'),
            'Session not established locally — skipping missing-car assertion'
        );

        const csrfToken = await page.locator('#csrf').inputValue();
        expect(csrfToken, 'edit.php must render a #csrf hidden field to obtain a token from').toBeTruthy();

        // edit.php has already sent output, so Redirect::to() falls back to an
        // inline script redirect. Wait for the URL to change, not for the POST
        // response's DOMContentLoaded.
        await Promise.all([
            page.waitForURL(url => !url.toString().includes('/cars/edit.php'), { timeout: 15000 }),
            page.evaluate(({ csrf, carId }) => {
                const form = document.createElement('form');
                form.method = 'POST';
                // Self-submit: a relative "app/owner/cars/edit.php" action 404s from edit.php.
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
            }, { csrf: csrfToken, carId: CAR_ID_NONEXISTENT }),
        ]);

        await page.waitForLoadState('domcontentloaded');

        const currentUrl = page.url();
        test.skip(
            currentUrl.includes('login') || currentUrl.includes('Please Log In'),
            'Session not established locally — skipping missing-car assertion'
        );

        // The fix: before it, the exists() guard returned silently and the form rendered blank.
        expect(
            currentUrl,
            'A nonexistent car_id must redirect to cars/index.php, not stay on edit.php'
        ).toContain('cars/index.php');

        // usError() renders a UserSpice toast, not a Bootstrap .alert-danger.
        await expect(
            page.locator('.us-toast .toast-body', { hasText: 'This car could not be found.' }),
            'The "This car could not be found." flash message must be visible after redirect'
        ).toBeVisible();

        // Regression guard for the original bug: a missing car forced a logout (#1300).
        await page.goto('app/owner/cars/index.php', { waitUntil: 'domcontentloaded' });
        const postNavUrl = page.url();
        expect(
            postNavUrl.includes('login') || postNavUrl.includes('Please Log In'),
            'User must still be logged in after visiting edit.php with a missing car_id — ' +
            'landing on the login page would indicate the original force-logout regression'
        ).toBe(false);
    });

    test('genuine ownership violation on an existing car still logs the user out (edit.php:119-124)', async ({ page, browserName }) => {
        test.skip(browserName !== 'chromium', 'Login/logout dance only needs to run once, not per-browser-project');

        await logout(page);
        await login(page, process.env.E2E_DEV_NONADMIN_USERNAME, process.env.E2E_DEV_NONADMIN_PASSWORD);

        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });
        const prePostUrl = page.url();
        expect(
            prePostUrl.includes('login') || prePostUrl.includes('Please Log In'),
            'E2E_DEV_NONADMIN_USERNAME must be logged in before the ownership POST'
        ).toBe(false);

        // users/init.php sends a logged-in user with email_verified=0 to
        // users/verify.php on every page. That page has no #csrf field, so
        // without this check the failure shows as a #csrf timeout below.
        expect(
            prePostUrl.includes('users/verify.php'),
            `${process.env.E2E_DEV_NONADMIN_USERNAME} must have email_verified=1 locally — see ENVIRONMENT.md`
        ).toBe(false);

        const csrfToken = await page.locator('#csrf').inputValue();
        expect(csrfToken, 'edit.php must render a #csrf hidden field to obtain a token from').toBeTruthy();

        // page.request shares the cookie jar. The logout branch exits with no
        // redirect, so there is nothing to follow.
        await page.request.post('app/owner/cars/edit.php', {
            form: { csrf: csrfToken, action: 'updateCar', car_id: String(CAR_ID_WITH_HISTORY) },
        });

        // edit.php is private; cars/index.php is public and cannot show the auth state.
        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });
        const postNavUrl = page.url();
        const postNavContent = await page.textContent('body');
        expect(
            postNavUrl.includes('login') || postNavUrl.includes('Please Log In') || postNavContent.includes('Please Log In'),
            'A genuine ownership violation (non-owner, non-admin) POSTing action=updateCar to edit.php must log the user out — ' +
            'still being logged in here would mean edit.php:119-124\'s logout branch did not fire'
        ).toBe(true);
    });
});
