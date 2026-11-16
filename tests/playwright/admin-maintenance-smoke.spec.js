// Page/content-level smoke coverage for app/admin/maintenance.php (#1660).
// Rewritten in #1225 from a two-tab layout into a single page with no tabs,
// backed by 3 card partials (maintenance-backups.php, maintenance-migrations.php,
// maintenance-scripts.php). This spec confirms the page renders successfully and
// the BackupManager/script-enumeration wiring in maintenance.php didn't throw
// before reaching markup — it does not duplicate the <title> check already in
// admin-page-titles.spec.js. Modal behavior (open/cancel, XSS in
// #confirmMessage) checks are in admin-modal-confirmation.spec.js. That file also
// checks app/admin/index.php: CSRF rejection of car deletion, and JS errors on load.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

test.describe('Admin Maintenance Page Smoke', () => {
    let response;

    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        response = await page.goto('app/admin/maintenance.php', { waitUntil: 'networkidle' });
    });

    test('page loads successfully with no fatal error, heading, and CSRF token', async ({ page }) => {
        expect(response.status(), 'page returns 200').toBe(200);

        const bodyText = await page.locator('body').textContent();
        expect(bodyText, 'page has no fatal error').not.toContain('Fatal error');

        await expect.soft(page.getByRole('heading', { name: 'Registry Maintenance', level: 1 }), 'Registry Maintenance heading').toBeVisible();
        await expect.soft(page.locator('input[name="csrf"]'), 'CSRF token input').toBeAttached();
    });

    test('renders all three card headings and the card anchors', async ({ page }) => {
        await expect.soft(page.getByRole('heading', { name: 'Backups', level: 2 }), 'Backups heading').toBeVisible();
        await expect.soft(page.getByRole('heading', { name: 'One-time Migrations', level: 2 }), 'One-time Migrations heading').toBeVisible();
        await expect.soft(page.getByRole('heading', { name: 'Maintenance Tasks', level: 2 }), 'Maintenance Tasks heading').toBeVisible();

        // Confirms the BackupManager construction and script-enumeration calls
        // in maintenance.php completed without throwing before reaching markup.
        await expect.soft(page.locator('#backups-card'), 'backups card anchor').toBeAttached();
        await expect.soft(page.locator('#migrations-card'), 'migrations card anchor').toBeAttached();
    });
});
