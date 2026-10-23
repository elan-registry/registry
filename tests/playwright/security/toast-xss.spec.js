const { test, expect } = require('@playwright/test');
const { login } = require('../auth-helper.js');
const { runPhpFixture } = require('../fixture-runner.js');

/**
 * Toast HTML-injection guard (#2439).
 *
 * The toast renderer in usersc/includes/system_messages_footer.php decodes
 * entities with DOMParser and must escape the result again before it assigns
 * it to innerHTML. A message that holds entity-encoded markup must show as
 * text and must never create an element.
 *
 * Section 1 uses a public page, no login. Section 2 seeds two owners whose
 * last names look like entity-encoded markup, sends an admin contact message
 * to each, and checks the success toast.
 *
 * Toasts auto-hide after 6 seconds, so every check runs right after the toast
 * shows.
 *
 * @group security
 * @group xss
 */

const PUBLIC_PAGE = 'index.php';
const TOAST_BODY = '.us-toast .toast-body';
const SUCCESS_TOAST_BODY = '.us-toast:has(.us-bar-success) .toast-body';

// Log in explicitly in section 2. Section 1 needs no session.
test.use({ storageState: { cookies: [], origins: [] } });

/** Open the public page with a ?err= message and wait for the toast. */
async function openWithErr(page, rawMessage) {
    await page.goto(`${PUBLIC_PAGE}?err=${encodeURIComponent(rawMessage)}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator(TOAST_BODY).first()).toBeVisible();
}

/** Assert the payload created no element, by id and as any img in a toast. */
async function expectNoInjectedImg(page, id) {
    await expect(page.locator(`#${id}`)).toHaveCount(0);
    await expect(page.locator(`${TOAST_BODY} img`)).toHaveCount(0);
}

/** Open the public page and call window.usError() with a message. */
async function openAndUsError(page, message) {
    await page.goto(PUBLIC_PAGE, { waitUntil: 'domcontentloaded' });
    await page.evaluate((msg) => window.usError(msg), message);
    await expect(page.locator(TOAST_BODY).first()).toBeVisible();
}

test.describe('Toast renderer does not create elements from entity text (#2439)', () => {
    test('?err= with double-encoded markup shows the one-level entity text', async ({ page }) => {
        await openWithErr(page, '&amp;lt;img id=xss2439a src=x onerror=x&amp;gt;');

        await expectNoInjectedImg(page, 'xss2439a');
        await expect(page.locator(TOAST_BODY).first()).toContainText('&lt;img');
    });

    test('?err= with single-encoded markup shows the tag as text', async ({ page }) => {
        await openWithErr(page, '&lt;img id=xss2439b src=x&gt;');

        await expectNoInjectedImg(page, 'xss2439b');
        await expect(page.locator(TOAST_BODY).first()).toContainText('<img');
    });

    test('usError() with single-encoded markup shows the tag as text', async ({ page }) => {
        await openAndUsError(page, '&lt;img id=xss2439c src=x&gt;');

        await expectNoInjectedImg(page, 'xss2439c');
        await expect(page.locator(TOAST_BODY).first()).toContainText('<img');
    });

    test('usError() keeps the allowed strong and br tags', async ({ page }) => {
        await openAndUsError(page, '<strong>bold</strong><br>line');

        await expect(page.locator(`${TOAST_BODY} strong`)).toHaveText('bold');
        await expect(page.locator(`${TOAST_BODY} br`)).toHaveCount(1);
    });

    test('usError() shows a plain ampersand entity as an ampersand', async ({ page }) => {
        await openAndUsError(page, 'Tom &amp; Jerry');

        await expect(page.locator(TOAST_BODY).first()).toHaveText('Tom & Jerry');
    });
});

// ---------------------------------------------------------------------------
// Section 2: end to end through the admin contact form. Needs the local admin
// account and the Docker stack (the fixture runs in the app container, and the
// email goes to the mock-brevo service).
// ---------------------------------------------------------------------------

const FIXTURE_REL = 'tests/playwright/local/fixtures/seed-toast-xss-owner.php';
const hasAdminCreds = !!(process.env.E2E_DEV_ADMIN_USERNAME && process.env.E2E_DEV_ADMIN_PASSWORD);

test.describe('Admin contact success toast shows an owner name as text (#2439)', () => {
    test.skip(!hasAdminCreds, 'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run the admin contact test');

    // Default mode keeps both tests in one worker, so one seed and one cleanup,
    // and a failure in the first test does not skip the second.
    test.describe.configure({ mode: 'default' });

    let seeded;

    test.beforeAll(() => {
        seeded = JSON.parse(runPhpFixture(FIXTURE_REL).trim().split('\n').pop());
        for (const owner of [seeded.a, seeded.b]) {
            expect(owner.userId).toBeGreaterThan(0);
            expect(owner.carId).toBeGreaterThan(0);
        }
    });

    test.afterAll(() => {
        try {
            runPhpFixture(FIXTURE_REL, ['--cleanup']);
        } catch (error) {
            // The next seed run deletes the marker rows first, so a failed cleanup is not fatal.
            console.warn('toast-xss cleanup failed:', error.message);
        }
    });

    /** Send an admin contact message to one seeded owner and return after the redirect. */
    async function sendAdminContact(page, owner) {
        await login(page);
        await page.goto('app/admin/index.php?tab=manage-cars', { waitUntil: 'domcontentloaded' });

        // The page renders two forms with this id: the manage-cars tab and the
        // index.php modal. Both post to the same handler with the same token, so
        // either works. .last() only makes the locator strict.
        const form = page.locator('#adminContactForm').last();
        await expect(form).toBeAttached();
        // The modal fields are hidden until a row button opens it. Set the values
        // directly and submit the form, as the row button handler would.
        await form.evaluate((f, data) => {
            f.querySelector('[name="owner_id"]').value = String(data.userId);
            f.querySelector('[name="car_id"]').value = String(data.carId);
            f.querySelector('[name="message"]').value = 'Playwright toast check for #2439';
            f.submit();
        }, owner);

        await page.waitForURL(url => url.pathname.endsWith('/app/admin/index.php'), { waitUntil: 'domcontentloaded' });
    }

    test('owner with single-encoded last name: toast shows the name as text', async ({ page }) => {
        await sendAdminContact(page, seeded.a);

        const toast = page.locator(SUCCESS_TOAST_BODY);
        await expect(toast).toBeVisible();
        await expectNoInjectedImg(page, 'xss2439d');
        await expect(toast).toContainText('Administrator message sent successfully to Toast');
        // process-admin-contact.php encodes the name once with htmlspecialchars()
        // and sanitizeHTML() decodes it once. The toast's textContent then decodes
        // the stored entities, so the user sees the literal tag text.
        await expect(toast).toContainText('Toast <img id=xss2439d src=x>');
    });

    test('owner with double-encoded last name: toast shows the name as text', async ({ page }) => {
        await sendAdminContact(page, seeded.b);

        const toast = page.locator(SUCCESS_TOAST_BODY);
        await expect(toast).toBeVisible();
        await expectNoInjectedImg(page, 'xss2439e');
        await expect(toast).toContainText('Administrator message sent successfully to Toast');
        await expect(toast).toContainText('Toast &lt;img id=xss2439e src=x&gt;');
    });
});
