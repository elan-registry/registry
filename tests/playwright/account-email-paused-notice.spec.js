// Coverage for the owner-level email notice on usersc/account.php (#1899,
// #2356): one banner for the owner's email state (blocked, bounced or opted
// out), with links to the Account Settings controls that fix it.
//
// Seeds eight loginable owners through `seed-email-paused-notice.php`. The
// deliverability flags are on the owner profile (#2101), and each flagged
// address is the user's own email, so the flag applies (#2356). The fixture
// needs the application database. The local stack is Docker only, so the spec
// runs the fixture in the app container through fixture-runner.js.

const { test, expect } = require('@playwright/test');
const { login } = require('./auth-helper.js');
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

const OWNER_KEYS = ['optout', 'spam', 'unsub', 'blocklist', 'nocause', 'bounced', 'blockedopt', 'clean'];

test.beforeAll(() => {
    seeded = JSON.parse(runPhpFixture(FIXTURE_REL).trim().split('\n').pop());
    for (const key of OWNER_KEYS) {
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

async function openAccount(page, key) {
    await login(page, seeded[key].username, seeded[key].password);
    await page.goto(ACCOUNT_URL, { waitUntil: 'domcontentloaded' });
    return page.locator('#email-paused-notice');
}

async function expectNoHorizontalOverflow(page, notice) {
    const overflow = await notice.evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    const pageOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth
    );
    expect(pageOverflow).toBeLessThanOrEqual(0);
}

test.describe('Email notice on account.php (#1899, #2356)', () => {
    test('clean owner sees no notice at all', async ({ page }) => {
        const notice = await openAccount(page, 'clean');

        // Non-vacuous: the account page itself rendered for this owner.
        await expect(page.locator('h1:has-text("My Account")')).toBeVisible();
        await expect(notice).toHaveCount(0);
    });

    for (const [key, cause] of [
        ['spam', 'a message to this address was reported as spam'],
        ['unsub', 'this address unsubscribed from registry email'],
        ['blocklist', 'an administrator blocked this address'],
    ]) {
        test(`blocked owner (${key}) sees the address, the cause, and both links`, async ({ page }) => {
            const notice = await openAccount(page, key);

            await expect(notice).toBeVisible();
            await expect(notice).toContainText(`Email to ${seeded[key].email} is blocked because ${cause}.`);
            await expect(notice.getByRole('link', { name: 'Change email' }))
                .toHaveAttribute('href', /usersc\/user_settings\.php#account-email$/);
            await expect(notice.getByRole('link', { name: 'Contact an admin' }))
                .toHaveAttribute('href', /app\/owner\/contact\/index\.php$/);
            await expect(notice.getByRole('link', { name: 'Resume' })).toHaveCount(0);
            await expect(notice).not.toContainText('Brevo');
            await expectNoHorizontalOverflow(page, notice);
        });
    }

    test('blocked owner with no known cause states no cause', async ({ page }) => {
        const notice = await openAccount(page, 'nocause');

        await expect(notice).toBeVisible();
        await expect(notice).toContainText(`Email to ${seeded.nocause.email} is blocked.`);
        await expect(notice).not.toContainText('because');
        await expect(notice).not.toContainText('Brevo');
    });

    test('bounced owner sees the bounce date and the change links', async ({ page }) => {
        const notice = await openAccount(page, 'bounced');

        await expect(notice).toBeVisible();
        await expect(notice).toContainText(`Email to ${seeded.bounced.email} bounced on 3 April 2026.`);
        await expect(notice.getByRole('link', { name: 'Change email' })).toBeVisible();
        await expect(notice.getByRole('link', { name: 'Contact an admin' })).toBeVisible();
        await expect(notice.getByRole('link', { name: 'Resume' })).toHaveCount(0);
        await expect(notice).not.toContainText('Brevo');
    });

    test('opted-out owner sees the opt-out date and only the Resume link', async ({ page }) => {
        const notice = await openAccount(page, 'optout');

        await expect(notice).toBeVisible();
        await expect(notice).toContainText('You opted out of registry email on 1 April 2026.');
        await expect(notice.getByRole('link', { name: 'Resume' }))
            .toHaveAttribute('href', /usersc\/user_settings\.php#resume-emails$/);
        await expect(notice.getByRole('link', { name: 'Change email' })).toHaveCount(0);
        await expect(notice.getByRole('link', { name: 'Contact an admin' })).toHaveCount(0);
        await expect(notice).not.toContainText('Brevo');
    });

    test('blocked and opted-out owner sees one notice: the block first, then the opt-out line and Resume', async ({ page }) => {
        const notice = await openAccount(page, 'blockedopt');

        await expect(page.locator('#email-paused-notice')).toHaveCount(1);
        await expect(notice).toContainText(`Email to ${seeded.blockedopt.email} is blocked because a message to this address was reported as spam.`);
        await expect(notice).toContainText('You also opted out of registry email.');
        await expect(notice.getByRole('link', { name: 'Change email' })).toBeVisible();
        await expect(notice.getByRole('link', { name: 'Contact an admin' })).toBeVisible();
        await expect(notice.getByRole('link', { name: 'Resume' }))
            .toHaveAttribute('href', /usersc\/user_settings\.php#resume-emails$/);

        const text = await notice.innerText();
        expect(text.indexOf('is blocked')).toBeGreaterThanOrEqual(0);
        expect(text.indexOf('is blocked')).toBeLessThan(text.indexOf('You also opted out'));
        await expectNoHorizontalOverflow(page, notice);
    });

    test('notice does not overflow at a 320px viewport', async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 700 });
        const notice = await openAccount(page, 'blockedopt');

        await expect(notice).toBeVisible();
        await expectNoHorizontalOverflow(page, notice);
    });

    test('banner is role="status", has no heading inside, and is the first element before the profile card', async ({ page }) => {
        const notice = await openAccount(page, 'blockedopt');
        await expect(notice).toHaveAttribute('role', 'status');

        // No heading element (h1-h6) inside the notice: account.php's own
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
        await openAccount(page, 'blockedopt');

        const dismissButton = page.locator('#email-paused-notice .er-email-notice__dismiss');
        await expect(dismissButton).toHaveAttribute('aria-label', 'Dismiss this notice until your next visit');

        const box = await dismissButton.boundingBox();
        expect(box).not.toBeNull();
        expect(box.width).toBeGreaterThanOrEqual(44);
        expect(box.height).toBeGreaterThanOrEqual(44);
    });

    test('dismiss hides the notice for the session: same-tab reload stays hidden, a fresh context shows it again', async ({ page, browser }) => {
        const notice = await openAccount(page, 'optout');
        await expect(notice).toBeVisible();

        await notice.locator('.er-email-notice__dismiss').click();
        await expect(notice).toHaveCount(0);

        // Same-tab reload: sessionStorage persists, so the notice must stay hidden.
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(page.locator('#email-paused-notice')).toHaveCount(0);

        // A fresh browser context has its own sessionStorage: the notice must
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
        const notice = await openAccount(page, 'optout');
        await expect(notice).toBeVisible();

        await notice.locator('.er-email-notice__dismiss').click();

        const heading = page.locator('#page-wrapper h1').first();
        await expect(heading).toHaveAttribute('tabindex', '-1');
        await expect(heading).toBeFocused();
    });

    // The contentHash in the dismissal key makes a dismissed notice show
    // again when the owner's flag state changes.
    test('a dismissed notice shows again after the owner flag state changes', async ({ page }) => {
        const notice = await openAccount(page, 'bounced');
        await expect(notice).toContainText('bounced on 3 April 2026');

        await notice.locator('.er-email-notice__dismiss').click();
        await expect(notice).toHaveCount(0);

        // Non-vacuous: with the state unchanged, the dismissal holds on reload.
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(page.locator('#email-paused-notice')).toHaveCount(0);

        // The state changes from a bounce to a block. The dismissal key holds
        // the old hash, so the new state must show.
        runPhpFixture(FIXTURE_REL, ['--set=bounced:blocked_spam']);
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(page.locator('#email-paused-notice')).toBeVisible();
        await expect(page.locator('#email-paused-notice')).toContainText('is blocked because a message to this address was reported as spam');
    });
});
