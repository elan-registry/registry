// tests/playwright/account-email-paused-notice.spec.js
//
// Coverage for the email-paused notice on usersc/account.php (#1899): a
// banner naming suppressed/bounced delivery addresses, with links to the two
// Account Settings controls that fix it.
//
// Seeds six loginable owners through `seed-email-paused-notice.php`: an
// owner-opt-out suppression (real cars_hist row, no er_email_events row), a
// Brevo spam suppression (er_email_events row, no matching cars_hist row), a
// bounced owner, a both-flags-on-one-address owner, a >3-address overflow
// owner, and a clean owner. The fixture needs the application database. The
// local stack is Docker only, so the spec runs the fixture in the app
// container through fixture-runner.js.
//
// Requires the local Docker site. Default: http://localhost:$APP_HOST_PORT/ — see
// tests/playwright/base-url.js. Override with PLAYWRIGHT_BASE_URL, see
// docs/development/ENVIRONMENT.md

const { test, expect } = require('@playwright/test');
const { login, isLoggedIn } = require('./auth-helper.js');
const { runPhpFixture } = require('./fixture-runner.js');

const FIXTURE_REL = 'tests/playwright/local/fixtures/seed-email-paused-notice.php';
const ACCOUNT_URL = 'usersc/account.php';

// Many logins in quick succession can trip RATE_LIMIT_LOGIN when Playwright
// runs this file's tests across parallel workers. Serial mode keeps one
// worker for this file, matching user-settings-resume-emails.spec.js's
// precedent for the same login-heavy shape.
test.describe.configure({ mode: 'serial' });

// Log in as the seeded owners only, never with a shared admin session.
test.use({ storageState: { cookies: [], origins: [] } });

let seeded;

test.beforeAll(() => {
    seeded = JSON.parse(runPhpFixture(FIXTURE_REL).trim().split('\n').pop());
    for (const key of ['optout', 'spam', 'bounced', 'both', 'overflow', 'clean']) {
        expect(seeded[key].userId, `seeded.${key}.userId`).toBeGreaterThan(0);
    }
});

test.afterAll(() => {
    try {
        runPhpFixture(FIXTURE_REL, ['--cleanup']);
    } catch (error) {
        // The next seed run deletes the marker rows first, so a failed cleanup is not fatal.
        console.warn('account-email-paused-notice cleanup failed:', error.message);
    }
});

test.describe('Email-paused notice on account.php (#1899)', () => {
    test('clean owner sees no notice at all', async ({ page }) => {
        await login(page, seeded.clean.username, seeded.clean.password);
        expect(await isLoggedIn(page)).toBe(true);

        await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });

        // Non-vacuous: the account page itself rendered for this owner.
        await expect(page.locator('h1:has-text("My Account")')).toBeVisible();
        await expect(page.locator('#email-paused-notice')).toHaveCount(0);
    });

    test('owner-opt-out suppression shows the suppressed line with the opt-out wording', async ({ page }) => {
        await login(page, seeded.optout.username, seeded.optout.password);
        await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });

        const notice = page.locator('#email-paused-notice');
        await expect(notice).toBeVisible();
        await expect(notice.locator('.er-email-notice__line--suppressed')).toBeVisible();
        await expect(notice).toContainText('You asked us to stop sending them to');
        await expect(notice.locator('.er-email-notice__line--bounced')).toHaveCount(0);
    });

    test('Brevo spam suppression shows the suppressed line with the complaint wording', async ({ page }) => {
        await login(page, seeded.spam.username, seeded.spam.password);
        await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });

        const notice = page.locator('#email-paused-notice');
        await expect(notice).toBeVisible();
        await expect(notice.locator('.er-email-notice__line--suppressed')).toBeVisible();
        await expect(notice).toContainText('flagged');
        await expect(notice.locator('.er-email-notice__line--bounced')).toHaveCount(0);
    });

    test('bounced owner shows only the bounced line', async ({ page }) => {
        await login(page, seeded.bounced.username, seeded.bounced.password);
        await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });

        const notice = page.locator('#email-paused-notice');
        await expect(notice).toBeVisible();
        await expect(notice.locator('.er-email-notice__line--bounced')).toBeVisible();
        await expect(notice).toContainText("We can't reach you by email");
        await expect(notice.locator('.er-email-notice__line--suppressed')).toHaveCount(0);
    });

    test('both-flags owner on one address shows both lines', async ({ page }) => {
        await login(page, seeded.both.username, seeded.both.password);
        await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });

        const notice = page.locator('#email-paused-notice');
        await expect(notice).toBeVisible();
        await expect(notice.locator('.er-email-notice__line--suppressed')).toBeVisible();
        await expect(notice.locator('.er-email-notice__line--bounced')).toBeVisible();
    });

    test('overflow owner (4 addresses) shows the 3-address cap and "and 1 more"', async ({ page }) => {
        await login(page, seeded.overflow.username, seeded.overflow.password);
        await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });

        const notice = page.locator('#email-paused-notice');
        await expect(notice).toBeVisible();
        await expect(notice).toContainText('This notice shows 3 addresses, and 1 more is also affected.');
        await expect(notice.getByRole('link', { name: 'your cars' })).toHaveAttribute(
            'href',
            /\/app\/owner\/cars\/index\.php$/
        );
    });

    test('banner is role="status", has no heading inside, and is the first element before the profile card', async ({ page }) => {
        await login(page, seeded.both.username, seeded.both.password);
        await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });

        const notice = page.locator('#email-paused-notice');
        await expect(notice).toHaveAttribute('role', 'status');

        // No heading element (h1-h6) inside the notice — account.php's own
        // <h1> must stay the page's first heading.
        await expect(notice.locator('h1, h2, h3, h4, h5, h6')).toHaveCount(0);

        // The notice must be the first child of .well, before the Profile card.
        const firstChildId = await page.locator('.well > *').first().getAttribute('id');
        expect(firstChildId).toBe('email-paused-notice');

        const profileCard = page.locator('.card.registry-card').first();
        const noticeBox = await notice.boundingBox();
        const cardBox = await profileCard.boundingBox();
        expect(noticeBox).not.toBeNull();
        expect(cardBox).not.toBeNull();
        expect(noticeBox.y).toBeLessThan(cardBox.y);
    });

    test('dismiss button has the correct aria-label and a >=44px hit area', async ({ page }) => {
        await login(page, seeded.both.username, seeded.both.password);
        await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });

        const dismissButton = page.locator('#email-paused-notice .er-email-notice__dismiss');
        await expect(dismissButton).toHaveAttribute('aria-label', 'Dismiss this notice until your next visit');

        const box = await dismissButton.boundingBox();
        expect(box).not.toBeNull();
        expect(box.width).toBeGreaterThanOrEqual(44);
        expect(box.height).toBeGreaterThanOrEqual(44);
    });

    test('CTA hrefs point at the resume-emails and account-email anchors', async ({ page }) => {
        await login(page, seeded.both.username, seeded.both.password);
        await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });

        const notice = page.locator('#email-paused-notice');
        await expect(notice.getByRole('link', { name: 'Turn emails back on' }))
            .toHaveAttribute('href', /usersc\/user_settings\.php#resume-emails$/);
        await expect(notice.getByRole('link', { name: 'Update my email address' }))
            .toHaveAttribute('href', /usersc\/user_settings\.php#account-email$/);
    });

    test('collapse is closed by default and toggles open on click', async ({ page }) => {
        await login(page, seeded.optout.username, seeded.optout.password);
        await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });

        const toggle = page.locator('#email-paused-notice .er-email-notice__why').first();
        const collapse = page.locator('#email-notice-why-suppressed');

        await expect(collapse).not.toHaveClass(/show/);
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');

        await toggle.click();

        await expect(collapse).toHaveClass(/show/);
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    });

    test('dismiss hides the notice for the session: same-tab reload stays hidden, a fresh context shows it again', async ({ page, browser }) => {
        await login(page, seeded.optout.username, seeded.optout.password);
        await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });

        const notice = page.locator('#email-paused-notice');
        await expect(notice).toBeVisible();

        await notice.locator('.er-email-notice__dismiss').click();
        await expect(notice).toHaveCount(0);

        // Same-tab reload: sessionStorage persists, so the notice must stay hidden.
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(page.locator('#email-paused-notice')).toHaveCount(0);

        // A fresh browser context has its own sessionStorage — the notice must
        // show again for the same owner.
        const freshContext = await browser.newContext();
        const freshPage = await freshContext.newPage();
        try {
            await login(freshPage, seeded.optout.username, seeded.optout.password);
            await freshPage.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });
            await expect(freshPage.locator('#email-paused-notice')).toBeVisible();
        } finally {
            await freshContext.close();
        }
    });

    test('dismiss moves focus to the page <h1>', async ({ page }) => {
        await login(page, seeded.optout.username, seeded.optout.password);
        await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });

        const notice = page.locator('#email-paused-notice');
        await expect(notice).toBeVisible();

        await notice.locator('.er-email-notice__dismiss').click();

        const heading = page.locator('#page-wrapper h1').first();
        await expect(heading).toHaveAttribute('tabindex', '-1');
        await expect(heading).toBeFocused();
    });
});
