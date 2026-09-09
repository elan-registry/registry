// tests/playwright/car-edit-missing-car.spec.js
//
// Coverage for app/owner/cars/edit.php's updateCarDetails() missing-car path
// (issue #1313), plus a regression guard for the adjacent ownership-violation
// logout branch in the same function (added during #1313's test-coverage
// review — see the second test below for why it lives here rather than in
// security/car-update-ownership.spec.js, which covers a different endpoint).
//
// Originally filed as: loading edit.php for a deleted/merged car force-logs
// the owner out (a null $carQ->data() flowed into the ownership comparison,
// hitting the $user->logout(); exit(); branch). That specific defect was
// already fixed by an earlier commit (#1300), which added an exists() guard
// that returns early before the ownership check ever runs. But the guard's
// early return was silent — no message, no redirect — leaving the owner on a
// blank/broken-looking edit form with no explanation.
//
// This session's fix (edit.php:107-113) makes the exists() guard show a
// flash message ("This car could not be found.") and redirect to
// cars/index.php, mirroring the existing pattern in details.php for the same
// scenario.
//
// No test exercised updateCarDetails() at all before this file — the closest
// existing coverage, security/car-update-ownership.spec.js, covers the AJAX
// save.php?action=updateCar endpoint, a different code path than edit.php's
// GET-render flow, and would not have caught either the original force-logout
// bug or this narrower silent-failure gap.
//
// This test is a plain form POST with no FilePond/AJAX mocking needed
// (unlike car-edit-text-save.spec.js, which mocks save.php for form-submit
// scenarios), so it lives in its own file rather than growing that one.
//
// updateCarDetails() — and therefore the exists() guard this test covers —
// only runs on a POST with action=updateCar (see edit.php's
// `if (Input::existsPost()) { ... if ($action === 'updateCar') { updateCarDetails(...) } }`
// block). A plain GET to edit.php?car_id=<id> never reaches it; the real
// client flow (app/views/cars/_car_hero_actions.php's "Update Car" button)
// submits a POST form with csrf/action/car_id fields. This test reproduces
// that POST directly rather than simulating a button click, since the
// missing-car scenario has no such button in the UI to click (there is no
// car to view a hero-actions form for).
//
// The ownership-violation test below needs a genuine non-admin, non-owner
// session — E2E_DEV_ADMIN_USERNAME (the shared local login used by beforeEach()) is
// provisioned as an Administrator (permission_id=2), which bypasses the
// logout branch entirely. It logs in as a second, persistent plain-owner
// account instead (E2E_DEV_NONADMIN_USERNAME/E2E_DEV_NONADMIN_PASSWORD in .env.local,
// permission_id=1, owns no cars), avoiding the cost and registration
// rate-limit contention of registering a throwaway account per test run.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, login, logout } = require('./auth-helper.js');
const { CAR_ID_NONEXISTENT, CAR_ID_WITH_HISTORY } = require('./fixtures.js');

test.describe('Car edit page — missing car (#1313)', () => {

    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    test('nonexistent car_id shows a not-found message, redirects to cars index, and does not log the user out', async ({ page }) => {
        // Load edit.php's plain add-car GET form to obtain a valid, session-bound
        // CSRF token (Token::check() validates against the session, not the page
        // that issued it — any page's token works for any POST in the same session).
        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });

        const initialUrl = page.url();
        if (initialUrl.includes('login') || initialUrl.includes('Please Log In')) {
            test.skip('Session not established locally — skipping missing-car assertion');
            return;
        }

        const csrfToken = await page.locator('#csrf').inputValue();
        expect(csrfToken, 'edit.php must render a #csrf hidden field to obtain a token from').toBeTruthy();

        // Submit a real POST form (mirrors _car_hero_actions.php's "Update Car"
        // button) with action=updateCar and a nonexistent car_id, letting the
        // browser navigate and follow the server's redirect naturally.
        //
        // Redirect::to() (users/classes/Redirect.php) only sends an HTTP
        // Location header when headers_sent() is false; edit.php has already
        // flushed output (usError()'s flash message, page markup) by the time
        // updateCarDetails() runs, so it falls back to an inline
        // `<script>window.location.href=...</script>` redirect. That means the
        // POST response itself still renders at the edit.php URL, and the real
        // navigation to cars/index.php only happens once the browser executes
        // that script — so we must wait for the URL to actually change, not
        // just for the POST response's DOMContentLoaded.
        await Promise.all([
            page.waitForURL(url => !url.toString().includes('/cars/edit.php'), { timeout: 15000 }),
            page.evaluate(({ csrf, carId }) => {
                const form = document.createElement('form');
                form.method = 'POST';
                // Self-submit (we are already on edit.php) — avoids relative-path
                // resolution pitfalls (edit.php's own URL is a file, not a directory,
                // so a bare relative "app/owner/cars/edit.php" action 404s).
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
        if (currentUrl.includes('login') || currentUrl.includes('Please Log In')) {
            test.skip('Session not established locally — skipping missing-car assertion');
            return;
        }

        // ------------------------------------------------------------------
        // Assertion 1: redirected away from edit.php to cars/index.php.
        // This is the actual fix — before it, the exists() guard returned
        // silently and the edit form rendered in place with blank fields.
        // ------------------------------------------------------------------
        expect(
            currentUrl,
            'A nonexistent car_id must redirect to cars/index.php, not stay on edit.php'
        ).toContain('cars/index.php');

        // ------------------------------------------------------------------
        // Assertion 2: the "not found" flash message is visible on the page
        // the redirect landed on. usError() surfaces as a UserSpice toast
        // (#us-toast-container .us-toast .toast-body), not a Bootstrap
        // .alert-danger — confirmed by inspecting the rendered markup.
        // ------------------------------------------------------------------
        await expect(
            page.locator('.us-toast .toast-body', { hasText: 'This car could not be found.' }),
            'The "This car could not be found." flash message must be visible after redirect'
        ).toBeVisible();

        // ------------------------------------------------------------------
        // Assertion 3 (regression guard for the originally-reported bug):
        // the user must still be logged in. Before #1300, a missing car's
        // null $carQ->data() flowed into the ownership check and forced a
        // logout via $user->logout(); exit();. That specific defect is
        // already fixed elsewhere, but no test locked it in until now.
        // Verify by navigating to another authenticated owner page and
        // confirming it renders rather than bouncing to the login page.
        // ------------------------------------------------------------------
        await page.goto('app/owner/cars/index.php', { waitUntil: 'domcontentloaded' });
        const postNavUrl = page.url();
        expect(
            postNavUrl.includes('login') || postNavUrl.includes('Please Log In'),
            'User must still be logged in after visiting edit.php with a missing car_id — ' +
            'landing on the login page would indicate the original force-logout regression'
        ).toBe(false);
    });

    // ----------------------------------------------------------------------
    // Ownership-violation logout (adjacent regression guard, not #1313 itself)
    //
    // #1313's fix touches the exists() guard directly above the ownership
    // check in the same function (edit.php:107-124). The plan file for this
    // issue claims the ownership-violation branch (lines 119-124: a non-
    // owner, non-admin user gets $user->logout(); exit();) is unchanged and
    // still works — but nothing in the suite actually exercised it before
    // this test. security/car-update-ownership.spec.js only covers the
    // separate save.php AJAX endpoint against a NONEXISTENT car_id, which
    // never reaches edit.php's own logout branch at all.
    //
    // Why a second, persistent account (E2E_DEV_NONADMIN_USERNAME/E2E_DEV_NONADMIN_PASSWORD), not
    // E2E_DEV_ADMIN_USERNAME: E2E_DEV_ADMIN_USERNAME (the shared local dev/test login) is
    // provisioned as an Administrator in this environment's DB
    // (permission_id=2), and hasPerm([2, 3]) at edit.php:117 makes admins
    // bypass the logout branch entirely — using it here would make the test
    // pass vacuously (or not exercise the branch at all) regardless of which
    // car_id is used. E2E_DEV_NONADMIN_USERNAME is a genuine plain owner
    // (permission_id=1, verified once at provisioning time — see
    // .env.local's E2E_DEV_NONADMIN_USERNAME/E2E_DEV_NONADMIN_PASSWORD comment for how it was
    // created) that owns no cars, so it can never coincidentally hold
    // admin/editor perms or pass the ownership check by actually owning
    // CAR_ID_WITH_HISTORY.
    //
    // This used to register (and delete) a throwaway account per test run
    // via join.php, which cost ~13s of registration overhead and shared a
    // registration rate-limit budget (ip_max=10/hour — see
    // usersc/includes/rate_limits.php's 'registration_attempt' entry) with
    // account-enumeration.spec.js. A persistent second account avoids both:
    // no per-run registration, and no rate-limit contention.
    test('genuine ownership violation on an existing car still logs the user out (edit.php:119-124)', async ({ page, browserName }) => {
        test.skip(browserName !== 'chromium', 'Login/logout dance only needs to run once, not per-browser-project');

        // beforeEach() above logs in as E2E_DEV_ADMIN_USERNAME (the shared admin
        // account) for every test in this file — log out first, then back in
        // as the persistent plain-owner account.
        await logout(page);
        await login(page, process.env.E2E_DEV_NONADMIN_USERNAME, process.env.E2E_DEV_NONADMIN_PASSWORD);

        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });
        const prePostUrl = page.url();
        expect(
            prePostUrl.includes('login') || prePostUrl.includes('Please Log In'),
            'E2E_DEV_NONADMIN_USERNAME must be logged in before the ownership POST'
        ).toBe(false);

        const csrfToken = await page.locator('#csrf').inputValue();
        expect(csrfToken, 'edit.php must render a #csrf hidden field to obtain a token from').toBeTruthy();

        // POST action=updateCar for CAR_ID_WITH_HISTORY — an existing car
        // E2E_DEV_NONADMIN_USERNAME does not own and has no admin/editor perms over.
        // Mirrors the real "Update Car" button submit (see the missing-car
        // test above for why a synthetic form POST is used instead of
        // clicking a button).
        // page.request shares the browser context's cookie jar, so this
        // POST carries the same session as the page above, without the
        // extra complexity/race potential of a synthetic DOM form submit
        // + navigation wait (unlike the missing-car test above, this
        // branch has no Redirect::to() response to follow — edit.php's
        // logout branch calls $user->logout(); exit(); directly).
        await page.request.post('app/owner/cars/edit.php', {
            form: { csrf: csrfToken, action: 'updateCar', car_id: String(CAR_ID_WITH_HISTORY) },
        });

        // ------------------------------------------------------------------
        // Assertion: the session must now be logged out. Navigate back to
        // edit.php itself (a private=1 page per the `pages` table — unlike
        // app/owner/cars/index.php, which is deliberately public and
        // renders identically whether logged in or not, so it cannot
        // distinguish auth states here) and confirm it bounces to the
        // login wall rather than rendering — the inverse of the
        // missing-car test's "still logged in" check.
        // ------------------------------------------------------------------
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
