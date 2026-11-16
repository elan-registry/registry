// Behavioral tests for the Bootstrap 5 modals used on admin pages.
// Covers: CSRF rejection of car deletion, JS errors on page load, Cancel
// behavior of the confirmation and input modals, the input modal opening with
// its default value, and that #confirmMessage holds no <script> or <img> after
// the cleanup modal opens. The confirmation modal checks target the backup
// cleanup confirmation modal (button[data-action="performBackupCleanup"]) in
// Area 2. performBackupCleanup() writes a fixed innerHTML string, so these
// tests do not cover the textContent path in deleteBackup().

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

const CLEANUP_BUTTON = 'button[data-action="performBackupCleanup"]';
const BACKUP_BUTTON = 'button[data-action="createManualBackup"]';

// ---------------------------------------------------------------------------
// Area 1: index.php — car deletion CSRF rejection and JS errors
// ---------------------------------------------------------------------------

test.describe('Admin confirmation modal — index', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    test('car deletion is rejected with an invalid CSRF token (#1829)', async ({ page }) => {
        // Car::delete() dropped its internal Token::check() in #1829 — CSRF is
        // now enforced only by the Token::check() gate in app/admin/index.php,
        // before the command switch (including case "delete") is ever reached.
        // This proves that gate actually rejects the delete command end-to-end,
        // rather than relying on code inspection alone. Uses a car_id that does
        // not exist, so even a rejection failure can't delete a real car — the
        // CSRF gate runs before the car-lookup/exists() check either way.
        const response = await page.request.post('app/admin/index.php', {
            form: {
                command: 'delete',
                car_id: '999999999',
                confirmation: 'DELETE',
                reason: 'Playwright CSRF rejection test',
                csrf: 'invalid_token'
            }
        });

        // token_error.php dies with a plain HTML page (no distinct HTTP status
        // or JSON) — assert on its rendered content instead of status code.
        const body = await response.text();
        expect(body).toContain('There was an error with your form');
        expect(body).not.toContain('permanently deleted');
    });

    test('no JS errors on page load', async ({ page }) => {
        const errors = [];
        page.on('console', msg => {
            if (msg.type() === 'error') {
                errors.push(msg.text());
            }
        });
        await page.goto('app/admin/index.php?tab=car-mgmt', { waitUntil: 'networkidle' });
        const jsErrors = errors.filter(e => !e.includes('favicon') && !e.includes('404'));
        expect(jsErrors).toHaveLength(0);
    });
});

// ---------------------------------------------------------------------------
// Area 2: maintenance.php — backup cleanup confirmation modal
// ---------------------------------------------------------------------------

test.describe('Admin confirmation modal — maintenance', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto('app/admin/maintenance.php', { waitUntil: 'networkidle' });
    });

    test('Cancel dismisses the backup cleanup confirmation modal', async ({ page }) => {
        const cleanupBtn = page.locator(CLEANUP_BUTTON).first();
        test.skip(await cleanupBtn.count() === 0, 'Cleanup Old Backups button not found — no old backups to clean up');

        await cleanupBtn.click();
        await expect(page.locator('#confirmationModal')).toBeVisible({ timeout: 3000 });

        await page.locator('#confirmationModal .btn-secondary').click();
        await expect(page.locator('#confirmationModal')).not.toBeVisible({ timeout: 3000 });
    });

    test('#confirmMessage contains no executable HTML (XSS prevention)', async ({ page }) => {
        const cleanupBtn = page.locator(CLEANUP_BUTTON).first();
        test.skip(await cleanupBtn.count() === 0, 'Cleanup Old Backups button not found — no old backups to clean up');

        await cleanupBtn.click();
        await expect(page.locator('#confirmationModal')).toBeVisible({ timeout: 3000 });

        const msgHtml = await page.locator('#confirmMessage').innerHTML();
        expect(msgHtml).not.toMatch(/<script/i);
        expect(msgHtml).not.toMatch(/<img/i);

        // Dismiss so the modal doesn't interfere with other tests
        await page.locator('#confirmationModal .btn-secondary').click();
    });
});

// ---------------------------------------------------------------------------
// Area 3: maintenance.php — input modal (#inputModal)
// ---------------------------------------------------------------------------

test.describe('Admin input modal — maintenance', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto('app/admin/maintenance.php', { waitUntil: 'networkidle' });
    });

    test('Create Manual Backup button opens input modal', async ({ page }) => {
        const backupBtn = page.locator(BACKUP_BUTTON);
        await backupBtn.click();
        await expect(page.locator('#inputModal')).toBeVisible({ timeout: 3000 });
        await expect(page.locator('#inputModalTitle')).toContainText('Create Manual Backup');
        await expect(page.locator('#inputModalValue')).toHaveValue('Admin Panel Manual Backup');
    });

    test('Cancel dismisses the input modal without triggering backup', async ({ page }) => {
        const backupBtn = page.locator(BACKUP_BUTTON);
        await backupBtn.click();
        await expect(page.locator('#inputModal')).toBeVisible({ timeout: 3000 });

        await page.locator('#inputModal .btn-secondary').click();
        await expect(page.locator('#inputModal')).not.toBeVisible({ timeout: 3000 });
    });
});
