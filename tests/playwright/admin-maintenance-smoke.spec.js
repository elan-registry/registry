// tests/playwright/admin-maintenance-smoke.spec.js
//
// Page/content-level smoke coverage for app/admin/maintenance.php (#1660).
// Rewritten in #1225 from a two-tab layout into a single page with no tabs,
// backed by 3 card partials (maintenance-backups.php, maintenance-migrations.php,
// maintenance-scripts.php). This spec confirms the page renders successfully and
// the BackupManager/script-enumeration wiring in maintenance.php didn't throw
// before reaching markup — it does not duplicate the <title> check already in
// admin-page-titles.spec.js or the modal DOM/CSRF checks already in
// admin-modal-confirmation.spec.js.
//
// Requires local MAMP. Default: http://localhost:9999/ElanRegistry/Registry/ — override with PLAYWRIGHT_BASE_URL, see docs/development/ENVIRONMENT.md

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

test.describe('Admin Maintenance Page Smoke', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto('app/admin/maintenance.php', { waitUntil: 'networkidle' });
    });

    test('page loads successfully with no fatal error', async ({ page }) => {
        const response = await page.goto('app/admin/maintenance.php', { waitUntil: 'networkidle' });

        expect(response.status()).toBe(200);

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toContain('Fatal error');
    });

    test('renders page heading and CSRF token', async ({ page }) => {
        await expect(page.getByRole('heading', { name: 'Registry Maintenance', level: 1 })).toBeVisible();
        await expect(page.locator('input[name="csrf"]')).toBeAttached();
    });

    test('renders all three card headings', async ({ page }) => {
        await expect(page.getByRole('heading', { name: 'Backups', level: 2 })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'One-time Migrations', level: 2 })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Maintenance Tasks', level: 2 })).toBeVisible();
    });

    test('backups and migrations card anchors are attached', async ({ page }) => {
        // Confirms the BackupManager construction and script-enumeration calls
        // in maintenance.php completed without throwing before reaching markup.
        await expect(page.locator('#backups-card')).toBeAttached();
        await expect(page.locator('#migrations-card')).toBeAttached();
    });
});
