// tests/playwright/admin-modal-confirmation.test.js
//
// Behavioral tests for the Bootstrap 5 #confirmationModal used on admin pages.
// Covers: modal DOM presence, Cancel/Confirm behavior, XSS prevention via
// textContent, and CSRF token availability for modal-triggered operations.
// Cancel/Confirm and XSS checks target the backup cleanup confirmation modal
// (button[onclick*="performBackupCleanup"]) in Area 2.
//
// Requires local MAMP. Default: http://localhost:9999/ElanRegistry/Registry/ — override with PLAYWRIGHT_BASE_URL, see docs/development/ENVIRONMENT.md

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

// ---------------------------------------------------------------------------
// Area 1: index.php — modal DOM and car merge tab
// ---------------------------------------------------------------------------

test.describe('Admin confirmation modal — index', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto('app/admin/index.php?tab=car-mgmt', { waitUntil: 'networkidle' });
    });

    test('confirmation modal element is present in DOM', async ({ page }) => {
        await expect(page.locator('#confirmationModal')).toBeAttached();
        await expect(page.locator('#confirmTitle')).toBeAttached();
        await expect(page.locator('#confirmMessage')).toBeAttached();
        await expect(page.locator('#confirmButton')).toBeAttached();
    });

    test('CSRF token is present for modal-triggered forms', async ({ page }) => {
        // index.php renders multiple forms (assignCar, deleteCar, contact-owner, etc.),
        // each with its own csrf input sharing the same per-session token value —
        // scope to the first to avoid a strict-mode violation matching all of them.
        const csrfInput = page.locator('input[name="csrf"]').first();
        await expect(csrfInput).toBeAttached();
        const value = await csrfInput.getAttribute('value');
        expect(value).toBeTruthy();
        expect(value.length).toBeGreaterThan(10);
    });

    test('modal is hidden on page load', async ({ page }) => {
        await expect(page.locator('#confirmationModal')).not.toBeVisible();
    });

    test('car deletion is rejected with an invalid CSRF token (#1829)', async ({ page }) => {
        // Car::delete() dropped its internal Token::check() in #1829 — CSRF is
        // now enforced only by the gate at app/admin/index.php:176-178, before
        // the command switch (including case "delete") is ever reached. This
        // proves that gate actually rejects the delete command end-to-end,
        // rather than relying on code inspection alone. Uses CAR_ID_NONEXISTENT
        // so even a rejection failure can't delete a real car — the CSRF gate
        // runs before the car-lookup/exists() check either way.
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
        await page.reload({ waitUntil: 'networkidle' });
        const jsErrors = errors.filter(e => !e.includes('favicon') && !e.includes('404'));
        expect(jsErrors).toHaveLength(0);
    });
});

// ---------------------------------------------------------------------------
// Area 2: maintenance.php — modal DOM
// ---------------------------------------------------------------------------

test.describe('Admin confirmation modal — maintenance', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto('app/admin/maintenance.php', { waitUntil: 'networkidle' });
    });

    test('confirmation modal element is present in DOM', async ({ page }) => {
        await expect(page.locator('#confirmationModal')).toBeAttached();
    });

    test('CSRF token is present for maintenance operations', async ({ page }) => {
        const csrfInput = page.locator('input[name="csrf"]');
        await expect(csrfInput).toBeAttached();
        const value = await csrfInput.getAttribute('value');
        expect(value).toBeTruthy();
    });

    test('Cancel dismisses the backup cleanup confirmation modal', async ({ page }) => {
        const cleanupBtn = page.locator('button[onclick*="performBackupCleanup"]').first();
        test.skip(await cleanupBtn.count() === 0, 'Cleanup Old Backups button not found — no old backups to clean up');

        await cleanupBtn.click();
        await expect(page.locator('#confirmationModal')).toBeVisible({ timeout: 3000 });

        await page.locator('#confirmationModal .btn-secondary').click();
        await expect(page.locator('#confirmationModal')).not.toBeVisible({ timeout: 3000 });
    });

    test('#confirmMessage contains no executable HTML (XSS prevention)', async ({ page }) => {
        const cleanupBtn = page.locator('button[onclick*="performBackupCleanup"]').first();
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
// Area 3: maintenance.php — input modal DOM (#inputModal)
// ---------------------------------------------------------------------------

test.describe('Admin input modal — maintenance', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
        await page.goto('app/admin/maintenance.php', { waitUntil: 'networkidle' });
    });

    test('input modal and all required child elements are present in DOM', async ({ page }) => {
        await expect(page.locator('#inputModal')).toBeAttached();
        await expect(page.locator('#inputModalTitle')).toBeAttached();
        await expect(page.locator('#inputModalMessage')).toBeAttached();
        await expect(page.locator('#inputModalValue')).toBeAttached();
        await expect(page.locator('#inputModalConfirm')).toBeAttached();
    });

    test('input modal is hidden on page load', async ({ page }) => {
        await expect(page.locator('#inputModal')).not.toBeVisible();
    });

    test('Create Manual Backup button opens input modal', async ({ page }) => {
        const backupBtn = page.locator('button[onclick*="createManualBackup"]');
        test.skip(await backupBtn.count() === 0, 'Create Manual Backup button not found');

        await backupBtn.first().click();
        await expect(page.locator('#inputModal')).toBeVisible({ timeout: 3000 });
        await expect(page.locator('#inputModalTitle')).toContainText('Create Manual Backup');
        await expect(page.locator('#inputModalValue')).toHaveValue('Admin Panel Manual Backup');
    });

    test('Cancel dismisses the input modal without triggering backup', async ({ page }) => {
        const backupBtn = page.locator('button[onclick*="createManualBackup"]');
        test.skip(await backupBtn.count() === 0, 'Create Manual Backup button not found');

        await backupBtn.first().click();
        await expect(page.locator('#inputModal')).toBeVisible({ timeout: 3000 });

        await page.locator('#inputModal .btn-secondary').click();
        await expect(page.locator('#inputModal')).not.toBeVisible({ timeout: 3000 });
    });
});
