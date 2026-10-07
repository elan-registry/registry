// Coverage for the "Resume verification emails" control (#resume-emails) on
// usersc/user_settings.php (issue #1895).
//
// Seeds two loginable owners through `seed-resume-emails.php`: one with the
// profile and car email_suppressed flags set, and one with both flags clear.
// The fixture needs the application database. The local stack is Docker only,
// so the spec runs the fixture in the app container through fixture-runner.js.

const { test, expect } = require('@playwright/test');
const { login, isLoggedIn } = require('./auth-helper.js');
const { runPhpFixture } = require('./fixture-runner.js');

const FIXTURE_REL = 'tests/playwright/local/fixtures/seed-resume-emails.php';
const SETTINGS_URL = 'usersc/user_settings.php';

// The resume test changes the seeded rows. Serial mode keeps one worker for this file.
test.describe.configure({ mode: 'serial' });

// Log in as the seeded owners only, never with a shared admin session.
test.use({ storageState: { cookies: [], origins: [] } });

let seeded;

test.beforeAll(() => {
    seeded = JSON.parse(runPhpFixture(FIXTURE_REL).trim().split('\n').pop());
    expect(seeded.suppressed.userId).toBeGreaterThan(0);
    expect(seeded.unsuppressed.userId).toBeGreaterThan(0);
});

test.afterAll(() => {
    try {
        runPhpFixture(FIXTURE_REL, ['--cleanup']);
    } catch (error) {
        // The next seed run deletes the marker rows first, so a failed cleanup is not fatal.
        console.warn('user-settings-resume-emails cleanup failed:', error.message);
    }
});

test.describe('Resume verification emails control (#1895)', () => {
    test('suppressed owner resumes emails and the control is gone after the redirect', async ({ page }) => {
        await login(page, seeded.suppressed.username, seeded.suppressed.password);
        expect(await isLoggedIn(page)).toBe(true);

        await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });
        const control = page.locator('#resume-emails');
        await expect(control).toBeVisible();
        await expect(control).toContainText('Verification emails are currently paused for 1 of your cars.');

        const resumeButton = control.getByRole('button', { name: 'Resume verification emails' });
        await Promise.all([
            page.waitForURL(url => url.pathname.endsWith('/usersc/user_settings.php'), { waitUntil: 'domcontentloaded' }),
            resumeButton.click(),
        ]);

        // The usSuccess() flash renders as a success toast. It closes after 6
        // seconds, so check it before anything else.
        await expect(page.locator('.us-toast:has(.us-bar-success) .toast-body'))
            .toContainText('Verification emails have been resumed for your cars.');

        // Non-vacuous: the settings page rendered, without the control.
        await expect(page.locator('h1:has-text("Update your user settings")')).toBeVisible();
        await expect(page.locator('#resume-emails')).toHaveCount(0);
    });

    test('unsuppressed owner has no #resume-emails element', async ({ page }) => {
        await login(page, seeded.unsuppressed.username, seeded.unsuppressed.password);
        expect(await isLoggedIn(page)).toBe(true);

        await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });

        // Non-vacuous: the settings form rendered for this owner.
        await expect(page.locator('form[name="updateAccount"]')).toBeVisible();
        await expect(page.locator('#resume-emails')).toHaveCount(0);
    });
});
